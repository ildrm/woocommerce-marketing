<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

/** Action Scheduler wakes workers; durable plugin jobs remain the source of truth. */
final class Queue
{
    private array $handlers = [];

    public function __construct(private readonly Database $database, private readonly Audit $audit)
    {
    }

    public function register(string $kind, callable $handler): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $kind)) {
            throw new ValidationException('Invalid handler identity.');
        }
        if (isset($this->handlers[$kind])) {
            throw new ConflictException('Job handler already registered.');
        }
        $this->handlers[$kind] = $handler;
    }

    public function enqueue(string $kind, array $payload, string $operationKey, int $delaySeconds = 0): array
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $kind) || $operationKey === '') {
            throw new ValidationException('Invalid job identity.');
        }
        if (strlen(Json::encode($payload)) > 16384) {
            throw new ValidationException('Job payload exceeds the resource limit.');
        }
        $key = hash('sha256', $kind . ':' . $operationKey);
        $existing = $this->database->find('jobs', 'operation_key', $key);
        if ($existing) {
            if ($existing['payload'] !== Json::encode($payload)) {
                throw new ConflictException('Job idempotency key was reused with a different payload.');
            } return $existing;
        }
        try {
            $row = $this->database->insert('jobs', ['kind' => $kind,'operation_key' => $key,'payload' => Json::encode($payload),'state' => 'pending','available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds))]);
        } catch (DatabaseException $error) {
            $row = $this->database->find('jobs', 'operation_key', $key);
            if (!$row) {
                throw $error;
            }
            if ($row['payload'] !== Json::encode($payload)) {
                throw new ConflictException('Job idempotency key was reused with a different payload.');
            }
        }
        return $row;
    }

    public function tick(): void
    {
        if (!(bool) get_option('wmos_active', false) || (int) get_option('wmos_schema_version', 0) !== Schema::VERSION) {
            return;
        }
        $started = microtime(true);
        for ($count = 0; $count < 20 && microtime(true) - $started < 5; ++$count) {
            $job = $this->claim();
            if (!$job) {
                break;
            }
            $this->perform($job);
        }
        update_option('wmos_worker_heartbeat', Database::now(), false);
    }

    public function retry(string $uuid): array
    {
        $job = $this->database->get('jobs', $uuid) ?? throw new ValidationException('Job not found.');
        if (!in_array($job['state'], ['failed','cancelled'], true)) {
            throw new ConflictException('This outcome cannot be blindly retried.');
        }
        $row = $this->database->update('jobs', $uuid, ['state' => 'pending','attempts' => 0,'lease_token' => null,'lease_until' => null,'last_error' => null,'available_at' => Database::now()], (int) $job['row_version']);
        $this->audit->record('queue.retried', $uuid, ['kind' => $job['kind']]);
        return $row;
    }

    public function cancel(string $uuid): array
    {
        $job = $this->database->get('jobs', $uuid) ?? throw new ValidationException('Job not found.');
        if (!in_array($job['state'], ['pending','failed'], true)) {
            throw new ConflictException('Running or ambiguous external work requires reconciliation.');
        }
        $row = $this->database->update('jobs', $uuid, ['state' => 'cancelled'], (int) $job['row_version']);
        $this->audit->record('queue.cancelled', $uuid);
        return $row;
    }

    private function claim(): ?array
    {
        return $this->database->transaction(function (): ?array {
            $db = $this->database->db();
            $table = $this->database->table('jobs');
            if (!$this->handlers) {
                return null;
            }
            $kinds = implode(',', array_fill(0, count($this->handlers), '%s'));
            $row = $db->get_row($db->prepare("SELECT uuid, row_version FROM `{$table}` WHERE ((state = 'pending' AND available_at <= %s) OR (state = 'running' AND lease_until < %s)) AND kind IN ({$kinds}) ORDER BY available_at, id LIMIT 1 FOR UPDATE", Database::now(), Database::now(), ...array_keys($this->handlers)), ARRAY_A);
            if (!$row) {
                return null;
            }
            $job = $this->database->get('jobs', $row['uuid']);
            if (!$job) {
                return null;
            }
            if ((int) $job['attempts'] > 8 || ($job['expires_at'] && $job['expires_at'] <= Database::now())) {
                $this->database->update('jobs', $job['uuid'], ['state' => 'failed','last_error' => 'retry_limit'], (int) $job['row_version']);
                return null;
            }
            return $this->database->update('jobs', $job['uuid'], ['state' => 'running','attempts' => (int)$job['attempts'] + 1,'lease_token' => bin2hex(random_bytes(32)),'lease_until' => gmdate('Y-m-d H:i:s', time() + 120)], (int)$job['row_version']);
        });
    }

    private function perform(array $job): void
    {
        try {
            if (!isset($this->handlers[$job['kind']])) {
                throw new ValidationException('Job handler is unavailable.');
            }
            ($this->handlers[$job['kind']])($job, Json::decode($job['payload']));
            $this->finish($job, 'completed');
        } catch (AmbiguousException $error) {
            $this->finish($job, 'ambiguous', 'submission_outcome_unknown');
        } catch (DeferredException $error) {
            $this->finish($job, 'pending', 'policy_deferred', min(86400, max(1, $error->delay)), true);
        } catch (RetryableException $error) {
            $delay = min(86400, max(1, $error->delay));
            $this->finish($job, (int)$job['attempts'] < 9 ? 'pending' : 'failed', 'temporary_failure', $delay);
        } catch (ConflictException $error) {
            $this->finish($job, (int)$job['attempts'] < 9 ? 'pending' : 'failed', 'concurrent_revision', min(300, 2 ** (int)$job['attempts']));
        } catch (\Throwable $error) {
            $this->finish($job, 'failed', 'handler_failed');
            $this->audit->record('queue.failed', $job['uuid'], ['kind' => $job['kind'],'error_class' => get_class($error)]);
        }
    }

    private function finish(array $job, string $state, ?string $error = null, int $delay = 0, bool $deferred = false): void
    {
        $db = $this->database->db();
        $table = $this->database->table('jobs');
        $attemptSql = $deferred ? ', attempts=GREATEST(0,attempts-1)' : '';
        $changed = $db->query($db->prepare("UPDATE `{$table}` SET state = %s, last_error = %s, available_at = %s, lease_until = NULL, lease_token = NULL, row_version = row_version + 1, updated_at = %s {$attemptSql} WHERE uuid = %s AND state = 'running' AND lease_token = %s", $state, $error ?? '', gmdate('Y-m-d H:i:s', time() + $delay), Database::now(), $job['uuid'], $job['lease_token']));
        if (false === $changed) {
            throw new DatabaseException('Could not complete job.');
        }
    }
}
