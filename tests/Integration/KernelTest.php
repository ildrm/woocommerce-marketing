<?php

declare(strict_types=1);

namespace Wmos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Wmos\Infrastructure\{AmbiguousException, ConflictException, Database, Queue, Schema};
use Wmos\Platform\{Lifecycle, Plugin};

final class KernelTest extends TestCase
{
    private Database $database;
    private array $services;
    protected function setUp(): void
    {
        if (getenv('WMOS_INTEGRATION') !== '1') {
            self::markTestSkipped('Enable isolated WordPress integration.');
        } $this->database = Plugin::instance()->database();
        $this->services = Plugin::instance()->services();
        update_option('wmos_active', true, false);
    }
    public function testAllPluginTablesAreTransactionalAndInstalled(): void
    {
        $db = $this->database->db();
        foreach (array_keys(Schema::tables()) as $table) {
            $engine = $db->get_var($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $this->database->table($table)));
            self::assertSame('InnoDB', $engine, $table);
        }
    }
    public function testNestedRollbackDoesNotCommitPartOfAnOperation(): void
    {
        $email = 'rollback-' . bin2hex(random_bytes(6)) . '@example.test';
        try {
            $this->database->transaction(function () use ($email): void {
                $this->services['contacts']->create($email);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $hash = (new \Wmos\Infrastructure\Secrets())->hash($email, 'email');
        self::assertNull($this->database->find('profiles', 'email_hash', $hash));
    }
    public function testStaleRevisionCannotOverwriteData(): void
    {
        $row = $this->services['contacts']->create('cas-' . bin2hex(random_bytes(5)) . '@example.test');
        $this->services['contacts']->update($row['uuid'], [], ['one'], (int)$row['row_version']);
        $this->expectException(ConflictException::class);
        $this->services['contacts']->update($row['uuid'], [], ['two'], (int)$row['row_version']);
    }
    public function testDurableQueueIdempotencyAndBoundedExecution(): void
    {
        $queue = new Queue($this->database, $this->services['audit']);
        $kind = 'test.' . bin2hex(random_bytes(5));
        $calls = 0;
        $queue->register($kind, function () use (&$calls): void {
            ++$calls;
        });
        $first = $queue->enqueue($kind, ['value' => 1], 'same');
        $second = $queue->enqueue($kind, ['value' => 1], 'same');
        self::assertSame($first['uuid'], $second['uuid']);
        $queue->tick();
        self::assertSame(1, $calls);
        self::assertSame('completed', $this->database->get('jobs', $first['uuid'])['state']);
        $queue->tick();
        self::assertSame(1, $calls);
    }
    public function testAmbiguousExternalJobCannotBeBlindlyRetried(): void
    {
        $queue = new Queue($this->database, $this->services['audit']);
        $kind = 'test.' . bin2hex(random_bytes(5));
        $queue->register($kind, static function (): void {
            throw new AmbiguousException('Unknown');
        });
        $job = $queue->enqueue($kind, [], 'ambiguous');
        $queue->tick();
        self::assertSame('ambiguous', $this->database->get('jobs', $job['uuid'])['state']);
        $this->expectException(ConflictException::class);
        $queue->retry($job['uuid']);
    }
    public function testSemanticFactIsStoredAndRoutedOnce(): void
    {
        $events = $this->services['events'];
        $key = 'event:' . bin2hex(random_bytes(6));
        $first = $events->capture('test.fact', 'integration', $key, ['test' => true]);
        $second = $events->capture('test.fact', 'integration', $key, ['test' => true]);
        self::assertSame($first['uuid'], $second['uuid']);
        $route = $this->services['queue']->enqueue('event.route', ['event_uuid' => $first['uuid']], $first['uuid']);
        $this->database->update('jobs', $route['uuid'], ['available_at' => '2000-01-01 00:00:00']);
        $this->services['queue']->tick();
        self::assertSame('completed', $this->database->get('jobs', $route['uuid'])['state']);
        self::assertNotNull($this->database->get('events', $first['uuid'])['processed_at']);
    }
    public function testDeactivationStopsWorkersAndPreservesData(): void
    {
        $row = $this->services['contacts']->create('preserve-' . bin2hex(random_bytes(5)) . '@example.test');
        Lifecycle::deactivate();
        $before = $this->database->get('profiles', $row['uuid']);
        $this->services['queue']->tick();
        self::assertSame($before, $this->database->get('profiles', $row['uuid']));
        self::assertFalse((bool)get_option('wmos_active'));
        update_option('wmos_active', true, false);
    }
    public function testTrackingRejectsCrossOriginAndNonconsensualEvents(): void
    {
        $tracking = $this->services['tracking'];
        $request = new \WP_REST_Request('GET', '/wmos/v1/tracking/bootstrap');
        $request->set_header('Origin', 'https://attacker.example');
        try {
            $tracking->bootstrap($request);
            self::fail('Cross-origin challenge accepted');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $request = new \WP_REST_Request('POST', '/wmos/v1/tracking/events');
        $request->set_header('Origin', home_url());
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('{"events":[]}');
        unset($_COOKIE['wmos_preferences']);
        $this->expectException(\InvalidArgumentException::class);
        $tracking->events($request);
    }
    public function testShortLinksRejectExternalRedirects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->services['tracking']->create('https://attacker.example/');
    }

    public function testDatabaseLockTimeoutDefersJobAndRetryCompletesAfterRelease(): void
    {
        $profile = $this->services['contacts']->create('lock-' . bin2hex(random_bytes(6)) . '@example.test');
        $connection = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $connection->set_prefix($this->database->db()->prefix);
        $connection->suppress_errors(true);
        $connection->query('SET SESSION innodb_lock_wait_timeout=1');
        $other = new Database($connection);
        $queue = new Queue($this->database, $this->services['audit']);
        $kind = 'test.lock.' . bin2hex(random_bytes(5));
        $queue->register($kind, function () use ($other, $profile): void {
            $other->update('profiles', $profile['uuid'], ['tags' => '["contended"]']);
        });
        $job = $queue->enqueue($kind, [], 'lock:' . $profile['uuid']);
        try {
            $this->database->transaction(function () use ($profile, $queue, $job): void {
                $db = $this->database->db();
                $db->get_var($db->prepare('SELECT id FROM ' . $this->database->table('profiles') . ' WHERE uuid=%s FOR UPDATE', $profile['uuid']));
                $queue->tick();
                $pending = $this->database->get('jobs', $job['uuid']);
                self::assertSame('pending', $pending['state']);
                self::assertSame('concurrent_revision', $pending['last_error']);
                self::assertSame(1, (int)$pending['attempts']);
                self::assertSame('[]', $this->database->get('profiles', $profile['uuid'])['tags']);
            });
            $this->database->update('jobs', $job['uuid'], ['available_at' => '2000-01-01 00:00:00']);
            $queue->tick();
            self::assertSame('completed', $this->database->get('jobs', $job['uuid'])['state']);
            self::assertSame('["contended"]', $this->database->get('profiles', $profile['uuid'])['tags']);
        } finally {
            $connection->close();
        }
    }
}
