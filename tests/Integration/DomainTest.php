<?php

declare(strict_types=1);

namespace Wmos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;
use Wmos\Platform\Plugin;

/** Runs against the real plugin tables and WooCommerce CRUD loaded by the WP bootstrap. */
final class DomainTest extends TestCase
{
    private array $services;
    private Database $database;
    private int $fixtureStart;
    private array $previousSettings;

    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !class_exists(Plugin::class) || !class_exists('WC_Order')) {
            if (getenv('WMOS_INTEGRATION') === '1') {
                self::fail('WordPress/WooCommerce integration bootstrap did not initialize.');
            }
            self::markTestSkipped('WordPress/WooCommerce integration bootstrap is required.');
        }
        $this->services = Plugin::instance()->services();
        $this->database = Plugin::instance()->database();
        $this->fixtureStart = (int)$this->database->db()->get_var('SELECT COALESCE(MAX(id),0) FROM ' . $this->database->table('definitions'));
        $settings = get_option('wmos_settings', []);
        $this->previousSettings = $settings;
        $settings['enabled_modules'] = array_values(array_unique(array_merge($settings['enabled_modules'] ?? [], ['campaigns', 'automations', 'segments', 'experiments', 'recommendations', 'personalization'])));
        update_option('wmos_settings', $settings, false);
    }

    protected function tearDown(): void
    {
        if (!isset($this->services)) {
            return;
        }
        $db = $this->database->db();
        $definitions = $this->database->table('definitions');
        $db->query($db->prepare("UPDATE {$definitions} SET state='paused',row_version=row_version+1 WHERE id>%d AND state IN ('enabled','active','running','rebuilding')", $this->fixtureStart));
        update_option('wmos_settings', $this->previousSettings, false);
    }

    private function profile(): array
    {
        return $this->services['contacts']->create('domain-' . bin2hex(random_bytes(6)) . '@example.test');
    }

    private function drain(array $run): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $processed = false;
            foreach ($this->database->list('steps', ['run_id' => $run['id']], 100) as $step) {
                if ($this->stepJob($run, $step)) {
                    $this->executeStep($run, $step['node_key']);
                    $processed = true;
                }
            }
            if (!$processed) {
                return;
            }
        }
        self::fail('Workflow exceeded the bounded integration drain.');
    }

    public function testPublicationCreatesImmutableHistoricalVersion(): void
    {
        $definitions = $this->services['definitions'];
        $row = $definitions->create('segment', 'Integration segment', ['rule' => ['field' => 'order_count', 'operator' => 'gte', 'value' => 0]]);
        $published = $definitions->publish($row['uuid'], (int) $row['row_version']);
        $versionId = (int) $published['published_version_id'];
        $original = $this->database->find('definition_versions', 'id', $versionId);
        $saved = $definitions->save($row['uuid'], ['rule' => ['field' => 'order_count', 'operator' => 'gte', 'value' => 5]], (int) $published['row_version']);
        $definitions->publish($row['uuid'], (int) $saved['row_version']);
        self::assertSame($original['body'], $this->database->find('definition_versions', 'id', $versionId)['body']);
        self::assertSame(0, Json::decode($original['body'])['rule']['value']);
    }

    public function testMaterializationPublishesCompleteGeneration(): void
    {
        $user = wp_create_user('wmos-segment-' . bin2hex(random_bytes(4)), wp_generate_password(30), 'segment-' . bin2hex(random_bytes(4)) . '@example.test');
        $profile = $this->services['contacts']->create('member-' . bin2hex(random_bytes(4)) . '@example.test', $user, true);
        $definitions = $this->services['definitions'];
        $row = $definitions->create('segment', 'Integration generation', ['rule' => ['field' => 'user_id', 'operator' => 'eq', 'value' => $user]]);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $job = $this->services['segments']->rebuild($row['uuid']);
        self::assertSame(0, (int) $this->database->get('definitions', $row['uuid'])['generation']);
        $this->services['segments']->process($job, Json::decode($job['payload']));
        $segment = $this->database->get('definitions', $row['uuid']);
        self::assertSame(1, (int) $segment['generation']);
        self::assertSame((int) $segment['published_version_id'], (int) $segment['materialized_version_id']);
        self::assertSame('active', $segment['state']);
        $raw = $this->database->get('profiles', $profile['uuid']);
        $members = $this->database->list('memberships', ['definition_id' => $segment['id'], 'generation' => 1, 'profile_id' => $raw['id']]);
        self::assertCount(1, $members);
    }

    public function testRepublishedSegmentMustRebuildBeforeCampaignStarts(): void
    {
        $definitions = $this->services['definitions'];
        $row = $definitions->create('segment', 'Version-pinned audience', ['rule' => ['field' => 'user_id', 'operator' => 'eq', 'value' => PHP_INT_MAX]]);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $job = $this->services['segments']->rebuild($row['uuid']);
        $this->services['segments']->process($job, Json::decode($job['payload']));
        $segment = $this->database->get('definitions', $row['uuid']);
        $oldVersion = (int) $segment['materialized_version_id'];
        $saved = $definitions->save($row['uuid'], ['rule' => ['field' => 'user_id', 'operator' => 'eq', 'value' => PHP_INT_MAX - 1]], (int) $segment['row_version']);
        $definitions->publish($row['uuid'], (int) $saved['row_version']);
        $campaign = $definitions->create('campaign', 'Audience guard', ['audience' => ['segment_uuid' => $row['uuid']]]);
        $published = $definitions->publish($campaign['uuid'], (int) $campaign['row_version']);
        try {
            $definitions->transition($campaign['uuid'], 'running', (int) $published['row_version']);
            self::fail('A stale segment generation must not start a campaign.');
        } catch (\DomainException) {
            self::assertSame('draft', $this->database->get('definitions', $campaign['uuid'])['state']);
        }
        $job = $this->services['segments']->rebuild($row['uuid']);
        $this->services['segments']->process($job, Json::decode($job['payload']));
        $segment = $this->database->get('definitions', $row['uuid']);
        self::assertSame(2, (int) $segment['generation']);
        self::assertNotSame($oldVersion, (int) $segment['materialized_version_id']);
        self::assertSame('running', $definitions->transition($campaign['uuid'], 'running', (int) $published['row_version'])['state']);
        $paused = $definitions->transition($campaign['uuid'], 'paused', (int) $this->database->get('definitions', $campaign['uuid'])['row_version']);
        self::assertSame('running', $definitions->transition($campaign['uuid'], 'running', (int) $paused['row_version'])['state']);
    }

    private function executeStep(array $run, string $nodeKey, ?\Wmos\Application\Automation $automation = null): void
    {
        $step = array_values(array_filter($this->database->list('steps', ['run_id' => $run['id']], 100), static fn(array $row): bool => $row['node_key'] === $nodeKey))[0];
        $job = $this->stepJob($run, $step);
        self::assertNotNull($job, 'A durable due job must exist for the step.');
        $job = $this->database->update('jobs', $job['uuid'], ['state' => 'running', 'lease_token' => bin2hex(random_bytes(32)), 'lease_until' => gmdate('Y-m-d H:i:s', time() + 120)], (int) $job['row_version']);
        ($automation ?? $this->services['automation'])->process($job, Json::decode($job['payload']));
        $live = $this->database->get('jobs', $job['uuid']);
        $this->database->update('jobs', $job['uuid'], ['state' => 'completed', 'lease_token' => null, 'lease_until' => null], (int) $live['row_version']);
    }

    private function stepJob(array $run, array $step): ?array
    {
        $wpdb = $this->database->db();
        $jobs = $this->database->table('jobs');
        $payload = Json::encode(['run_uuid' => $run['uuid'], 'step_uuid' => $step['uuid']]);
        $uuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$jobs} WHERE kind='automation_step' AND state='pending' AND available_at<=%s AND payload=%s ORDER BY available_at,id LIMIT 1", Database::now(), $payload));
        return $uuid ? $this->database->get('jobs', $uuid) : null;
    }

    public function testPausedAutomationDefersWithoutFailingItsRun(): void
    {
        $definitions = $this->services['definitions'];
        $row = $definitions->create('automation', 'Paused workflow', ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'end']]]);
        $published = $definitions->publish($row['uuid'], (int) $row['row_version']);
        $run = $this->services['automation']->enter($row['uuid'], $this->profile()['uuid'], null, 'paused-entry');
        $definitions->transition($row['uuid'], 'paused', (int) $published['row_version']);
        try {
            $this->executeStep($run, 'start');
            self::fail('Paused work must defer.');
        } catch (\Wmos\Infrastructure\DeferredException) {
            self::assertSame('running', $this->database->get('runs', $run['uuid'])['state']);
        }
        $this->services['automation']->cancel($run['uuid']);
    }

    public function testPermanentActionFailureTerminatesRunAndPendingSteps(): void
    {
        $audit = new \Wmos\Infrastructure\Audit($this->database);
        $queue = new \Wmos\Infrastructure\Queue($this->database, $audit);
        $automation = new \Wmos\Application\Automation($this->database, $this->services['definitions'], $queue, $audit);
        $automation->setActions(['tag' => static function (): array {
            throw new \DomainException('Permanent action rejection.');
        }]);
        $definitions = $this->services['definitions'];
        $row = $definitions->create('automation', 'Failing workflow', ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'tag', 'type' => 'action', 'config' => ['action' => 'tag', 'tag' => 'test']], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'tag'], ['from' => 'tag', 'to' => 'end']]]);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $run = $automation->enter($row['uuid'], $this->profile()['uuid'], null, 'failure-entry');
        $this->executeStep($run, 'start', $automation);
        try {
            $this->executeStep($run, 'tag', $automation);
            self::fail('Permanent action failure must propagate.');
        } catch (\DomainException) {
            self::assertSame('failed', $this->database->get('runs', $run['uuid'])['state']);
        }
        self::assertSame('failed', $this->database->list('steps', ['run_id' => $run['id']], 100)[1]['state']);
    }

    public function testParentCancellationAlsoCancelsPinnedChildWorkflow(): void
    {
        $definitions = $this->services['definitions'];
        $child = $definitions->create('automation', 'Child workflow', ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'delay', 'type' => 'delay', 'config' => ['seconds' => 60]], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'delay'], ['from' => 'delay', 'to' => 'end']]]);
        $definitions->publish($child['uuid'], (int) $child['row_version']);
        $parent = $definitions->create('automation', 'Parent workflow', ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'child', 'type' => 'subworkflow', 'config' => ['definition_uuid' => $child['uuid']]], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'child'], ['from' => 'child', 'to' => 'end'], ['from' => 'child', 'to' => 'end', 'outcome' => 'failure']]]);
        $definitions->publish($parent['uuid'], (int) $parent['row_version']);
        $run = $this->services['automation']->enter($parent['uuid'], $this->profile()['uuid'], null, 'parent-entry');
        $this->executeStep($run, 'start');
        $this->executeStep($run, 'child');
        $childStep = $this->database->list('steps', ['run_id' => $run['id']], 100)[1];
        $childUuid = Json::decode($childStep['result'])['child_uuid'];
        self::assertSame('running', $this->database->get('runs', $childUuid)['state']);
        $this->services['automation']->cancel($run['uuid']);
        self::assertSame('cancelled', $this->database->get('runs', $childUuid)['state']);
    }

    public function testDuplicateEntryExecutesOnePinnedRun(): void
    {
        $profile = $this->profile();
        $definitions = $this->services['definitions'];
        $body = ['trigger' => ['event' => 'test.domain'], 'nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'end']]];
        $row = $definitions->create('automation', 'Integration duplicate entry', $body);
        $published = $definitions->publish($row['uuid'], (int) $row['row_version']);
        $run = $this->services['automation']->enter($row['uuid'], $profile['uuid'], null, 'same-fact');
        $repeat = $this->services['automation']->enter($row['uuid'], $profile['uuid'], null, 'same-fact');
        self::assertSame($run['uuid'], $repeat['uuid']);
        self::assertSame((int) $published['published_version_id'], (int) $run['version_id']);
        $this->drain($run);
        self::assertSame('completed', $this->database->get('runs', $run['uuid'])['state']);
        self::assertCount(2, $this->database->list('steps', ['run_id' => $run['id']], 100));
    }

    public function testCancellationFencesQueuedSteps(): void
    {
        $profile = $this->profile();
        $definitions = $this->services['definitions'];
        $body = ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'end']]];
        $row = $definitions->create('automation', 'Integration cancel', $body);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $run = $this->services['automation']->enter($row['uuid'], $profile['uuid'], null, 'cancel-fact');
        $this->services['automation']->cancel($run['uuid']);
        $this->drain($run);
        self::assertSame('cancelled', $this->database->get('runs', $run['uuid'])['state']);
        self::assertSame('cancelled', $this->database->list('steps', ['run_id' => $run['id']], 100)[0]['state']);
    }

    public function testWaitWakesExactlyOnceAfterDuplicateEventRouting(): void
    {
        $profile = $this->profile();
        $definitions = $this->services['definitions'];
        $eventName = 'test.wait.' . bin2hex(random_bytes(4));
        $body = ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'wait', 'type' => 'wait', 'config' => ['event' => $eventName, 'timeout' => 60]], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'wait'], ['from' => 'wait', 'to' => 'end', 'outcome' => 'event'], ['from' => 'wait', 'to' => 'end', 'outcome' => 'timeout']]];
        $row = $definitions->create('automation', 'Integration wait', $body);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $run = $this->services['automation']->enter($row['uuid'], $profile['uuid'], null, 'wait-fact');
        $this->drain($run);
        $steps = $this->database->list('steps', ['run_id' => $run['id']], 100);
        self::assertSame('waiting', $steps[1]['state']);
        $raw = $this->database->get('profiles', $profile['uuid']);
        $event = $this->database->insert('events', ['name' => $eventName, 'source' => 'domain_test', 'source_key' => hash('sha256', $run['uuid']), 'profile_id' => $raw['id'], 'object_type' => 'test', 'object_id' => $run['uuid'], 'properties' => '{}', 'context' => '{}', 'occurred_at' => Database::now()]);
        $this->services['automation']->ingest($event);
        $this->services['automation']->ingest($event);
        $this->drain($run);
        self::assertSame('completed', $this->database->get('runs', $run['uuid'])['state']);
        $steps = $this->database->list('steps', ['run_id' => $run['id']], 100);
        self::assertCount(3, $steps);
        self::assertSame('event', Json::decode($steps[1]['result'])['outcome']);
    }

    public function testSplitJoinUsesOneDurableJoinActivation(): void
    {
        $profile = $this->profile();
        $definitions = $this->services['definitions'];
        $condition = ['rule' => ['field' => 'state', 'operator' => 'eq', 'value' => 'active']];
        $body = ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'fan', 'type' => 'split'], ['id' => 'left', 'type' => 'condition', 'config' => $condition], ['id' => 'right', 'type' => 'condition', 'config' => $condition], ['id' => 'join', 'type' => 'join', 'config' => ['split' => 'fan']], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'fan'], ['from' => 'fan', 'to' => 'left'], ['from' => 'fan', 'to' => 'right'], ['from' => 'join', 'to' => 'end']]];
        foreach (['left', 'right'] as $branch) {
            foreach (['true', 'false', 'unknown'] as $outcome) {
                $body['edges'][] = ['from' => $branch, 'to' => 'join', 'outcome' => $outcome];
            }
        }
        $row = $definitions->create('automation', 'Integration split', $body);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $run = $this->services['automation']->enter($row['uuid'], $profile['uuid'], null, 'split-fact');
        $this->drain($run);
        self::assertSame('completed', $this->database->get('runs', $run['uuid'])['state']);
        $steps = $this->database->list('steps', ['run_id' => $run['id']], 100);
        self::assertCount(6, $steps);
        $joins = array_values(array_filter($steps, static fn(array $step): bool => $step['node_key'] === 'join'));
        self::assertCount(1, $joins);
        self::assertSame(['left' => true, 'right' => true], Json::decode($joins[0]['result'])['arrivals']);
    }

    public function testExperimentAssignmentsStayStableAndExposureIsSeparate(): void
    {
        $profile = $this->profile();
        $definitions = $this->services['definitions'];
        $row = $definitions->create('experiment', 'Integration assignment', ['variants' => [['key' => 'a', 'weight' => 5000], ['key' => 'b', 'weight' => 5000]], 'unit' => 'profile', 'metric' => 'conversion']);
        $published = $definitions->publish($row['uuid'], (int) $row['row_version']);
        $definitions->transition($row['uuid'], 'running', (int) $published['row_version']);
        $assignment = $this->services['experiments']->assign($row['uuid'], $profile['uuid'], $profile['uuid']);
        $again = $this->services['experiments']->assign($row['uuid'], $profile['uuid'], $profile['uuid']);
        self::assertSame($assignment['uuid'], $again['uuid']);
        self::assertNull($assignment['exposed_at']);
        self::assertNotNull($this->services['experiments']->expose($assignment['uuid'])['exposed_at']);
    }

    public function testWooConversionAndPartialRefundAreIdempotent(): void
    {
        $user = wp_create_user('wmos-domain-' . bin2hex(random_bytes(5)), wp_generate_password(30), 'woo-' . bin2hex(random_bytes(5)) . '@example.test');
        self::assertIsInt($user);
        $this->services['contacts']->create('wc-' . bin2hex(random_bytes(5)) . '@example.test', $user, true);
        $order = wc_create_order(['customer_id' => $user]);
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name('Domain test item');
        $fee->set_total('10.01');
        $order->add_item($fee);
        $order->set_currency('USD');
        $order->calculate_totals();
        $order->payment_complete();
        $order->save();
        $measurement = $this->services['measurement'];
        $measurement->reconcile($order);
        $first = $this->database->find('conversions', 'order_id', $order->get_id());
        $measurement->reconcile($order);
        self::assertSame($first['row_version'], $this->database->find('conversions', 'order_id', $order->get_id())['row_version']);
        self::assertSame(1001, (int) $first['net_minor']);
        $differentScale = static fn(): int => 3;
        add_filter('wc_get_price_decimals', $differentScale);
        try {
            $measurement->reconcile($order);
            $stable = $this->database->find('conversions', 'order_id', $order->get_id());
            self::assertSame((int) $first['exponent'], (int) $stable['exponent']);
            self::assertSame(1001, (int) $stable['net_minor']);
            self::assertSame($first['row_version'], $stable['row_version']);
        } finally {
            remove_filter('wc_get_price_decimals', $differentScale);
        }
        $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '3.01', 'refund_payment' => false]);
        self::assertInstanceOf(\WC_Order_Refund::class, $refund);
        $order = wc_get_order($order->get_id());
        $measurement->reconcile($order);
        $corrected = $this->database->find('conversions', 'order_id', $order->get_id());
        self::assertSame(700, (int) $corrected['net_minor']);
        $measurement->reconcile($order);
        self::assertSame($corrected['row_version'], $this->database->find('conversions', 'order_id', $order->get_id())['row_version']);
        foreach (\Wmos\Application\Measurement::MODELS as $model) {
            $credits = $this->database->list('credits', ['conversion_id' => $corrected['id'], 'model' => $model], 100);
            self::assertSame(700, array_sum(array_column($credits, 'amount_minor')));
        }
    }

    public function testRecommendationsUsePublicProductsAndExcludePrivateProducts(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Recommendation product');
        $product->set_status('publish');
        $product->set_regular_price('12.34');
        $product->set_stock_status('instock');
        $product->save();
        $private = new \WC_Product_Simple();
        $private->set_name('Private product');
        $private->set_status('private');
        $private->set_regular_price('2.00');
        $private->save();
        $result = $this->services['recommendations']->recommend('pinned', ['product_ids' => [$product->get_id(), $private->get_id()]], null);
        self::assertCount(1, $result['products']);
        self::assertSame($product->get_id(), $result['products'][0]['id']);
        self::assertFalse($result['personalized']);
    }

    public function testOversizedTouchpointHistoryRetainsExactLastTouchAndRevenue(): void
    {
        $user = wp_create_user('wmos-large-' . bin2hex(random_bytes(4)), wp_generate_password(30), 'large-' . bin2hex(random_bytes(4)) . '@example.test');
        $profile = $this->services['contacts']->create('history-' . bin2hex(random_bytes(4)) . '@example.test', $user, true);
        $raw = $this->database->get('profiles', $profile['uuid']);
        $definitions = $this->services['definitions'];
        $first = $definitions->create('campaign', 'History first', []);
        $last = $definitions->create('campaign', 'History last', []);
        $this->database->transaction(function () use ($raw, $first, $last): void {
            for ($i = 0; $i < 1001; ++$i) {
                $this->database->insert('touchpoints', ['profile_id' => $raw['id'], 'session_hash' => null, 'link_id' => null, 'definition_id' => $i === 1000 ? $last['id'] : $first['id'], 'channel' => 'email', 'occurred_at' => gmdate('Y-m-d H:i:s', time() - 2000 + $i), 'event_key' => hash('sha256', $raw['uuid'] . ':' . $i)]);
            }
        });
        $order = wc_create_order(['customer_id' => $user]);
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name('History item');
        $fee->set_total('5.01');
        $order->add_item($fee);
        $order->set_currency('USD');
        $order->calculate_totals();
        $order->payment_complete();
        $order->save();
        $this->services['measurement']->configureCustomModel(1, ['email' => 2]);
        $this->services['measurement']->reconcile($order);
        $conversion = $this->database->find('conversions', 'order_id', $order->get_id());
        $credits = $this->database->list('credits', ['conversion_id' => $conversion['id'], 'model' => 'last_touch'], 100);
        self::assertCount(1, $credits);
        self::assertSame((int) $last['id'], (int) $credits[0]['definition_id']);
        self::assertSame(501, (int) $credits[0]['amount_minor']);
        foreach (\Wmos\Application\Measurement::MODELS as $model) {
            $credits = $this->database->list('credits', ['conversion_id' => $conversion['id'], 'model' => $model], 100);
            self::assertSame(501, array_sum(array_column($credits, 'amount_minor')));
            self::assertSame(1000000, array_sum(array_column($credits, 'weight')));
        }
    }

    public function testCostOnlyCampaignAppearsWithoutInventedRevenue(): void
    {
        $campaign = $this->services['definitions']->create('campaign', 'Cost-only campaign', []);
        $measurement = $this->services['measurement'];
        $cost = $measurement->recordCost($campaign['uuid'], 123, 'XTS', 2, 'cost-only', '2001-01-01T12:00:00Z');
        self::assertSame($cost['uuid'], $measurement->recordCost($campaign['uuid'], 123, 'XTS', 2, 'cost-only', '2001-01-01T12:00:00Z')['uuid']);
        $report = $measurement->report(['from' => '2001-01-01', 'to' => '2001-01-01']);
        $currency = array_values(array_filter($report['currencies'], static fn(array $row): bool => $row['currency'] === 'XTS'))[0];
        self::assertSame('0', $currency['net_revenue_minor']);
        self::assertSame('0', $currency['orders']);
        self::assertNull($currency['aov_minor']);
        self::assertEquals(0, $currency['roas']);
        $campaignRow = array_values(array_filter($report['campaigns'], static fn(array $row): bool => $row['definition_uuid'] === $campaign['uuid']))[0];
        self::assertSame('123', $campaignRow['cost_minor']);
        self::assertSame('0', $campaignRow['credited_minor']);
        self::assertEquals(0, $campaignRow['attributed_roas']);
    }

    public function testPersonalizationUsesPublicFallbackUntilConsentAndWithdrawsImmediately(): void
    {
        $profile = $this->profile();
        $definitions = $this->services['definitions'];
        $body = ['surface' => 'banner', 'title' => 'Customer banner', 'html' => '<p>Customer content</p><script>alert(1)</script>', 'rule' => ['field' => 'state', 'operator' => 'eq', 'value' => 'active'], 'fallback' => ['title' => 'Public banner', 'html' => '<p>Public content</p>']];
        $row = $definitions->create('personalization', 'Integration banner', $body);
        $definitions->publish($row['uuid'], (int) $row['row_version']);
        $service = $this->services['personalization'];
        self::assertSame('Public banner', $service->resolve($row['uuid'], $profile['uuid'])['title']);
        $this->services['consent']->grant($profile['uuid'], 'personalization', 'personalization', 'test_form', 'policy-v1', ['confirmed' => true], 'personalize:' . $profile['uuid']);
        $selected = $service->resolve($row['uuid'], $profile['uuid']);
        self::assertSame('Customer banner', $selected['title']);
        self::assertStringNotContainsString('<script>', $selected['html']);
        self::assertSame('Public banner', $service->resolve($row['uuid'], null)['title']);
        $this->services['consent']->withdraw($profile['uuid'], 'personalization', 'personalization', 'withdraw-personalize:' . $profile['uuid']);
        self::assertSame('Public banner', $service->resolve($row['uuid'], $profile['uuid'])['title']);
    }
}
