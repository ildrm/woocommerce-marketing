<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

/** Prepared access to plugin-owned tables. Canonical WooCommerce storage is never queried here. */
final class Database
{
    private int $depth = 0;

    public function __construct(private readonly \wpdb $connection)
    {
    }

    public function db(): \wpdb
    {
        return $this->connection;
    }

    public function table(string $name): string
    {
        if (!isset(Schema::tables()[$name]) || !preg_match('/^[a-zA-Z0-9_]+$/D', $this->connection->prefix)) {
            throw new ValidationException('Unknown table.');
        }
        return $this->connection->prefix . 'wmos_' . $name;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function insert(string $table, array $data): array
    {
        $this->validateColumns($table, array_keys($data));
        $now = self::now();
        $data += ['uuid' => self::uuid(), 'created_at' => $now, 'updated_at' => $now, 'row_version' => 1];
        if (false === $this->connection->insert($this->table($table), $data)) {
            $this->failure('Unable to persist record.');
        }
        return $this->find($table, 'id', (int) $this->connection->insert_id) ?? throw new DatabaseException('Record could not be read.');
    }

    public function get(string $table, string $uuid): ?array
    {
        return $this->find($table, 'uuid', $uuid);
    }

    public function find(string $table, string $field, string|int $value): ?array
    {
        $this->validateColumns($table, [$field]);
        $columns = $this->projection($table);
        $sql = $this->connection->prepare("SELECT {$columns} FROM `{$this->table($table)}` WHERE `{$field}` = %s LIMIT 1", $value);
        $row = $this->connection->get_row($sql, ARRAY_A);
        $this->check();
        return is_array($row) ? $row : null;
    }

    public function list(string $table, array $filters = [], int $limit = 25, int $afterId = 0): array
    {
        $this->validateColumns($table, array_keys($filters));
        $where = ['id > %d'];
        $args = [max(0, $afterId)];
        foreach ($filters as $field => $value) {
            if (null === $value) {
                $where[] = "`{$field}` IS NULL";
            } else {
                $where[] = "`{$field}` = %s";
                $args[] = $value;
            }
        }
        $args[] = min(1000, max(1, $limit));
        $sql = "SELECT {$this->projection($table)} FROM `{$this->table($table)}` WHERE " . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT %d';
        $rows = $this->connection->get_results($this->connection->prepare($sql, $args), ARRAY_A);
        $this->check();
        return is_array($rows) ? $rows : [];
    }

    public function update(string $table, string $uuid, array $data, ?int $expectedVersion = null): array
    {
        $this->validateColumns($table, array_keys($data));
        if (array_intersect(['id', 'uuid', 'created_at', 'row_version'], array_keys($data))) {
            throw new ValidationException('Immutable record fields cannot be changed.');
        }
        $data['updated_at'] = self::now();
        $sets = ['row_version = row_version + 1'];
        $args = [];
        foreach ($data as $field => $value) {
            $sets[] = null === $value ? "`{$field}` = NULL" : "`{$field}` = %s";
            if (null !== $value) {
                $args[] = $value;
            }
        }
        $args[] = $uuid;
        $where = 'uuid = %s';
        if (null !== $expectedVersion) {
            $where .= ' AND row_version = %d';
            $args[] = $expectedVersion;
        }
        $sql = "UPDATE `{$this->table($table)}` SET " . implode(', ', $sets) . " WHERE {$where}";
        $changed = $this->connection->query($this->connection->prepare($sql, $args));
        $this->check();
        if (1 !== $changed) {
            throw new ConflictException('Record changed or was removed. Reload and try again.');
        }
        return $this->get($table, $uuid) ?? throw new DatabaseException('Record could not be read.');
    }

    public function delete(string $table, string $uuid): void
    {
        if (false === $this->connection->delete($this->table($table), ['uuid' => $uuid])) {
            $this->failure('Unable to remove record.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        $level = $this->depth++;
        $savepoint = 'wmos_' . $level;
        try {
            $this->execute(0 === $level ? 'START TRANSACTION' : "SAVEPOINT {$savepoint}");
            $result = $callback();
            $this->execute(0 === $level ? 'COMMIT' : "RELEASE SAVEPOINT {$savepoint}");
            return $result;
        } catch (\Throwable $error) {
            $this->connection->query(0 === $level ? 'ROLLBACK' : "ROLLBACK TO SAVEPOINT {$savepoint}");
            throw $error;
        } finally {
            --$this->depth;
        }
    }

    private function execute(string $sql): void
    {
        if (false === $this->connection->query($sql)) {
            $this->failure('Database transaction failed.');
        }
    }

    private function projection(string $table): string
    {
        return implode(', ', array_map(static fn(string $column): string => "`{$column}`", array_keys(Schema::columns($table))));
    }

    private function validateColumns(string $table, array $fields): void
    {
        $columns = Schema::columns($table);
        foreach ($fields as $field) {
            if (!is_string($field) || !isset($columns[$field])) {
                throw new ValidationException('Unknown record field.');
            }
        }
    }

    private function check(): void
    {
        if ('' !== $this->connection->last_error) {
            $this->failure('Database operation failed.');
        }
    }

    private function failure(string $message): never
    {
        if (preg_match('/^(Deadlock found|Lock wait timeout exceeded)/i', $this->connection->last_error)) {
            // The transaction unwinds before the durable worker applies bounded backoff.
            throw new ConflictException('Database is busy. Please retry.');
        }
        throw new DatabaseException($message);
    }
}
