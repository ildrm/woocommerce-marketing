<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Queue, Audit};

final class Privacy
{
    private const PERSONAL_TABLES = ['identities', 'consents', 'consent_tokens', 'memberships', 'messages', 'steps', 'runs', 'events', 'touchpoints', 'assignments', 'carts'];

    public function __construct(private Database $database, private Queue $queue, private Contacts $contacts, private Consent $consent, private Audit $audit)
    {
        $queue->register('privacy.erase', [$this, 'process']);
        $queue->register('privacy.retention', function (): void {
            $this->retention();
        });
    }

    public function export(string $profileUuid, array $cursors = []): array
    {
        if (array_diff(array_keys($cursors), ['consents','messages','events','touchpoints','ledger','program_accounts'])) {
            throw new \InvalidArgumentException('Unknown export cursor group.');
        }
        foreach ($cursors as $value) {
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('Export cursors must be nonnegative integers.');
            }
        }
        $profile = $this->contacts->raw($profileUuid);
        $subjects = $this->aliases($profile);
        $ids = array_merge([(int)$profile['id']], array_column($subjects, 'id'));
        $result = ['contact' => $this->contacts->get($profileUuid), 'cursors' => $cursors, 'next_cursors' => $cursors, 'identities' => $this->contacts->exportIdentities($profileUuid), 'records' => [], 'done' => true];
        foreach (['consents', 'messages', 'events', 'touchpoints', 'ledger', 'program_accounts'] as $table) {
            $db = $this->database->db();
            $projection = implode(',', array_keys(\Wmos\Infrastructure\Schema::columns($table)));
            $rows = $db->get_results($db->prepare('SELECT ' . $projection . ' FROM ' . $this->database->table($table) . ' WHERE profile_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') AND id>%d ORDER BY id LIMIT 100', ...array_merge($ids, [max(0, (int)($cursors[$table] ?? 0))])), ARRAY_A);
            if (count($rows) === 100) {
                $result['done'] = false;
            }
            foreach ($rows as $row) {
                $result['next_cursors'][$table] = (int) $row['id'];
                $safe = array_intersect_key($row, array_flip(['uuid', 'purpose', 'channel', 'status', 'source', 'policy_version', 'effective_at', 'name', 'state', 'created_at', 'points', 'amount_minor', 'currency', 'reason', 'balance', 'held', 'accepted_at', 'occurred_at']));
                if ($table === 'consents') {
                    $safe['evidence'] = json_decode($row['evidence'] ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                }
                $result['records'][] = ['group' => $table, 'item' => $safe];
            }
        }
        $this->audit->record('privacy.exported', $profileUuid, ['cursors' => $cursors, 'next_cursors' => $cursors, 'identities' => $this->contacts->exportIdentities($profileUuid)]);
        return $result;
    }

    public function erase(string $profileUuid): array
    {
        return $this->database->transaction(function () use ($profileUuid): array {
            $profile = $this->locked($profileUuid);
            $old = $this->database->db()->get_row($this->database->db()->prepare('SELECT uuid,state FROM ' . $this->database->table('privacy_jobs') . ' WHERE profile_id=%d AND kind=%s ORDER BY id DESC LIMIT 1', $profile['id'], 'erase'), ARRAY_A);
            if ($old && in_array($old['state'], ['pending', 'running', 'completed'], true)) {
                return ['uuid' => $old['uuid'], 'state' => $old['state']];
            }
            $this->database->update('profiles', $profileUuid, ['state' => 'erasing', 'erasure_epoch' => (int) $profile['erasure_epoch'] + 1]);
            $this->consent->suppress($profileUuid, '*', '*', 'erasure');
            $this->database->db()->query($this->database->db()->prepare('UPDATE ' . $this->database->table('runs') . " SET state='cancelled',cancel_epoch=cancel_epoch+1,row_version=row_version+1 WHERE profile_id=%d AND state NOT IN ('completed','cancelled','failed')", $profile['id']));
            $aliases = $this->aliases($profile);
            $row = $this->database->insert('privacy_jobs', ['profile_id' => $profile['id'], 'kind' => 'erase', 'state' => 'pending', 'cursor_id' => 0, 'policy' => json_encode(['table_index' => 0, 'alias_uuids' => array_column($aliases, 'uuid'), 'retain_financial' => true, 'external_processor_removal' => 'manual_review_required'], JSON_THROW_ON_ERROR)]);
            $this->queue->enqueue('privacy.erase', ['privacy_uuid' => $row['uuid']], 'privacy:' . $row['uuid'] . ':0');
            $this->audit->record('privacy.erasure_requested', $profileUuid, ['job' => $row['uuid']]);
            return ['uuid' => $row['uuid'], 'state' => 'pending', 'external_removal' => 'manual_review_required'];
        });
    }

    public function process(array $job, array $payload): void
    {
        $privacy = $this->database->get('privacy_jobs', $payload['privacy_uuid']) ?? throw new \RuntimeException('Privacy job not found.');
        if ($privacy['state'] === 'completed') {
            return;
        }
        $policy = json_decode($privacy['policy'], true, 32, JSON_THROW_ON_ERROR);
        $index = (int) $policy['table_index'];
        if ($index >= count(self::PERSONAL_TABLES)) {
            foreach ($policy['alias_uuids'] ?? [] as $aliasUuid) {
                $alias = $this->database->get('profiles', $aliasUuid);
                if (!$alias || $alias['state'] === 'erased') {
                    continue;
                }$child = $this->erase($aliasUuid);
                $this->process([], ['privacy_uuid' => $child['uuid']]);
                $fresh = $this->database->get('privacy_jobs', $child['uuid']);
                $this->queue->enqueue('privacy.erase', ['privacy_uuid' => $privacy['uuid']], 'privacy-alias:' . $privacy['uuid'] . ':' . $child['uuid'] . ':' . $fresh['row_version']);
                return;
            }
        }
        $this->database->transaction(function () use ($privacy, $policy, $index): void {
            $profile = $this->database->find('profiles', 'id', (int) $privacy['profile_id']);
            if (!$profile) {
                throw new \RuntimeException('Privacy subject unavailable.');
            }
            $this->locked($profile['uuid']);
            $privacy = $this->database->get('privacy_jobs', $privacy['uuid']);
            $policy = json_decode($privacy['policy'], true, 32, JSON_THROW_ON_ERROR);
            $index = (int)$policy['table_index'];
            if (isset(self::PERSONAL_TABLES[$index])) {
                $table = self::PERSONAL_TABLES[$index];
                if ($table === 'steps') {
                    $db = $this->database->db();
                    $ids = $db->get_results($db->prepare('SELECT s.id,s.uuid FROM ' . $this->database->table('steps') . ' s INNER JOIN ' . $this->database->table('runs') . ' r ON r.id=s.run_id WHERE r.profile_id=%d AND s.id>%d ORDER BY s.id LIMIT 100', $profile['id'], $privacy['cursor_id']), ARRAY_A);
                    $rows = $ids ?: [];
                } else {
                    $rows = $this->database->list($table, ['profile_id' => $profile['id']], 100, (int) $privacy['cursor_id']);
                }
                foreach ($rows as $row) {
                    $this->database->delete($table, $row['uuid']);
                }
                $cursor = $rows ? (int) $rows[array_key_last($rows)]['id'] : 0;
                if (count($rows) < 100) {
                    $policy['table_index'] = $index + 1;
                    $cursor = 0;
                }
                $this->database->update('privacy_jobs', $privacy['uuid'], ['state' => 'running', 'cursor_id' => $cursor, 'policy' => json_encode($policy, JSON_THROW_ON_ERROR)]);
                $this->queue->enqueue('privacy.erase', ['privacy_uuid' => $privacy['uuid']], 'privacy:' . $privacy['uuid'] . ':' . $policy['table_index'] . ':' . $cursor);
                return;
            }
            $db = $this->database->db();
            $db->query($db->prepare('DELETE r FROM ' . $this->database->table('recommendation_order_products') . ' r INNER JOIN ' . $this->database->table('conversions') . ' c ON c.order_id=r.order_id WHERE c.profile_id=%d', $profile['id']));
            $db->query($db->prepare('UPDATE ' . $this->database->table('identity_merges') . " SET snapshot='',proof='',reason='Subject erased',row_version=row_version+1 WHERE source_id=%d OR target_id=%d", $profile['id'], $profile['id']));
            // Financial references are retained under the explicit program policy; do not claim anonymous data.
            $this->database->update('profiles', $profile['uuid'], ['state' => 'erased', 'email_cipher' => null, 'email_hash' => null, 'user_id' => null, 'tags' => '[]', 'attributes' => '{}', 'order_count' => 0, 'revenue_minor' => 0, 'last_order_at' => null]);
            $this->database->update('privacy_jobs', $privacy['uuid'], ['state' => 'completed', 'policy' => json_encode($policy + ['retained_reason' => 'Financial obligations and minimal contact-prevention hashes; review external processor removal separately.'], JSON_THROW_ON_ERROR)]);
            $this->audit->record('privacy.local_erasure_completed', $profile['uuid'], ['external_removal' => 'manual_review_required', 'financial_retained' => true]);
        });
    }

    public function job(string $uuid): array
    {
        $row = $this->database->get('privacy_jobs', $uuid) ?? throw new \RuntimeException('Privacy job not found.');
        return ['uuid' => $row['uuid'], 'state' => $row['state'], 'policy' => json_decode($row['policy'], true, 32, JSON_THROW_ON_ERROR), 'last_error' => $row['last_error']];
    }

    public function retention(): array
    {
        $db = $this->database->db();
        $counts = [];
        $configured = (int)(get_option('wmos_settings', [])['retention_days'] ?? 90);
        $rawDays = max(1, min(3650, $configured));
        foreach (['events' => $rawDays, 'touchpoints' => $rawDays, 'webhook_receipts' => 30, 'consent_tokens' => 2, 'audit' => 365, 'jobs' => 30, 'api_requests' => 1] as $table => $days) {
            $extra = match ($table) {
                'jobs'=>" AND state IN ('completed','cancelled','failed')",'webhook_receipts'=>' AND processed_at IS NOT NULL',default=>''
            };
            $rows = $db->get_col($db->prepare('SELECT uuid FROM ' . $this->database->table($table) . ' WHERE ' . ($table === 'api_requests' ? 'expires_at' : 'created_at') . '<%s' . $extra . ' ORDER BY id LIMIT 100', $table === 'api_requests' ? Database::now() : gmdate('Y-m-d H:i:s', time() - $days * 86400)));
            foreach ($rows as $uuid) {
                $this->database->delete($table, $uuid);
            }
            $counts[$table] = count($rows);
        }
        $rows = $db->get_col($db->prepare('SELECT uuid FROM ' . $this->database->table('messages') . " WHERE created_at<%s AND content<>'' AND state IN ('submitted','delivered','blocked','cancelled','failed','bounced','complained') ORDER BY id LIMIT 100", gmdate('Y-m-d H:i:s', time() - $rawDays * 86400)));
        foreach ($rows as $uuid) {
            $this->database->update('messages', $uuid, ['content' => '']);
        }
        $counts['message_bodies'] = count($rows);
        return $counts;
    }

    public function registerWordPress(): void
    {
        add_filter('wp_privacy_personal_data_exporters', function (array $exporters): array {
            $exporters['woocommerce-marketing-os'] = ['exporter_friendly_name' => __('WooCommerce Marketing OS', 'woocommerce-marketing-os'), 'callback' => [$this, 'wordpressExport']];
            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', function (array $erasers): array {
            $erasers['woocommerce-marketing-os'] = ['eraser_friendly_name' => __('WooCommerce Marketing OS', 'woocommerce-marketing-os'), 'callback' => [$this, 'wordpressErase']];
            return $erasers;
        });
        add_action('admin_init', function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content('WooCommerce Marketing OS', wp_kses_post(__('This store may maintain marketing contact identities, purpose and channel consent, campaign interactions and program financial records. Enabled providers receive the minimum destination and reviewed message data. Nonessential tracking requires configured permission. Financial obligations and minimized suppression evidence may be retained; external processor deletion is reviewed separately. Ask the store for its enabled providers, purposes and retention policy.', 'woocommerce-marketing-os')));
            }
        });
    }

    public function wordpressExport(string $email, int $page): array
    {
        $profile = $this->profileForEmail($email);
        if (!$profile) {
            return ['data' => [], 'done' => true];
        }
        // Fixed-size per-table pages use their own cursors; offset is bounded by WordPress's paginated callback contract.
        $data = [];
        $subjects = $this->aliases($profile);
        $subjectIds = array_merge([(int)$profile['id']], array_column($subjects, 'id'));
        foreach (['consents', 'messages', 'events', 'touchpoints', 'ledger', 'program_accounts'] as $table) {
            $db = $this->database->db();
            $ids = $db->get_results($db->prepare('SELECT id,uuid FROM ' . $this->database->table($table) . ' WHERE profile_id IN (' . implode(',', array_fill(0, count($subjectIds), '%d')) . ') ORDER BY id LIMIT 100 OFFSET %d', ...array_merge($subjectIds, [max(0, $page - 1) * 100])), ARRAY_A);
            foreach ($ids as $id) {
                $row = $this->database->get($table, $id['uuid']);
                $values = array_intersect_key($row, array_flip(['purpose', 'channel', 'status', 'source', 'policy_version', 'effective_at', 'state', 'name', 'points', 'amount_minor', 'currency', 'reason', 'balance', 'held', 'created_at']));
                $fields = [];
                foreach ($values as $name => $value) {
                    $fields[] = ['name' => $name, 'value' => (string) $value];
                }
                $data[] = ['group_id' => 'wmos-' . $table, 'group_label' => 'Marketing ' . $table, 'item_id' => $table . '-' . $id['uuid'], 'data' => $fields];
            }
            if (count($ids) === 100) {
                $more = true;
            }
        }
        if ($page === 1) {
            $contact = $this->contacts->get($profile['uuid']);
            $data[] = ['group_id' => 'wmos-contact', 'group_label' => 'Marketing contact', 'item_id' => $profile['uuid'], 'data' => [['name' => 'Email', 'value' => $contact['email']], ['name' => 'Attributes', 'value' => json_encode($contact['attributes'], JSON_THROW_ON_ERROR)], ['name' => 'Tags', 'value' => implode(', ', $contact['tags'])]]];
        }
        if ($page === 1) {
            foreach ($this->contacts->exportIdentities($profile['uuid']) as $identity) {
                $data[] = ['group_id' => 'wmos-identities','group_label' => 'Marketing identities','item_id' => $identity['uuid'],'data' => [['name' => 'Kind','value' => $identity['kind']],['name' => 'Value','value' => $identity['value']],['name' => 'Verified at','value' => (string)$identity['verified_at']]]];
            }
        }
        return ['data' => $data, 'done' => !isset($more)];
    }

    public function wordpressErase(string $email, int $page): array
    {
        $profile = $this->profileForEmail($email);
        if (!$profile) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $job = $this->erase($profile['uuid']);
        $this->process([], ['privacy_uuid' => $job['uuid']]);
        $done = $this->job($job['uuid'])['state'] === 'completed';
        return ['items_removed' => true, 'items_retained' => true, 'messages' => ['Program financial obligations and minimized suppression evidence are retained under the configured policy. External provider removal requires documented merchant review.'], 'done' => $done];
    }

    /** Recorded merge provenance is the only alias expansion; there is no fuzzy identity matching. */
    private function aliases(array $profile): array
    {
        $db = $this->database->db();
        $pending = [(int)$profile['id']];
        $seen = [(int)$profile['id'] => true];
        $result = [];
        while ($pending) {
            $id = array_shift($pending);
            $rows = $db->get_results($db->prepare('SELECT p.id,p.uuid FROM ' . $this->database->table('identity_merges') . ' m INNER JOIN ' . $this->database->table('profiles') . ' p ON p.id=m.source_id WHERE m.target_id=%d AND m.state=%s AND p.state IN (%s,%s) ORDER BY m.id LIMIT 101', $id, 'merged', 'merged', 'erasing'), ARRAY_A);
            foreach ($rows as $row) {
                if (isset($seen[(int)$row['id']])) {
                    continue;
                }$seen[(int)$row['id']] = true;
                $result[] = $row;
                $pending[] = (int)$row['id'];
                if (count($result) > 100) {
                    throw new \RuntimeException('Large merge history requires bounded subject review.');
                }
            }
        }
        return $result;
    }

    private function profileForEmail(string $email): ?array
    {
        return $this->contacts->findByEmail($email);
    }

    private function locked(string $uuid): array
    {
        return $this->database->db()->get_row($this->database->db()->prepare('SELECT id,uuid,state,erasure_epoch FROM ' . $this->database->table('profiles') . ' WHERE uuid=%s FOR UPDATE', $uuid), ARRAY_A) ?: throw new \RuntimeException('Contact not found.');
    }
}
