<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Domain\Graph;
use Wmos\Domain\Rules;
use Wmos\Infrastructure\Audit;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;
use Wmos\Infrastructure\Queue;

/** Versioned marketing definitions and bounded campaign execution. */
final class Definitions
{
    private $broadcastPlanner = null;
    private $providerCapabilities = null;
    public const KINDS = ['campaign', 'automation', 'segment', 'promotion', 'program', 'experiment', 'asset', 'offline', 'event', 'influencer', 'content', 'partner', 'personalization', 'recommendation'];

    public function __construct(private Database $database, private Audit $audit, private Queue $queue)
    {
        $queue->register('campaign_start', function (array $job, array $payload): void {
            if (!in_array('campaigns', get_option('wmos_settings', [])['enabled_modules'] ?? [], true)) {
                throw new \Wmos\Infrastructure\DeferredException('Campaign module is disabled.');
            }
            $row = $this->database->get('definitions', $payload['uuid']);
            if ($row && $row['state'] === 'scheduled' && (int) $row['cancel_epoch'] === (int) $payload['epoch']) {
                $this->transition($row['uuid'], 'running', (int) $row['row_version']);
            }
        });
        $queue->register('campaign_broadcast', [$this, 'broadcast']);
    }

    public function setBroadcastPlanner(callable $planner): void
    {
        $this->broadcastPlanner = $planner;
    }
    public function setProviderCapabilities(callable $reader): void
    {
        $this->providerCapabilities = $reader;
    }

    public function create(string $kind, string $name, array $body): array
    {
        if (!in_array($kind, self::KINDS, true) || trim($name) === '' || strlen($name) > 191) {
            throw new \InvalidArgumentException('Invalid definition kind or name.');
        }
        $this->validate($kind, $body, false);
        $row = $this->database->insert('definitions', ['kind' => $kind, 'name' => $name, 'state' => 'draft', 'draft' => Json::encode($body), 'cancel_epoch' => 0, 'generation' => 0]);
        $this->audit->record('definition.created', $row['uuid'], ['kind' => $kind]);
        return $this->get($row['uuid']);
    }

    public function save(string $uuid, array $body, int $expectedRevision): array
    {
        $row = $this->required($uuid);
        if (in_array($row['state'], ['archived', 'cancelled', 'completed'], true)) {
            throw new \DomainException('Terminal definitions must be duplicated to edit.');
        }
        $this->validate($row['kind'], $body, false);
        $this->database->update('definitions', $uuid, ['draft' => Json::encode($body)], $expectedRevision);
        return $this->get($uuid);
    }

    public function publish(string $uuid, int $expectedRevision): array
    {
        return $this->database->transaction(function () use ($uuid, $expectedRevision): array {
            $row = $this->lock($uuid);
            if ((int) $row['row_version'] !== $expectedRevision || in_array($row['state'], ['archived', 'cancelled', 'completed'], true)) {
                throw new \DomainException('Definition revision or state does not permit publication.');
            }
            $body = Json::decode($row['draft']);
            $this->validate($row['kind'], $body, true);
            if ($row['kind'] === 'automation') {
                $this->validateSubworkflows($body, $uuid, []);
            }
            $wpdb = $this->database->db();
            $table = $this->database->table('definition_versions');
            $number = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version),0)+1 FROM {$table} WHERE definition_id=%d", $row['id']));
            $encoded = Json::encode($body);
            $version = $this->database->insert('definition_versions', ['definition_id' => $row['id'], 'version' => $number, 'body' => $encoded, 'digest' => hash('sha256', $encoded)]);
            $state = $row['state'] === 'draft' ? match ($row['kind']) {
                'automation' => 'enabled', 'campaign' => 'draft', 'experiment' => 'draft', default => 'active'
            } : $row['state'];
            $this->database->update('definitions', $uuid, ['published_version_id' => $version['id'], 'state' => $state], $expectedRevision);
            $this->audit->record('definition.published', $uuid, ['version' => $number, 'digest' => $version['digest']]);
            return $this->get($uuid);
        });
    }

    public function transition(string $uuid, string $state, int $expectedRevision): array
    {
        return $this->database->transaction(function () use ($uuid, $state, $expectedRevision): array {
            $row = $this->lock($uuid);
            if (in_array($state, ['running', 'scheduled', 'enabled', 'active'], true)) {
                $module = match ($row['kind']) {
                    'campaign' => 'campaigns', 'automation' => 'automations', 'segment' => 'segments', 'experiment' => 'experiments', 'personalization' => 'personalization', 'recommendation' => 'recommendations', default => null
                };
                if ($module && !in_array($module, get_option('wmos_settings', [])['enabled_modules'] ?? [], true)) {
                    throw new \DomainException('Enable this marketing module before starting execution.');
                }
            }
            $map = $row['kind'] === 'campaign' ? [
                'draft' => ['scheduled', 'running', 'cancelled'], 'scheduled' => ['draft', 'running', 'cancelled'],
                'running' => ['paused', 'completed', 'cancelled'], 'paused' => ['running', 'completed', 'cancelled'],
                'completed' => ['archived'], 'cancelled' => ['archived'], 'archived' => [],
            ] : [
                'draft' => ['enabled', 'active', 'running', 'archived'], 'enabled' => ['paused', 'archived'],
                'active' => ['paused', 'archived'], 'running' => ['paused', 'completed', 'archived'],
                'paused' => ['enabled', 'active', 'running', 'archived'], 'completed' => ['archived'], 'archived' => [],
            ];
            if (!in_array($state, $map[$row['state']] ?? [], true)) {
                throw new \DomainException('Illegal definition state transition.');
            }
            if (in_array($state, ['scheduled', 'running', 'enabled', 'active'], true) && empty($row['published_version_id'])) {
                throw new \DomainException('Publish a version before activation.');
            }
            $epoch = (int) $row['cancel_epoch'] + (in_array($state, ['cancelled', 'archived'], true) ? 1 : 0);
            $this->database->update('definitions', $uuid, ['state' => $state, 'cancel_epoch' => $epoch], $expectedRevision);
            if ($row['kind'] === 'campaign' && $state === 'scheduled') {
                $body = $this->versionBody((int) $row['published_version_id']);
                try {
                    $due = (new \DateTimeImmutable($body['scheduled_at'] ?? '', new \DateTimeZone($body['timezone'] ?? 'UTC')))->getTimestamp();
                } catch (\Exception) {
                    throw new \DomainException('A valid future schedule instant is required.');
                }
                if ($due <= time()) {
                    throw new \DomainException('A future schedule instant is required.');
                }
                $this->queue->enqueue('campaign_start', ['uuid' => $uuid, 'epoch' => $epoch], 'campaign-start:' . $uuid . ':' . $row['published_version_id'] . ':' . $due, $due - time());
            }
            if ($row['kind'] === 'campaign' && $state === 'running') {
                $operationKey = 'broadcast:' . $uuid . ':' . $row['published_version_id'] . ':0';
                $prior = $this->database->find('jobs', 'operation_key', hash('sha256', 'campaign_broadcast:' . $operationKey));
                $payload = ['uuid' => $uuid, 'version_id' => (int) $row['published_version_id'], 'epoch' => $epoch, 'cursor' => 0];
                $body = $this->versionBody((int) $row['published_version_id']);
                if (!$prior && !empty($body['audience']['segment_uuid'])) {
                    $segment = $this->materializedSegment($body['audience']['segment_uuid']);
                    $payload['audience_generation'] = (int) $segment['generation'];
                    $payload['audience_version_id'] = (int) $segment['materialized_version_id'];
                }
                if (!$prior) {
                    $this->queue->enqueue('campaign_broadcast', $payload, $operationKey);
                }
            }
            $this->audit->record('definition.transitioned', $uuid, ['from' => $row['state'], 'to' => $state]);
            return $this->get($uuid);
        });
    }

    public function get(string $uuid): array
    {
        $row = $this->required($uuid);
        $row['draft'] = Json::decode($row['draft']);
        $row['published'] = empty($row['published_version_id']) ? null : $this->versionBody((int) $row['published_version_id']);
        return $row;
    }

    public function list(string $kind, int $limit = 25, int $after = 0): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown definition kind.');
        }
        return array_map(fn(array $row): array => $this->get($row['uuid']), $this->database->list('definitions', ['kind' => $kind], min(101, max(1, $limit)), $after));
    }

    public function versionBody(int $id): array
    {
        $row = $this->database->find('definition_versions', 'id', $id);
        if (!$row) {
            throw new \DomainException('Published version is unavailable.');
        }
        return Json::decode($row['body']);
    }

    public function broadcast(array $job, array $payload): void
    {
        if (!in_array('campaigns', get_option('wmos_settings', [])['enabled_modules'] ?? [], true)) {
            throw new \Wmos\Infrastructure\DeferredException('Campaign module is disabled.');
        }
        $row = $this->required($payload['uuid']);
        if ($row['state'] === 'paused') {
            throw new \Wmos\Infrastructure\DeferredException('Campaign is paused.');
        }
        if ($row['state'] !== 'running' || (int) $row['cancel_epoch'] !== (int) $payload['epoch']) {
            return;
        }
        $body = $this->versionBody((int) $payload['version_id']);
        if (empty($body['channel'])) {
            return;
        } // Management-only campaigns have no recipient execution.
        if (!$this->broadcastPlanner) {
            throw new \DomainException('Campaign channel planner is not configured.');
        }
        $audience = $body['audience'] ?? [];
        $wpdb = $this->database->db();
        $profiles = $this->database->table('profiles');
        $args = [(int) $payload['cursor']];
        $where = 'p.id>%d AND p.state=\'active\'';
        if (!empty($audience['segment_uuid'])) {
            $segment = $this->required($audience['segment_uuid']);
            if (!isset($payload['audience_generation'])) {
                $segment = $this->materializedSegment($audience['segment_uuid']);
            }
            if ($segment['kind'] !== 'segment' || (int) ($payload['audience_generation'] ?? $segment['generation']) < 1) {
                throw new \DomainException('Campaign segment must have a published membership generation.');
            }
            $members = $this->database->table('memberships');
            $where .= " AND EXISTS (SELECT 1 FROM {$members} m WHERE m.profile_id=p.id AND m.definition_id=%d AND m.generation=%d)";
            $args[] = $segment['id'];
            $args[] = $payload['audience_generation'] ?? $segment['generation'];
            $payload['audience_generation'] = $payload['audience_generation'] ?? (int) $segment['generation'];
        } elseif (!empty($audience['profile_uuids'])) {
            $where .= ' AND p.uuid IN (' . implode(',', array_fill(0, count($audience['profile_uuids']), '%s')) . ')';
            $args = array_merge($args, $audience['profile_uuids']);
        } elseif (($audience['all'] ?? false) !== true) {
            throw new \DomainException('Campaign must explicitly select an audience.');
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.id,p.uuid FROM {$profiles} p WHERE {$where} ORDER BY p.id LIMIT 100", ...$args), ARRAY_A);
        foreach ($rows as $profile) {
            $live = $this->required($row['uuid']);
            if ($live['state'] !== 'running' || (int) $live['cancel_epoch'] !== (int) $payload['epoch']) {
                return;
            }
            ($this->broadcastPlanner)($row + ['execution_body' => $body], $profile['uuid'], 'campaign:' . $row['uuid'] . ':' . $payload['version_id'] . ':' . $profile['uuid']);
            $payload['cursor'] = (int) $profile['id'];
        }
        if (count($rows) === 100) {
            $this->queue->enqueue('campaign_broadcast', $payload, 'broadcast:' . $row['uuid'] . ':' . $payload['version_id'] . ':' . $payload['cursor']);
        }
    }

    private function required(string $uuid): array
    {
        return $this->database->get('definitions', $uuid) ?? throw new \DomainException('Definition not found.');
    }

    private function lock(string $uuid): array
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('definitions');
        $locked = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE uuid=%s FOR UPDATE", $uuid));
        return $locked ? $this->required($uuid) : throw new \DomainException('Definition not found.');
    }

    private function validate(string $kind, array $body, bool $publishing): void
    {
        if (strlen(Json::encode($body)) > 262144) {
            throw new \InvalidArgumentException('Definition is too large.');
        }
        $fields = match ($kind) {
            'campaign' => ['audience', 'channel', 'provider_uuid', 'content', 'purpose', 'scheduled_at', 'timezone', 'goals', 'budget', 'tracking', 'asset_uuids', 'offer_uuid', 'experiment_uuid'],
            'automation' => ['trigger', 'nodes', 'edges', 'reentry', 'cooldown', 'max_active', 'exit_rule'],
            'segment' => ['rule', 'mode'],
            'experiment' => ['variants', 'unit', 'metric', 'seed', 'eligibility', 'lookback_days', 'protocol'],
            'promotion' => ['discount_type', 'amount_minor', 'currency', 'exponent', 'percent_bps', 'free_shipping', 'usage_limit', 'expires_in_days', 'product_ids', 'category_ids', 'minimum_minor', 'eligibility'],
            'program' => ['type', 'currency', 'exponent', 'financial_retention_days', 'points_per_major', 'rate_bps', 'fixed_minor', 'hold_days', 'earn_expiry_days', 'minimum_minor', 'referrer_points', 'referee_points', 'redemption_minor_per_point', 'rules'],
            'asset', 'content' => ['type', 'subject', 'text', 'html', 'attachment_id', 'locale', 'status', 'rights', 'seo_title', 'seo_description', 'landing_url'],
            'offline' => ['type', 'campaign_uuid', 'location', 'starts_at', 'ends_at', 'asset_uuid', 'link_uuid', 'qr_uuid', 'promotion_uuid', 'cost', 'currency', 'exponent', 'notes'],
            'event' => ['type', 'campaign_uuid', 'location', 'starts_at', 'ends_at', 'timezone', 'registration', 'capacity', 'link_uuid', 'cost', 'currency', 'exponent'],
            'influencer', 'partner' => ['type', 'profile_uuid', 'handles', 'campaign_uuid', 'deliverables', 'cost', 'currency', 'exponent', 'program_uuid', 'link_uuid', 'promotion_uuid', 'terms'],
            'personalization', 'recommendation' => ['surface', 'title', 'html', 'url', 'rule', 'strategy', 'config', 'fallback'],
            default => [],
        };
        if (array_diff(array_keys($body), $fields)) {
            throw new \InvalidArgumentException('Unknown definition fields.');
        }
        foreach ($body as $key => $value) {
            if (str_ends_with($key, '_uuid') && (!is_string($value) || !preg_match('/^[a-f0-9-]{36}$/Di', $value))) {
                throw new \InvalidArgumentException('Invalid referenced UUID.');
            }
            if (in_array($key, ['starts_at', 'ends_at', 'scheduled_at'], true) && (!is_string($value) || strtotime($value) === false)) {
                throw new \InvalidArgumentException('Invalid schedule.');
            }
            if (in_array($key, ['capacity', 'attachment_id', 'usage_limit', 'expiry_days', 'hold_days', 'cost', 'amount', 'exponent'], true) && (!is_int($value) || $value < 0)) {
                throw new \InvalidArgumentException('Invalid numeric definition field.');
            }
            if ($key === 'attachment_id' && $value > 0) {
                $attachment = get_post($value);
                if (!$attachment || $attachment->post_type !== 'attachment' || $attachment->post_status === 'trash') {
                    throw new \InvalidArgumentException('Referenced media attachment is unavailable.');
                }
            }
            if (in_array($key, ['text', 'html', 'subject', 'seo_title', 'seo_description', 'location', 'notes', 'terms'], true) && (!is_string($value) || strlen($value) > 65536)) {
                throw new \InvalidArgumentException('Invalid content field.');
            }
            if ($key === 'currency' && (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value))) {
                throw new \InvalidArgumentException('Invalid currency.');
            }
            if ($key === 'timezone' && (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(), true))) {
                throw new \InvalidArgumentException('Invalid timezone.');
            }
            if (in_array($key, ['type', 'locale', 'status', 'rights'], true) && (!is_string($value) || strlen($value) > ($key === 'rights' ? 4096 : 128))) {
                throw new \InvalidArgumentException('Invalid typed asset metadata.');
            }
            if ($key === 'handles') {
                if (!is_array($value) || count($value) > 20) {
                    throw new \InvalidArgumentException('Partner handles must be a bounded channel map.');
                }
                foreach ($value as $channel => $handle) {
                    if (!is_string($channel) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $channel) || !is_string($handle) || strlen($handle) > 191) {
                        throw new \InvalidArgumentException('Invalid partner channel handle.');
                    }
                }
            }
            if ($key === 'deliverables') {
                if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
                    throw new \InvalidArgumentException('Deliverables must be a bounded list.');
                }
                foreach ($value as $deliverable) {
                    if (!is_array($deliverable) || array_diff(array_keys($deliverable), ['type', 'title', 'status', 'due_at', 'url', 'asset_uuid', 'quantity']) || !is_string($deliverable['type'] ?? null) || strlen($deliverable['type']) > 64) {
                        throw new \InvalidArgumentException('Invalid typed deliverable.');
                    }
                    foreach ($deliverable as $field => $item) {
                        if ($field === 'quantity' && (!is_int($item) || $item < 1 || $item > 1000000)) {
                            throw new \InvalidArgumentException('Invalid deliverable quantity.');
                        }
                        if ($field !== 'quantity' && (!is_string($item) || strlen($item) > 2048)) {
                            throw new \InvalidArgumentException('Invalid deliverable text.');
                        }
                        if ($field === 'due_at' && strtotime($item) === false) {
                            throw new \InvalidArgumentException('Invalid deliverable due date.');
                        }
                        if ($field === 'asset_uuid' && !preg_match('/^[a-f0-9-]{36}$/Di', $item)) {
                            throw new \InvalidArgumentException('Invalid deliverable asset UUID.');
                        }
                        if ($field === 'status' && !in_array($item, ['planned', 'in_progress', 'submitted', 'accepted', 'cancelled'], true)) {
                            throw new \InvalidArgumentException('Invalid deliverable status.');
                        }
                    }
                }
            }
            if ($key === 'registration') {
                if (!is_array($value) || array_diff(array_keys($value), ['enabled', 'url', 'capacity']) || isset($value['enabled']) && !is_bool($value['enabled']) || isset($value['url']) && (!is_string($value['url']) || strlen($value['url']) > 2048 || !filter_var($value['url'], FILTER_VALIDATE_URL)) || isset($value['capacity']) && (!is_int($value['capacity']) || $value['capacity'] < 1 || $value['capacity'] > 1000000)) {
                    throw new \InvalidArgumentException('Invalid event registration descriptor.');
                }
            }
        }
        if (!$publishing) {
            return;
        }
        if (in_array($kind, ['personalization', 'recommendation'], true)) {
            Personalization::validate($body);
        }
        if ($kind === 'segment') {
            Rules::validate($body['rule'] ?? []);
        }
        if ($kind === 'program') {
            if (!in_array($body['type'] ?? '', ['loyalty', 'referral', 'affiliate'], true) || !is_int($body['financial_retention_days'] ?? null) || $body['financial_retention_days'] < 1) {
                throw new \InvalidArgumentException('Program type and explicit financial retention policy are required.');
            }
            foreach (['points_per_major' => 100000, 'rate_bps' => 10000, 'fixed_minor' => PHP_INT_MAX, 'hold_days' => 365, 'earn_expiry_days' => 3650, 'minimum_minor' => PHP_INT_MAX, 'referrer_points' => PHP_INT_MAX, 'referee_points' => PHP_INT_MAX, 'redemption_minor_per_point' => PHP_INT_MAX] as $field => $maximum) {
                if (isset($body[$field]) && (!is_int($body[$field]) || $body[$field] < 0 || $body[$field] > $maximum)) {
                    throw new \InvalidArgumentException('Invalid program amount or rate.');
                }
            }
            if (isset($body['rules'])) {
                Rules::validate($body['rules']);
            }
        }
        if ($kind === 'promotion') {
            if (!in_array($body['discount_type'] ?? '', ['percent', 'fixed_cart', 'fixed_product'], true) || !is_int($body['amount_minor'] ?? null) || $body['amount_minor'] < 0) {
                throw new \InvalidArgumentException('Promotion discount type and nonnegative amount are required.');
            }
            if (isset($body['percent_bps']) && (!is_int($body['percent_bps']) || $body['percent_bps'] < 0 || $body['percent_bps'] > 10000)) {
                throw new \InvalidArgumentException('Invalid promotion percentage.');
            }
            foreach (['product_ids', 'category_ids'] as $field) {
                if (isset($body[$field]) && (!is_array($body[$field]) || count($body[$field]) > 100 || array_filter($body[$field], static fn($id): bool => !is_int($id) || $id < 1))) {
                    throw new \InvalidArgumentException('Invalid promotion object references.');
                }
            }
            if (isset($body['eligibility'])) {
                Rules::validate($body['eligibility']);
            }
        }
        if ($kind === 'automation') {
            Graph::validate($body);
        }
        if ($kind === 'campaign' && isset($body['channel'])) {
            if (!in_array($body['channel'], ['email', 'sms', 'push', 'whatsapp', 'telegram', 'social', 'webhook'], true) || empty($body['provider_uuid']) || !is_array($body['content'] ?? null) || !is_array($body['audience'] ?? null)) {
                throw new \InvalidArgumentException('Campaign channel, provider, content and audience are required.');
            }
            $audience = $body['audience'];
            if (array_diff(array_keys($audience), ['all', 'segment_uuid', 'profile_uuids']) || isset($audience['profile_uuids']) && (!is_array($audience['profile_uuids']) || count($audience['profile_uuids']) > 100)) {
                throw new \InvalidArgumentException('Invalid bounded campaign audience.');
            }
            if (count(array_filter(['all' => ($audience['all'] ?? false) === true, 'segment_uuid' => !empty($audience['segment_uuid']), 'profile_uuids' => !empty($audience['profile_uuids'])])) !== 1) {
                throw new \InvalidArgumentException('Choose exactly one explicit campaign audience.');
            }
            foreach ($audience['profile_uuids'] ?? [] as $profileUuid) {
                if (!is_string($profileUuid) || !preg_match('/^[a-f0-9-]{36}$/Di', $profileUuid)) {
                    throw new \InvalidArgumentException('Invalid campaign recipient UUID.');
                }
            }
            if (isset($audience['segment_uuid'])) {
                $this->materializedSegment($audience['segment_uuid']);
            }
            $content = $body['content'];
            Graph::validateContent($content);
            $provider = $this->database->get('providers', $body['provider_uuid']);
            if (!$provider || $provider['state'] !== 'active') {
                throw new \InvalidArgumentException('Campaign provider must be active.');
            }
            if (!$this->providerCapabilities) {
                throw new \DomainException('Provider capability reader is not configured.');
            }
            $capabilities = ($this->providerCapabilities)($provider['type']);
            if (!in_array($body['channel'], $capabilities['channels'] ?? [], true)) {
                throw new \InvalidArgumentException('Campaign channel is unsupported by this provider.');
            }
        }
        if ($kind === 'campaign' && isset($body['budget'])) {
            $budget = $body['budget'];
            if (!is_array($budget) || array_diff(array_keys($budget), ['amount_minor', 'currency', 'exponent']) || !is_int($budget['amount_minor'] ?? null) || $budget['amount_minor'] < 0) {
                throw new \InvalidArgumentException('Invalid campaign budget.');
            }
            new \Wmos\Domain\Money($budget['amount_minor'], $budget['currency'] ?? '', $budget['exponent'] ?? 2);
        }
        if ($kind === 'campaign' && isset($body['goals'])) {
            if (!is_array($body['goals']) || !array_is_list($body['goals']) || count($body['goals']) > 20) {
                throw new \InvalidArgumentException('Invalid campaign goals.');
            }
            foreach ($body['goals'] as $goal) {
                if (!is_array($goal) || array_diff(array_keys($goal), ['metric', 'operator', 'target', 'currency', 'exponent']) || !in_array($goal['metric'] ?? '', ['revenue', 'orders', 'conversions', 'clicks', 'deliveries', 'referrals', 'loyalty_points'], true) || !in_array($goal['operator'] ?? '', ['gt', 'gte', 'eq'], true) || !is_int($goal['target'] ?? null) || $goal['target'] < 0) {
                    throw new \InvalidArgumentException('Invalid typed campaign goal.');
                }
            }
        }
        if ($kind === 'experiment') {
            $variants = $body['variants'] ?? [];
            if (!is_array($variants) || count($variants) < 2 || count($variants) > 10 || !in_array($body['unit'] ?? 'profile', ['profile', 'session'], true)) {
                throw new \InvalidArgumentException('Experiment requires 2..10 variants and a stable unit.');
            }
            $keys = [];
            $sum = 0;
            foreach ($variants as $variant) {
                if (!is_array($variant) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $variant['key'] ?? '') || isset($keys[$variant['key']]) || !is_int($variant['weight'] ?? null) || $variant['weight'] < 1 || $variant['weight'] > 10000) {
                    throw new \InvalidArgumentException('Invalid experiment variant.');
                }
                $keys[$variant['key']] = true;
                $sum += $variant['weight'];
            }
            if ($sum !== 10000) {
                throw new \InvalidArgumentException('Experiment weights must sum to 10000.');
            }
        }
        if (in_array($kind, ['offline', 'event', 'influencer', 'partner', 'asset', 'content'], true) && empty($body['type'])) {
            throw new \InvalidArgumentException('A typed marketing definition is required.');
        }
    }

    private function materializedSegment(string $uuid): array
    {
        $segment = $this->required($uuid);
        if ($segment['kind'] !== 'segment' || $segment['state'] === 'archived' || (int) $segment['generation'] < 1 || (int) ($segment['materialized_version_id'] ?? 0) !== (int) ($segment['published_version_id'] ?? 0)) {
            throw new \DomainException('Rebuild the published segment version before selecting its audience.');
        }
        return $segment;
    }

    private function validateSubworkflows(array &$body, string $root, array $ancestors): void
    {
        if (count($ancestors) > 3) {
            throw new \InvalidArgumentException('Subworkflow depth exceeds three.');
        }
        foreach ($body['nodes'] as &$node) {
            $action = $node['type'] === 'action' ? ($node['config']['action'] ?? '') : '';
            if (!in_array($node['type'], ['subworkflow', 'experiment'], true) && !in_array($action, ['coupon', 'points'], true)) {
                continue;
            }
            $reference = $action === 'coupon' ? 'promotion_uuid' : ($action === 'points' ? 'program_uuid' : 'definition_uuid');
            $uuid = $node['config'][$reference];
            if ($uuid === $root || in_array($uuid, $ancestors, true)) {
                throw new \InvalidArgumentException('Recursive subworkflow is prohibited.');
            }
            $child = $this->required($uuid);
            $expectedKind = $action === 'coupon' ? 'promotion' : ($action === 'points' ? 'program' : ($node['type'] === 'experiment' ? 'experiment' : 'automation'));
            if ($child['kind'] !== $expectedKind || empty($child['published_version_id'])) {
                throw new \InvalidArgumentException('Node must reference a published compatible definition.');
            }
            $node['config']['version_id'] = (int) $child['published_version_id'];
            if ($expectedKind === 'automation') {
                $nested = $this->versionBody((int) $child['published_version_id']);
                $this->validateSubworkflows($nested, $root, array_merge($ancestors, [$uuid]));
            }
        }
    }
}
