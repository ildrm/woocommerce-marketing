<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Domain\Rules;
use Wmos\Infrastructure\Audit;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;
use Wmos\Infrastructure\Queue;
use Wmos\Infrastructure\DeferredException;

/** Durable DAG execution. Queue leases fence transitions; action keys fence effects. */
final class Automation
{
    private array $actions = [];
    private ?Experiments $experiments = null;
    private $factLoader = null;
    public function __construct(private Database $database, private Definitions $definitions, private Queue $queue, private Audit $audit)
    {
        $queue->register('automation_step', [$this, 'process']);
        $queue->register('automation_trigger', function (array $job, array $payload): void {
            $this->route($payload['event_uuid'], (int) ($payload['cursor'] ?? 0));
        });
        $queue->register('automation_wait_route', function (array $job, array $payload): void {
            $event = $this->database->get('events', $payload['event_uuid']);
            if ($event) {
                $this->wakeWaiters($event, (int) $payload['cursor']);
            }
        });
    }
    public function setActions(array $actions): void
    {
        foreach ($actions as $type => $handler) {
            if (!is_string($type) || !is_callable($handler)) {
                throw new \InvalidArgumentException('Invalid automation action registry.');
            }
        }
        $this->actions = $actions;
    }
    public function setExperiments(Experiments $experiments): void
    {
        $this->experiments = $experiments;
    }
    public function setFactLoader(callable $loader): void
    {
        $this->factLoader = $loader;
    }

    public function ingest(array $eventRow): void
    {
        if (!$this->enabled()) {
            return;
        }
        if (!isset($eventRow['uuid'], $eventRow['name'])) {
            throw new \InvalidArgumentException('A durable event is required.');
        }
        $this->queue->enqueue('automation_trigger', ['event_uuid' => $eventRow['uuid'], 'cursor' => 0], 'trigger:' . $eventRow['uuid'] . ':0');
        $this->wakeWaiters($eventRow, 0);
    }

    private function route(string $eventUuid, int $cursor): void
    {
        if (!$this->enabled()) {
            throw new DeferredException('Automation module is disabled.');
        }
        $event = $this->database->get('events', $eventUuid);
        if (!$event) {
            return;
        }
        $rows = $this->database->list('definitions', ['kind' => 'automation', 'state' => 'enabled'], 100, $cursor);
        foreach ($rows as $row) {
            if (empty($row['published_version_id'])) {
                continue;
            }
            $body = $this->definitions->versionBody((int) $row['published_version_id']);
            $name = $body['trigger']['event'] ?? null;
            if ($name === null) {
                foreach ($body['nodes'] as $node) {
                    if ($node['type'] === 'trigger') {
                        $name = $node['config']['event'] ?? null;
                    }
                }
            }
            if ($name === $event['name']) {
                $profile = empty($event['profile_id']) ? null : $this->database->find('profiles', 'id', (int) $event['profile_id']);
                $this->enter($row['uuid'], $profile['uuid'] ?? null, $event['uuid'], 'event:' . $event['uuid']);
            }
            $cursor = (int) $row['id'];
        }
        if (count($rows) === 100) {
            $this->queue->enqueue('automation_trigger', ['event_uuid' => $eventUuid, 'cursor' => $cursor], 'trigger:' . $eventUuid . ':' . $cursor);
        }
    }

    public function enter(string $definitionUuid, ?string $profileUuid, ?string $eventUuid, string $operationKey, ?int $pinnedVersionId = null, array $parentLink = []): array
    {
        if (!$this->enabled()) {
            throw new \DomainException('Automation module is disabled.');
        }
        return $this->database->transaction(function () use ($definitionUuid, $profileUuid, $eventUuid, $operationKey, $pinnedVersionId, $parentLink): array {
            $wpdb = $this->database->db();
            $defs = $this->database->table('definitions');
            $locked = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$defs} WHERE uuid=%s FOR UPDATE", $definitionUuid));
            $definition = $locked ? $this->database->get('definitions', $definitionUuid) : null;
            if (!$definition || $definition['kind'] !== 'automation' || $definition['state'] !== 'enabled' || empty($definition['published_version_id'])) {
                throw new \DomainException('Enabled published automation is required.');
            }
            $profile = $profileUuid === null ? null : $this->database->get('profiles', $profileUuid);
            if ($profileUuid !== null && (!$profile || $profile['state'] !== 'active')) {
                throw new \DomainException('Active profile is required.');
            }
            $event = $eventUuid === null ? null : $this->database->get('events', $eventUuid);
            if ($eventUuid !== null && !$event) {
                throw new \DomainException('Entry event is unavailable.');
            }
            $key = hash('sha256', $definitionUuid . ':' . ($profileUuid ?? 'anonymous') . ':' . $operationKey);
            $existing = $this->database->find('runs', 'entry_key', $key);
            if ($existing) {
                return $existing;
            }
            $versionId = $pinnedVersionId ?? (int) $definition['published_version_id'];
            $version = $this->database->find('definition_versions', 'id', $versionId);
            if (!$version || (int) $version['definition_id'] !== (int) $definition['id']) {
                throw new \DomainException('Pinned automation version does not belong to definition.');
            }
            $body = $this->definitions->versionBody($versionId);
            $runs = $this->database->table('runs');
            $subjectSql = $profile ? $wpdb->prepare('profile_id=%d', $profile['id']) : 'profile_id IS NULL';
            $previous = $wpdb->get_row($wpdb->prepare("SELECT id,state,created_at FROM {$runs} WHERE definition_id=%d AND {$subjectSql} ORDER BY id DESC LIMIT 1", $definition['id']), ARRAY_A);
            $policy = $body['reentry'] ?? 'per_event';
            if ($previous && ($policy === 'never' || $policy === 'after_terminal' && !in_array($previous['state'], ['completed', 'cancelled', 'failed', 'exited'], true) || $policy === 'cooldown' && strtotime($previous['created_at'] . ' UTC') > time() - (int) ($body['cooldown'] ?? 86400))) {
                return $this->database->find('runs', 'id', (int) $previous['id']);
            }
            $active = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$runs} WHERE definition_id=%d AND {$subjectSql} AND state IN ('pending','running','waiting','paused')", $definition['id']));
            if ($active >= (int) ($body['max_active'] ?? 5)) {
                throw new \DomainException('Subject active-run limit reached.');
            }
            if (array_diff(array_keys($parentLink), ['parent_run_uuid', 'parent_cancel_epoch'])) {
                throw new \InvalidArgumentException('Invalid parent workflow context.');
            }
            // Immutable event evidence is not authorization for derived customer profiling.
            $eventProperties = $event ? Json::decode($event['properties']) : [];
            $run = $this->database->insert('runs', ['definition_id' => $definition['id'], 'version_id' => $versionId, 'profile_id' => $profile['id'] ?? null, 'event_id' => $event['id'] ?? null, 'entry_key' => $key, 'state' => 'running', 'cancel_epoch' => $definition['cancel_epoch'], 'context' => Json::encode(['erasure_epoch' => (int) ($profile['erasure_epoch'] ?? 0), 'event_properties' => $eventProperties] + $parentLink)]);
            foreach ($body['nodes'] as $node) {
                if ($node['type'] === 'trigger') {
                    $this->activate($run, $node['id'], ['lineage' => 'root', 'splits' => []]);
                    break;
                }
            }
            $this->audit->record('automation.entered', $run['uuid'], ['definition_uuid' => $definitionUuid]);
            return $run;
        });
    }

    public function cancel(string $runUuid): array
    {
        return $this->database->transaction(function () use ($runUuid): array {
            $run = $this->lockRun($runUuid);
            if (in_array($run['state'], ['completed', 'cancelled', 'failed', 'exited'], true)) {
                return $run;
            }
            $updated = $this->database->update('runs', $runUuid, ['state' => 'cancelled', 'cancel_epoch' => (int) $run['cancel_epoch'] + 1], (int) $run['row_version']);
            $wpdb = $this->database->db();
            $steps = $this->database->table('steps');
            $wpdb->query($wpdb->prepare("UPDATE {$steps} SET state='cancelled',row_version=row_version+1,updated_at=%s WHERE run_id=%d AND state IN ('ready','running','waiting')", Database::now(), $run['id']));
            $this->cancelChildren($run);
            $this->audit->record('automation.cancelled', $runUuid);
            return $updated;
        });
    }

    public function process(array $job, array $payload): void
    {
        try {
            $this->execute($job, $payload);
        } catch (DeferredException | \Wmos\Infrastructure\RetryableException | \Wmos\Infrastructure\ConflictException | \Wmos\Infrastructure\AmbiguousException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->database->transaction(function () use ($job, $payload): void {
                $currentJob = $this->database->get('jobs', $job['uuid']);
                if (!$currentJob || $currentJob['lease_token'] !== $job['lease_token']) {
                    return;
                }
                $run = $this->database->get('runs', $payload['run_uuid']);
                if (!$run || in_array($run['state'], ['completed', 'cancelled', 'failed', 'exited'], true)) {
                    return;
                }
                $run = $this->lockRun($run['uuid']);
                $this->database->update('runs', $run['uuid'], ['state' => 'failed', 'cancel_epoch' => (int) $run['cancel_epoch'] + 1], (int) $run['row_version']);
                $wpdb = $this->database->db();
                $steps = $this->database->table('steps');
                $wpdb->query($wpdb->prepare("UPDATE {$steps} SET state='failed',row_version=row_version+1,updated_at=%s WHERE run_id=%d AND state IN ('ready','running','waiting')", Database::now(), $run['id']));
                $this->cancelChildren($run);
                $this->audit->record('automation.failed', $run['uuid'], ['step_uuid' => $payload['step_uuid']]);
            });
            throw $error;
        }
    }

    private function execute(array $job, array $payload): void
    {
        if (!$this->enabled()) {
            throw new DeferredException('Automation module is disabled.');
        }
        $run = $this->database->get('runs', $payload['run_uuid']);
        $step = $this->database->get('steps', $payload['step_uuid']);
        if (!$run || !$step || !in_array($run['state'], ['pending', 'running', 'waiting'], true) || in_array($step['state'], ['succeeded', 'cancelled', 'failed'], true)) {
            return;
        }
        $definition = $this->database->find('definitions', 'id', (int) $run['definition_id']);
        if (!$definition || in_array($definition['state'], ['archived', 'cancelled'], true) || (int) $run['cancel_epoch'] !== (int) $definition['cancel_epoch']) {
            $this->cancel($run['uuid']);
            return;
        }
        if ($definition['state'] === 'paused') {
            throw new DeferredException('Automation is paused.');
        }
        $body = $this->definitions->versionBody((int) $run['version_id']);
        $node = null;
        foreach ($body['nodes'] as $candidate) {
            if ($candidate['id'] === $step['node_key']) {
                $node = $candidate;
                break;
            }
        }
        if (!$node) {
            throw new \DomainException('Pinned node is unavailable.');
        }
        $config = $node['config'] ?? [];
        $meta = empty($step['result']) ? ['lineage' => 'root', 'splits' => []] : Json::decode($step['result']);
        $profile = empty($run['profile_id']) ? [] : ($this->database->find('profiles', 'id', (int) $run['profile_id']) ?? []);
        $context = Json::decode($run['context']);
        if (!empty($context['parent_run_uuid'])) {
            $parent = $this->database->get('runs', $context['parent_run_uuid']);
            if (!$parent || !in_array($parent['state'], ['running', 'waiting', 'pending'], true) || (int) $parent['cancel_epoch'] !== (int) $context['parent_cancel_epoch']) {
                $this->cancel($run['uuid']);
                return;
            }
            $parentDefinition = $this->database->find('definitions', 'id', (int) $parent['definition_id']);
            if ($parentDefinition && $parentDefinition['state'] === 'paused') {
                throw new DeferredException('Parent workflow is paused.');
            }
        }
        if (!empty($run['profile_id']) && (!$profile || $profile['state'] !== 'active' || (int) $profile['erasure_epoch'] !== (int) ($context['erasure_epoch'] ?? 0))) {
            $this->cancel($run['uuid']);
            return;
        }
        $outcome = 'success';
        $output = [];
        $facts = [];
        if (in_array($node['type'], ['condition', 'filter', 'goal', 'branch'], true) && $profile && $this->factLoader) {
            // Consent withdrawal must win over previously stored or event-supplied facts.
            $facts = ($this->factLoader)([$profile])[$profile['uuid']] ?? [];
        }
        switch ($node['type']) {
            case 'trigger':
                break;
            case 'condition':
            case 'filter':
            case 'goal':
                    $match = Rules::matches($config['rule'], $profile, $facts);
                    $outcome = $match === null ? 'unknown' : ($match ? 'true' : 'false');
                break;
            case 'branch':
                $outcome = 'default';
                foreach ($config['cases'] as $case) {
                    if (Rules::matches($case['rule'], $profile, $facts) === true) {
                        $outcome = $case['outcome'];
                        break;
                    }
                }
                break;
            case 'delay':
                if (empty($meta['due'])) {
                    $due = self::delayDue($config, time());
                    $this->wait($job, $run, $step, $meta + ['due' => $due], max(1, $due - time()));
                    return;
                }
                if (time() < (int) $meta['due']) {
                    throw new DeferredException('Delay has not elapsed.', (int) $meta['due'] - time());
                }
                break;
            case 'wait':
                if (empty($meta['due'])) {
                    $meta += ['due' => time() + $config['timeout'], 'event' => $config['event'], 'started_at' => Database::now()];
                    $this->wait($job, $run, $step, $meta, $config['timeout']);
                    $this->catchUpWait($run, $step, $meta);
                    return;
                }
                if (!empty($meta['wake_event'])) {
                    $outcome = 'event';
                } elseif (time() >= (int) $meta['due']) {
                    $this->catchUpWait($run, $step, $meta);
                    $fresh = $this->database->get('steps', $step['uuid']);
                    $meta = Json::decode($fresh['result']);
                    $outcome = empty($meta['wake_event']) ? 'timeout' : 'event';
                } else {
                    throw new DeferredException('Wait has not completed.', (int) $meta['due'] - time());
                }
                break;
            case 'action':
            case 'webhook':
                $action = $node['type'] === 'webhook' ? 'webhook' : $config['action'];
                if (!isset($this->actions[$action])) {
                    throw new \DomainException('Action handler unavailable.');
                }
                $output = ($this->actions[$action])($config, $run, $step);
                if (!is_array($output) || strlen(Json::encode($output)) > 4096) {
                    throw new \DomainException('Invalid action output.');
                }
                $outcome = $output['outcome'] ?? 'success';
                break;
            case 'experiment':
                if (!$this->experiments) {
                    throw new \DomainException('Experiment service unavailable.');
                }
                $unit = $profile['uuid'] ?? ($context['event_properties']['session_id'] ?? $context['facts']['session_id'] ?? $run['uuid']);
                $assignment = $this->experiments->assign($config['definition_uuid'], $unit, $profile['uuid'] ?? null, $config['version_id'] ?? null);
                $outcome = $assignment['variant'];
                $output = ['assignment_uuid' => $assignment['uuid']];
                break;
            case 'subworkflow':
                if (empty($meta['child_uuid'])) {
                    $child = $this->enter($config['definition_uuid'], $profile['uuid'] ?? null, null, 'parent-step:' . $step['uuid'], $config['version_id'] ?? null, ['parent_run_uuid' => $run['uuid'], 'parent_cancel_epoch' => (int) $run['cancel_epoch']]);
                    $meta['child_uuid'] = $child['uuid'];
                }
                $child = $this->database->get('runs', $meta['child_uuid']);
                if (!in_array($child['state'], ['completed', 'exited', 'failed', 'cancelled'], true)) {
                    $this->wait($job, $run, $step, $meta, 30);
                    return;
                }
                $outcome = in_array($child['state'], ['completed', 'exited'], true) ? 'success' : 'failure';
                break;
            case 'join':
                if (!$this->joinReady($run, $node, $meta)) {
                    $this->wait($job, $run, $step, $meta, 30);
                    return;
                }
                break;
            case 'split':
            case 'exit':
                break;
            default:
                throw new \DomainException('Unsupported node type.');
        }
        $this->finish($job, $run, $step, $body, $node, $meta, $outcome, $output);
    }

    private function activate(array $run, string $nodeKey, array $meta): array
    {
        $key = hash('sha256', $run['uuid'] . ':' . $nodeKey . ':' . $meta['lineage']);
        $existing = $this->database->find('steps', 'activation_key', $key);
        if ($existing) {
            return $existing;
        }
        $wpdb = $this->database->db();
        $table = $this->database->table('steps');
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id=%d", $run['id']));
        if ($count >= 1000) {
            throw new \DomainException('Workflow activation budget exceeded.');
        }
        $step = $this->database->insert('steps', ['run_id' => $run['id'], 'node_key' => $nodeKey, 'activation_key' => $key, 'state' => 'ready', 'result' => Json::encode($meta)]);
        $this->queue->enqueue('automation_step', ['run_uuid' => $run['uuid'], 'step_uuid' => $step['uuid']], 'step:' . $step['uuid'] . ':initial');
        return $step;
    }

    private function cancelChildren(array $run): void
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('steps');
        $results = $wpdb->get_col($wpdb->prepare("SELECT result FROM {$table} WHERE run_id=%d AND result IS NOT NULL ORDER BY id LIMIT 1000", $run['id']));
        foreach ($results as $result) {
            $meta = Json::decode($result);
            if (!empty($meta['child_uuid'])) {
                $this->cancel($meta['child_uuid']);
            }
        }
    }

    public static function delayDue(array $config, int $now): int
    {
        if (isset($config['seconds'])) {
            return $now + (int) $config['seconds'];
        }
        $zone = new \DateTimeZone($config['timezone']);
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->modify('+' . $config['days'] . ' days');
        [$hour, $minute] = array_map('intval', explode(':', $config['at']));
        $due = self::calendarInstant($local, $hour, $minute);
        if ($due->getTimestamp() <= $now) {
            $due = self::calendarInstant($local->modify('+1 day'), $hour, $minute);
        }
        return $due->getTimestamp();
    }

    private static function calendarInstant(\DateTimeImmutable $day, int $hour, int $minute): \DateTimeImmutable
    {
        $due = $day->setTime($hour, $minute);
        $requested = sprintf('%02d:%02d', $hour, $minute);
        if ($due->format('H:i') === $requested) {
            return $due;
        } // PHP 8.3 chooses the earlier duplicate occurrence.
        $nominal = (new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $requested . ':00', new \DateTimeZone('UTC')))->getTimestamp();
        $transitions = $day->getTimezone()->getTransitions($due->getTimestamp() - 86400, $due->getTimestamp() + 86400);
        $previous = null;
        foreach ($transitions ?: [] as $transition) {
            if ($previous !== null && $transition['offset'] > $previous && $nominal >= $transition['ts'] + $previous && $nominal < $transition['ts'] + $transition['offset']) {
                return (new \DateTimeImmutable('@' . $transition['ts']))->setTimezone($day->getTimezone());
            }
            $previous = $transition['offset'];
        }
        return $due;
    }

    private function wait(array $job, array $run, array $step, array $meta, int $seconds): void
    {
        $this->database->transaction(function () use ($job, $run, $step, $meta, $seconds): void {
            $live = $this->lockRun($run['uuid']);
            $this->fence($job, $live, $run);
            $current = $this->database->get('steps', $step['uuid']);
            if (in_array($current['state'], ['succeeded', 'cancelled'], true)) {
                return;
            }
            $freshMeta = empty($current['result']) ? [] : Json::decode($current['result']);
            $meta = array_replace($meta, $freshMeta); // Preserve arrivals/wake facts committed since evaluation.
            $this->database->update('steps', $step['uuid'], ['state' => 'waiting', 'due_at' => gmdate('Y-m-d H:i:s', time() + $seconds), 'result' => Json::encode($meta)], (int) $current['row_version']);
            $this->queue->enqueue('automation_step', ['run_uuid' => $run['uuid'], 'step_uuid' => $step['uuid']], 'step:' . $step['uuid'] . ':wake:' . ($meta['due'] ?? (time() + $seconds)), $seconds);
        });
    }

    private function finish(array $job, array $run, array $step, array $body, array $node, array $meta, string $outcome, array $output): void
    {
        $this->database->transaction(function () use ($job, $run, $step, $body, $node, $meta, $outcome, $output): void {
            $live = $this->lockRun($run['uuid']);
            $this->fence($job, $live, $run);
            $current = $this->database->get('steps', $step['uuid']);
            if ($current['state'] === 'succeeded') {
                return;
            }
            $freshMeta = empty($current['result']) ? [] : Json::decode($current['result']);
            $meta = array_replace($meta, $freshMeta);
            if ($node['type'] === 'wait' && !empty($meta['wake_event'])) {
                $outcome = 'event';
            }
            $meta['output'] = $output;
            $meta['outcome'] = $outcome;
            $this->database->update('steps', $step['uuid'], ['state' => 'succeeded', 'result' => Json::encode($meta)], (int) $current['row_version']);
            if ($node['type'] !== 'exit') {
                $matched = array_values(array_filter($body['edges'], static fn(array $edge): bool => $edge['from'] === $node['id'] && ($node['type'] === 'split' || ($edge['outcome'] ?? 'success') === $outcome)));
                if ($matched === []) {
                    throw new \DomainException('Execution outcome has no configured edge.');
                }
                foreach ($matched as $edge) {
                    $nextMeta = ['lineage' => $meta['lineage'], 'splits' => $meta['splits'] ?? []];
                    if ($node['type'] === 'split') {
                        $split = ['parent' => $meta['lineage'], 'branch' => $edge['to'], 'expected' => array_column($matched, 'to')];
                        $nextMeta['splits'][$node['id']] = $split;
                        $nextMeta['lineage'] .= '/' . $node['id'] . '/' . $edge['to'];
                    }
                    $nextNode = null;
                    foreach ($body['nodes'] as $n) {
                        if ($n['id'] === $edge['to']) {
                            $nextNode = $n;
                            break;
                        }
                    }
                    if ($nextNode['type'] === 'join') {
                        $splitKey = $nextNode['config']['split'];
                        $split = $nextMeta['splits'][$splitKey] ?? throw new \DomainException('Join is outside its split branch.');
                        $joinMeta = $nextMeta;
                        $joinMeta['lineage'] = $split['parent'] . '/join/' . $nextNode['id'];
                        $join = $this->activate($live, $nextNode['id'], $joinMeta);
                        $joinMeta = Json::decode($join['result']);
                        $joinMeta['arrivals'][$split['branch']] = true;
                        $this->database->update('steps', $join['uuid'], ['result' => Json::encode($joinMeta)], (int) $join['row_version']);
                    } else {
                        $this->activate($live, $edge['to'], $nextMeta);
                    }
                }
            }
            $wpdb = $this->database->db();
            $steps = $this->database->table('steps');
            $active = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$steps} WHERE run_id=%d AND state IN ('ready','running','waiting')", $run['id']));
            if ($active === 0) {
                $this->database->update('runs', $run['uuid'], ['state' => 'completed'], (int) $live['row_version']);
                $this->audit->record('automation.completed', $run['uuid']);
            }
        });
    }

    private function joinReady(array $run, array $node, array $meta): bool
    {
        $split = $meta['splits'][$node['config']['split']] ?? null;
        if (!$split) {
            throw new \DomainException('Join has no split lineage.');
        }
        foreach ($split['expected'] as $branch) {
            if (empty($meta['arrivals'][$branch])) {
                return false;
            }
        }
        return true;
    }

    private function wakeWaiters(array $event, int $cursor): void
    {
        $wpdb = $this->database->db();
        $steps = $this->database->table('steps');
        $runs = $this->database->table('runs');
        $subject = empty($event['profile_id']) ? 'r.profile_id IS NULL' : $wpdb->prepare('r.profile_id=%d', $event['profile_id']);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT s.uuid,s.id,r.uuid AS run_uuid FROM {$steps} s INNER JOIN {$runs} r ON r.id=s.run_id WHERE s.state='waiting' AND r.state IN ('running','waiting') AND {$subject} AND s.id>%d ORDER BY s.id LIMIT 100", $cursor), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $step = $this->database->get('steps', $row['uuid']);
            $meta = Json::decode($step['result']);
            if (($meta['event'] ?? null) === $event['name'] && $event['occurred_at'] >= ($meta['started_at'] ?? '') && strtotime($event['occurred_at'] . ' UTC') <= (int) $meta['due']) {
                $this->database->transaction(function () use ($row, $event): void {
                    $run = $this->lockRun($row['run_uuid']);
                    $step = $this->database->get('steps', $row['uuid']);
                    if ($step['state'] !== 'waiting' || !in_array($run['state'], ['running', 'waiting'], true)) {
                        return;
                    }
                    $meta = Json::decode($step['result']);
                    if (!empty($meta['wake_event'])) {
                        return;
                    }
                    $meta['wake_event'] = $event['uuid'];
                    $this->database->update('steps', $step['uuid'], ['result' => Json::encode($meta)], (int) $step['row_version']);
                    $this->queue->enqueue('automation_step', ['run_uuid' => $run['uuid'], 'step_uuid' => $step['uuid']], 'step:' . $step['uuid'] . ':event:' . $event['uuid']);
                });
            }
        }
        if (count($rows) === 100) {
            $next = (int) end($rows)['id'];
            $this->queue->enqueue('automation_wait_route', ['event_uuid' => $event['uuid'], 'cursor' => $next], 'wait-route:' . $event['uuid'] . ':' . $next);
        }
    }

    private function catchUpWait(array $run, array $step, array $meta): void
    {
        $wpdb = $this->database->db();
        $events = $this->database->table('events');
        $subject = empty($run['profile_id']) ? 'profile_id IS NULL' : $wpdb->prepare('profile_id=%d', $run['profile_id']);
        $uuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$events} WHERE name=%s AND {$subject} AND occurred_at>=%s AND occurred_at<=%s ORDER BY occurred_at,id LIMIT 1", $meta['event'], $meta['started_at'], gmdate('Y-m-d H:i:s', (int) $meta['due'])));
        $event = $uuid ? $this->database->get('events', $uuid) : null;
        if ($event) {
            $this->wakeWaiters($event, 0);
        }
    }

    private function lockRun(string $uuid): array
    {
        $wpdb = $this->database->db();
        $runs = $this->database->table('runs');
        $locked = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$runs} WHERE uuid=%s FOR UPDATE", $uuid));
        return $locked ? $this->database->get('runs', $uuid) : throw new \DomainException('Run not found.');
    }

    private function fence(array $job, array $live, array $original): void
    {
        if (!$this->enabled()) {
            throw new DeferredException('Automation module is disabled.');
        }
        $currentJob = $this->database->get('jobs', $job['uuid']);
        if (!$currentJob || $currentJob['state'] !== 'running' || $currentJob['lease_token'] !== $job['lease_token'] || !in_array($live['state'], ['pending', 'running', 'waiting'], true) || (int) $live['cancel_epoch'] !== (int) $original['cancel_epoch']) {
            throw new \DomainException('Execution lease or run epoch changed.');
        }
        $definition = $this->database->find('definitions', 'id', (int) $live['definition_id']);
        if ($definition && $definition['state'] === 'paused') {
            throw new DeferredException('Automation is paused.');
        }
        if (!$definition || $definition['state'] !== 'enabled' || (int) $definition['cancel_epoch'] !== (int) $live['cancel_epoch']) {
            throw new \DomainException('Automation policy changed.');
        }
        if (!empty($live['profile_id'])) {
            $profile = $this->database->find('profiles', 'id', (int) $live['profile_id']);
            $context = Json::decode($live['context']);
            if (!$profile || $profile['state'] !== 'active' || (int) $profile['erasure_epoch'] !== (int) ($context['erasure_epoch'] ?? 0)) {
                throw new \DomainException('Profile privacy epoch changed.');
            }
        }
    }
    private function enabled(): bool
    {
        return in_array('automations', get_option('wmos_settings', [])['enabled_modules'] ?? [], true);
    }
}
