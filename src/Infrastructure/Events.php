<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

final class Events
{
    private array $consumers = [];

    public function __construct(private readonly Database $database, private readonly Queue $queue)
    {
        $queue->register('event.route', [$this, 'route']);
    }

    public function subscribe(string $key, callable $consumer): void
    {
        if (isset($this->consumers[$key])) {
            throw new ConflictException('Event consumer already registered.');
        }
        $this->consumers[$key] = $consumer;
    }

    public function capture(string $name, string $source, string $sourceKey, array $properties = [], ?string $profileUuid = null, ?array $object = null, ?string $occurredAt = null): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}$/D', $name) || !preg_match('/^[a-z0-9_.-]{1,64}$/D', $source)) {
            throw new ValidationException('Invalid event type or source.');
        }
        if (strlen(Json::encode($properties)) > 8192) {
            throw new ValidationException('Event properties exceed the resource limit.');
        }
        $key = hash('sha256', $sourceKey);
        $db = $this->database->db();
        $table = $this->database->table('events');
        $existing = $db->get_row($db->prepare("SELECT uuid FROM `{$table}` WHERE source = %s AND source_key = %s", $source, $key), ARRAY_A);
        if ($existing) {
            return $this->database->get('events', $existing['uuid']);
        }
        try {
            return $this->database->transaction(function () use ($name, $source, $key, $properties, $profileUuid, $object, $occurredAt): array {
                        $profile = $profileUuid ? $this->database->get('profiles', $profileUuid) : null;
                if ($profile && 'active' !== $profile['state']) {
                    throw new ValidationException('Contact is unavailable.');
                }
                $row = $this->database->insert('events', [
                'name' => $name,'source' => $source,'source_key' => $key,'profile_id' => $profile['id'] ?? null,
                'object_type' => $object['type'] ?? null,'object_id' => $object['id'] ?? null,
                'properties' => Json::encode($properties),'context' => Json::encode(['schema_version' => 1,'correlation_id' => Database::uuid(),'blog_id' => (string)get_current_blog_id()]),
                'occurred_at' => $occurredAt ?? Database::now(),
                ]);
                        $this->queue->enqueue('event.route', ['event_uuid' => $row['uuid']], $row['uuid']);
                        return $row;
            });
        } catch (DatabaseException $error) {
            $existing = $db->get_var($db->prepare("SELECT uuid FROM `{$table}` WHERE source=%s AND source_key=%s", $source, $key));
            if (!$existing) {
                throw $error;
            }
            return $this->database->get('events', (string)$existing);
        }
    }

    public function route(array $job, array $payload): void
    {
        $event = $this->database->get('events', (string)($payload['event_uuid'] ?? ''));
        if (!$event || $event['processed_at']) {
            return;
        }
        // Every consumer has a durable job key. One failure cannot rerun a completed consumer.
        foreach ($this->consumers as $key => $_consumer) {
            $this->queue->enqueue('event.consume.' . $key, ['event_uuid' => $event['uuid']], $event['uuid'] . ':' . $key);
        }
        $this->database->update('events', $event['uuid'], ['processed_at' => Database::now()], (int)$event['row_version']);
    }

    public function registerConsumers(): void
    {
        foreach ($this->consumers as $key => $consumer) {
            $this->queue->register('event.consume.' . $key, function (array $job, array $payload) use ($consumer): void {
                $event = $this->database->get('events', $payload['event_uuid']);
                if ($event) {
                    $consumer($event);
                }
            });
        }
    }
}
