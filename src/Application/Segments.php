<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Domain\Rules;
use Wmos\Infrastructure\Audit;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;
use Wmos\Infrastructure\Queue;

final class Segments
{
    private $eventConsumer = null;
    private $factLoader = null;
    public function __construct(private Database $database, private Queue $queue, private Audit $audit)
    {
        $queue->register('segment_build', [$this, 'process']);
        $queue->register('segment_diff', [$this, 'diff']);
    }
    public function setEventConsumer(callable $consumer): void
    {
        $this->eventConsumer = $consumer;
    }
    public function setFactLoader(callable $loader): void
    {
        $this->factLoader = $loader;
    }

    public function diff(array $job, array $payload): void
    {
        if (!$this->enabled()) {
            throw new \Wmos\Infrastructure\DeferredException('Segment module is disabled.');
        }
        $definition = $this->database->get('definitions', $payload['uuid']);
        if (!$definition) {
            return;
        }
        $wpdb = $this->database->db();
        $members = $this->database->table('memberships');
        $profiles = $this->database->table('profiles');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.id,p.uuid,EXISTS(SELECT 1 FROM {$members} m WHERE m.profile_id=p.id AND m.definition_id=%d AND m.generation=%d) AS was_member,EXISTS(SELECT 1 FROM {$members} m WHERE m.profile_id=p.id AND m.definition_id=%d AND m.generation=%d) AS is_member FROM {$profiles} p WHERE p.id>%d AND p.state='active' ORDER BY p.id LIMIT 100", $definition['id'], $payload['old'], $definition['id'], $payload['new'], $payload['cursor']), ARRAY_A) ?: [];
        foreach ($rows as $profile) {
            if ((int) $profile['was_member'] !== (int) $profile['is_member']) {
                $name = (int) $profile['is_member'] === 1 ? 'customer.segment.entered' : 'customer.segment.exited';
                $key = hash('sha256', $definition['uuid'] . ':' . $payload['new'] . ':' . $profile['uuid'] . ':' . $name);
                $event = $this->database->find('events', 'source_key', $key);
                if (!$event) {
                    $event = $this->database->insert('events', ['name' => $name, 'source' => 'segments', 'source_key' => $key, 'profile_id' => $profile['id'], 'object_type' => 'segment', 'object_id' => $definition['uuid'], 'properties' => Json::encode(['segment_uuid' => $definition['uuid'], 'generation' => (int) $payload['new']]), 'context' => '{}', 'occurred_at' => Database::now()]);
                }
                if ($this->eventConsumer) {
                    ($this->eventConsumer)($event);
                }
            }
            $payload['cursor'] = (int) $profile['id'];
        }
        if (count($rows) === 100) {
            $this->queue->enqueue('segment_diff', $payload, 'segment-diff:' . $definition['uuid'] . ':' . $payload['new'] . ':' . $payload['cursor']);
        }
    }

    public function rebuild(string $definitionUuid): array
    {
        if (!$this->enabled()) {
            throw new \DomainException('Segment module is disabled.');
        }
        return $this->database->transaction(function () use ($definitionUuid): array {
            $row = $this->lock($definitionUuid);
            if ($row['kind'] !== 'segment' || empty($row['published_version_id']) || $row['state'] === 'archived') {
                throw new \DomainException('Published segment is required.');
            }
            if ($row['state'] === 'rebuilding') {
                throw new \DomainException('Segment rebuild is already active.');
            }
            $this->database->update('definitions', $definitionUuid, ['state' => 'rebuilding'], (int) $row['row_version']);
            $payload = ['uuid' => $definitionUuid, 'version_id' => (int) $row['published_version_id'], 'generation' => (int) $row['generation'] + 1, 'cursor' => 0];
            return $this->queue->enqueue('segment_build', $payload, 'segment:' . $definitionUuid . ':' . $payload['generation'] . ':0');
        });
    }

    public function process(array $job, array $payload): void
    {
        if (!$this->enabled()) {
            throw new \Wmos\Infrastructure\DeferredException('Segment module is disabled.');
        }
        $definition = $this->database->get('definitions', $payload['uuid']);
        if (!$definition || $definition['state'] !== 'rebuilding') {
            return;
        }
        $version = $this->database->find('definition_versions', 'id', (int) $payload['version_id']);
        $body = Json::decode($version['body']);
        $rule = $body['rule'];
        Rules::validate($rule);
        $rows = $this->query($rule, 100, (int) $payload['cursor'], true);
        $facts = $this->factLoader ? ($this->factLoader)($rows) : [];
        $this->database->transaction(function () use ($rows, $facts, &$payload, $definition, $rule): void {
            foreach ($rows as $row) {
                $payload['cursor'] = (int) $row['id'];
                if (Rules::matches($rule, $row, $facts[$row['uuid']] ?? []) !== true) {
                    continue;
                }
                $wpdb = $this->database->db();
                $table = $this->database->table('memberships');
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE definition_id=%d AND generation=%d AND profile_id=%d", $definition['id'], $payload['generation'], $row['id']));
                if (!$exists) {
                    $this->database->insert('memberships', ['definition_id' => $definition['id'], 'generation' => $payload['generation'], 'profile_id' => $row['id']]);
                }
            }
            if (count($rows) === 100) {
                $this->queue->enqueue('segment_build', $payload, 'segment:' . $definition['uuid'] . ':' . $payload['generation'] . ':' . $payload['cursor']);
            } else {
                $live = $this->lock($definition['uuid']);
                if ($live['state'] === 'rebuilding') {
                    $old = (int) $live['generation'];
                    $this->database->update('definitions', $live['uuid'], ['generation' => $payload['generation'], 'materialized_version_id' => $payload['version_id'], 'state' => 'active'], (int) $live['row_version']);
                    $this->audit->record('segment.rebuilt', $live['uuid'], ['generation' => $payload['generation'], 'previous_generation' => $old]);
                    $this->queue->enqueue('segment_diff', ['uuid' => $live['uuid'], 'old' => $old, 'new' => $payload['generation'], 'cursor' => 0], 'segment-diff:' . $live['uuid'] . ':' . $payload['generation'] . ':0');
                }
            }
        });
    }

    public function preview(array $ast, int $limit = 25): array
    {
        Rules::validate($ast);
        $matches = [];
        $rows = $this->query($ast, min(100, max(1, $limit)), 0, false);
        $facts = $this->factLoader ? ($this->factLoader)($rows) : [];
        foreach ($rows as $row) {
            if (Rules::matches($ast, $row, $facts[$row['uuid']] ?? []) === true) {
                $matches[] = ['uuid' => $row['uuid'], 'order_count' => (int) $row['order_count'], 'revenue_minor' => (string) $row['revenue_minor'], 'currency' => $row['currency']];
            }
        }
        return $matches;
    }

    private function query(array $rule, int $limit, int $cursor, bool $building): array
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('profiles');
        try {
            $compiled = Rules::compile($rule);
        } catch (\DomainException) {
            $compiled = ['sql' => '1=1', 'args' => []];
        }
        $args = array_merge([$cursor], $compiled['args'], [$limit]);
        return $wpdb->get_results($wpdb->prepare("SELECT p.id,p.uuid,p.state,p.user_id,p.order_count,p.revenue_minor,p.currency,p.exponent,p.last_order_at,p.created_at,p.tags,p.attributes FROM {$table} p WHERE p.id>%d AND p.state='active' AND ({$compiled['sql']}) ORDER BY p.id LIMIT %d", ...$args), ARRAY_A) ?: [];
    }

    private function lock(string $uuid): array
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('definitions');
        $locked = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE uuid=%s FOR UPDATE", $uuid));
        return $locked ? $this->database->get('definitions', $uuid) : throw new \DomainException('Segment not found.');
    }
    private function enabled(): bool
    {
        return in_array('segments', get_option('wmos_settings', [])['enabled_modules'] ?? [], true);
    }
}
