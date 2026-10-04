<?php
declare(strict_types=1);

namespace Wmos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Wmos\Domain\Rules;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;
use Wmos\Platform\Plugin;

final class FactsTest extends TestCase
{
    private array $services;
    private Database $database;
    private int $previousUser;
    private array $previousCookies;
    private array $previousSettings;
    private array $filters = [];

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
        $this->previousUser = get_current_user_id();
        $this->previousCookies = $_COOKIE;
        $this->previousSettings = get_option('wmos_settings', []);
        update_option('wmos_settings', array_replace($this->previousSettings, [
            'tracking_enabled' => true,
            'consent_policy_version' => 'facts-policy-v1',
        ]), false);
        unset($_COOKIE['wmos_preferences']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->services)) {
            return;
        }
        foreach ($this->filters as $filter) {
            remove_filter('wmos_rfm_monetary_thresholds', $filter);
        }
        wp_set_current_user($this->previousUser);
        $_COOKIE = $this->previousCookies;
        update_option('wmos_settings', $this->previousSettings, false);
    }

    private function profile(?int $userId = null): array
    {
        $created = $this->services['contacts']->create('facts-' . bin2hex(random_bytes(6)) . '@example.test', $userId, $userId !== null);
        return $this->database->get('profiles', $created['uuid']);
    }

    private function grant(array $profile, string $purpose, string $channel): void
    {
        $this->services['consent']->grant($profile['uuid'], $purpose, $channel, 'integration_preferences', 'facts-policy-v1', ['confirmed' => true], Database::uuid());
    }

    private function facts(array $profile): array
    {
        $profile = $this->database->get('profiles', $profile['uuid']);
        return $this->services['facts']->load([$profile])[$profile['uuid']];
    }

    private function preferences(array $purposes, ?string $nonce): void
    {
        $tracking = $this->services['tracking'];
        $request = new \WP_REST_Request('GET', '/wmos/v1/tracking/bootstrap');
        $request->set_header('Origin', home_url());
        $challenge = $tracking->bootstrap($request)->get_data()['challenge'];
        $request = new \WP_REST_Request('POST', '/wmos/v1/tracking/consent');
        $request->set_header('Origin', home_url());
        $request->set_header('Content-Type', 'application/json');
        if ($nonce !== null) {
            $request->set_header('X-WP-Nonce', $nonce);
        }
        $request->set_body(Json::encode(['challenge' => $challenge, 'policy_version' => 'facts-policy-v1', 'purposes' => $purposes]));
        self::assertSame(200, $tracking->consent($request)->get_status());
    }

    public function testAuthenticatedPreferencesRequireSeparateProfilingPurposeAndWithdrawalRemovesFacts(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $userId = wp_create_user('wmos-facts-' . $suffix, wp_generate_password(30), 'user-facts-' . $suffix . '@example.test');
        self::assertIsInt($userId);
        $profile = $this->profile($userId);
        $this->database->insert('events', [
            'name' => 'order.paid', 'source' => 'facts_test', 'source_key' => hash('sha256', Database::uuid()),
            'profile_id' => $profile['id'], 'object_type' => 'order', 'object_id' => 'facts-order',
            'properties' => Json::encode(['product_ids' => [123], 'category_ids' => [45], 'coupon_codes' => ['facts-sale'], 'items_truncated' => false]),
            'context' => '{}', 'occurred_at' => Database::now(),
        ]);
        wp_set_current_user($userId);
        self::assertSame([], $this->facts($profile));
        $this->preferences(['profiling'], null);
        self::assertFalse($this->services['consent']->allowed($profile['uuid'], 'profiling', 'profiling'));
        self::assertSame([], $this->facts($profile), 'A browser preference cannot authorize account profiling without the WordPress nonce.');
        $nonce = wp_create_nonce('wp_rest');
        $this->preferences(['analytics', 'personalization'], $nonce);
        self::assertTrue($this->services['consent']->allowed($profile['uuid'], 'analytics', 'analytics'));
        self::assertSame([], $this->facts($profile), 'Analytics and shopping reminder purposes do not grant profiling.');
        $this->preferences(['analytics', 'personalization', 'profiling'], $nonce);
        $facts = $this->facts($profile);
        self::assertSame([123], $facts['product_ids']);
        self::assertSame([45], $facts['category_ids']);
        self::assertSame(['facts-sale'], $facts['coupon_codes']);
        self::assertTrue(Rules::matches(['field' => 'facts.product_ids', 'operator' => 'contains', 'value' => 123], $profile, $facts));
        $this->preferences([], $nonce);
        self::assertSame([], $this->facts($profile));
        self::assertNull(Rules::matches(['field' => 'facts.product_ids', 'operator' => 'contains', 'value' => 123], $profile, $this->facts($profile)));
        self::assertFalse($this->services['consent']->allowed($profile['uuid'], 'profiling', 'profiling'));
    }

    public function testLatestConsentAndIdentitySuppressionGuardConfiguredCurrencyRfm(): void
    {
        $usd = $this->profile();
        $usd = $this->database->update('profiles', $usd['uuid'], [
            'currency' => 'USD', 'exponent' => 2, 'revenue_minor' => 1001,
            'order_count' => 3, 'last_order_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400),
        ]);
        $jpy = $this->profile();
        $jpy = $this->database->update('profiles', $jpy['uuid'], [
            'currency' => 'JPY', 'exponent' => 0, 'revenue_minor' => 1001,
            'order_count' => 3, 'last_order_at' => $usd['last_order_at'],
        ]);
        $this->grant($usd, 'profiling', 'profiling');
        $this->grant($jpy, 'profiling', 'profiling');
        self::assertArrayNotHasKey('rfm', $this->facts($usd), 'No default monetary thresholds may be guessed.');
        $thresholds = static fn($value, $currency, $exponent): ?array => match ($currency . ':' . $exponent) {
            'USD:2' => [100, 500, 1000, 5000],
            'JPY:0' => [2000, 3000, 4000, 5000],
            default => null,
        };
        $this->filters[] = $thresholds;
        add_filter('wmos_rfm_monetary_thresholds', $thresholds, 10, 3);
        self::assertSame('R4-F3-M4', $this->facts($usd)['rfm']);
        self::assertSame('R4-F3-M1', $this->facts($jpy)['rfm']);
        $unscaled = $usd;
        unset($unscaled['exponent']);
        self::assertArrayNotHasKey('rfm', $this->services['facts']->load([$unscaled])[$usd['uuid']]);
        $this->grant($usd, 'marketing', 'email');
        self::assertContains('marketing:email', $this->facts($usd)['consent']);
        $this->services['consent']->withdraw($usd['uuid'], 'marketing', 'email', Database::uuid());
        self::assertNotContains('marketing:email', $this->facts($usd)['consent']);
        $this->grant($usd, 'marketing', 'email');
        $this->services['consent']->suppress($usd['uuid'], 'marketing', 'email', 'manual');
        $this->grant($usd, 'marketing', 'email');
        self::assertNotContains('marketing:email', $this->facts($usd)['consent'], 'A fresh grant does not lift a manual suppression.');
        $identity = $this->database->list('identities', ['profile_id' => $usd['id']], 1)[0];
        $this->database->insert('suppressions', [
            'profile_id' => null, 'identity_hash' => $identity['value_hash'], 'channel' => '*',
            'purpose' => '*', 'reason' => 'complaint', 'scope_key' => hash('sha256', Database::uuid()),
        ]);
        self::assertSame([], $this->facts($usd), 'Identity suppression applies even without a profile ID.');
        self::assertSame('R4-F3-M1', $this->facts($jpy)['rfm'], 'Another profile in the same batch remains authorized.');
        $batch = $this->services['facts']->load([$usd, $jpy]);
        self::assertSame([], $batch[$usd['uuid']]);
        self::assertSame('R4-F3-M1', $batch[$jpy['uuid']]['rfm']);
    }

    private function cart(array $profile, int $total, ?string $currency, ?int $exponent, int $quantity): array
    {
        return $this->database->insert('carts', [
            'cart_key' => hash('sha256', Database::uuid()), 'profile_id' => $profile['id'],
            'fingerprint' => hash('sha256', Database::uuid()), 'state' => 'active',
            'last_activity' => Database::now(), 'contents' => Json::encode([['product_id' => 123, 'quantity' => $quantity]]),
            'total_minor' => $total, 'currency' => $currency, 'exponent' => $exponent,
        ]);
    }

    public function testCartMoneyStaysExactAndUnknownUnitsNeverCombineLoyaltyPrograms(): void
    {
        $profile = $this->profile();
        $this->grant($profile, 'profiling', 'profiling');
        $this->cart($profile, 1001, 'USD', 2, 2);
        $second = $this->cart($profile, 9, 'USD', 2, 1);
        $facts = $this->facts($profile);
        self::assertSame(1010, $facts['cart_total_minor']);
        self::assertSame(3, $facts['cart_count']);
        self::assertTrue(Rules::matches(['field' => 'facts.cart_total_minor', 'operator' => 'eq', 'value' => 1010], $profile, $facts));
        $this->database->update('carts', $second['uuid'], ['currency' => 'EUR']);
        self::assertArrayNotHasKey('cart_total_minor', $this->facts($profile));
        self::assertNull(Rules::matches(['field' => 'facts.cart_total_minor', 'operator' => 'gte', 'value' => 1], $profile, $this->facts($profile)));
        $this->database->update('carts', $second['uuid'], ['currency' => 'USD', 'exponent' => 3]);
        self::assertArrayNotHasKey('cart_total_minor', $this->facts($profile));
        $this->database->update('carts', $second['uuid'], ['exponent' => null]);
        self::assertArrayNotHasKey('cart_total_minor', $this->facts($profile));
        $this->database->update('carts', $second['uuid'], ['exponent' => 2, 'total_minor' => PHP_INT_MAX]);
        self::assertArrayNotHasKey('cart_total_minor', $this->facts($profile), 'Overflow remains unknown rather than changing into floating point.');
        $this->database->insert('program_accounts', ['profile_id' => $profile['id'], 'program_id' => 800000001, 'balance' => 40, 'held' => 5]);
        self::assertSame(40, $this->facts($profile)['loyalty_points']);
        $this->database->insert('program_accounts', ['profile_id' => $profile['id'], 'program_id' => 800000002, 'balance' => 600, 'held' => 0]);
        self::assertArrayNotHasKey('loyalty_points', $this->facts($profile), 'Independent program points have no shared unit.');
        self::assertNull(Rules::matches(['field' => 'facts.loyalty_points', 'operator' => 'gte', 'value' => 1], $profile, $this->facts($profile)));
    }

    private function executeStep(array $run, string $node): array
    {
        $steps = array_values(array_filter($this->database->list('steps', ['run_id' => $run['id']], 100), static fn(array $step): bool => $step['node_key'] === $node));
        self::assertCount(1, $steps);
        $step = $steps[0];
        $job = $this->database->find('jobs', 'operation_key', hash('sha256', 'automation_step:step:' . $step['uuid'] . ':initial'));
        self::assertNotNull($job);
        $job = $this->database->update('jobs', $job['uuid'], ['state' => 'running', 'lease_token' => bin2hex(random_bytes(32)), 'lease_until' => gmdate('Y-m-d H:i:s', time() + 120)], (int) $job['row_version']);
        $this->services['automation']->process($job, Json::decode($job['payload']));
        $this->database->update('jobs', $job['uuid'], ['state' => 'completed', 'lease_token' => null, 'lease_until' => null]);
        return $this->database->get('steps', $step['uuid']);
    }

    public function testWorkflowEventCannotInjectDeniedFactsAndDelayedConditionsHonorWithdrawal(): void
    {
        $profile = $this->profile();
        $event = $this->database->insert('events', [
            'name' => 'order.paid', 'source' => 'facts_workflow_test', 'source_key' => hash('sha256', Database::uuid()),
            'profile_id' => $profile['id'], 'object_type' => 'order', 'object_id' => 'facts-workflow-order',
            'properties' => Json::encode(['product_ids' => [777], 'category_ids' => [45], 'coupon_codes' => [], 'items_truncated' => false]),
            'context' => '{}', 'occurred_at' => Database::now(),
        ]);
        $definition = $this->services['definitions']->create('automation', 'Profiling authorization regression', [
            'nodes' => [
                ['id' => 'start', 'type' => 'trigger'],
                ['id' => 'gate', 'type' => 'condition', 'config' => ['rule' => ['field' => 'facts.product_ids', 'operator' => 'contains', 'value' => 777]]],
                ['id' => 'end', 'type' => 'exit'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'gate'],
                ['from' => 'gate', 'to' => 'end', 'outcome' => 'true'],
                ['from' => 'gate', 'to' => 'end', 'outcome' => 'false'],
                ['from' => 'gate', 'to' => 'end', 'outcome' => 'unknown'],
            ],
        ]);
        $this->services['definitions']->publish($definition['uuid'], (int) $definition['row_version']);
        $automation = $this->services['automation'];
        $denied = $automation->enter($definition['uuid'], $profile['uuid'], $event['uuid'], 'denied-profiling');
        $context = Json::decode($denied['context']);
        self::assertSame([777], $context['event_properties']['product_ids'], 'Business event evidence remains separate.');
        self::assertArrayNotHasKey('facts', $context, 'An event cannot supply derived authorization-sensitive facts.');
        $this->executeStep($denied, 'start');
        self::assertSame('unknown', Json::decode($this->executeStep($denied, 'gate')['result'])['outcome']);
        $this->executeStep($denied, 'end');

        $this->grant($profile, 'profiling', 'profiling');
        self::assertSame([777], $this->facts($profile)['product_ids']);
        $pending = $automation->enter($definition['uuid'], $profile['uuid'], $event['uuid'], 'withdraw-before-gate');
        $context = Json::decode($pending['context']);
        $context['facts'] = ['product_ids' => [777]]; // Previously saved contexts must not bypass current authorization.
        $pending = $this->database->update('runs', $pending['uuid'], ['context' => Json::encode($context)]);
        $this->executeStep($pending, 'start');
        $this->services['consent']->withdraw($profile['uuid'], 'profiling', 'profiling', Database::uuid());
        self::assertSame('unknown', Json::decode($this->executeStep($pending, 'gate')['result'])['outcome']);
        $this->executeStep($pending, 'end');

        $this->grant($profile, 'profiling', 'profiling');
        $authorized = $automation->enter($definition['uuid'], $profile['uuid'], $event['uuid'], 'authorized-current-facts');
        $this->executeStep($authorized, 'start');
        self::assertSame('true', Json::decode($this->executeStep($authorized, 'gate')['result'])['outcome']);
        $this->executeStep($authorized, 'end');
    }

    private function analyticsRequest(string $eventId, ?string $nonce): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/wmos/v1/tracking/events');
        $request->set_header('Origin', home_url());
        $request->set_header('Content-Type', 'application/json');
        if ($nonce !== null) {
            $request->set_header('X-WP-Nonce', $nonce);
        }
        $request->set_body(Json::encode(['events' => [['name' => 'page.viewed', 'id' => $eventId, 'properties' => ['path' => '/facts-analytics']]]]));
        return $request;
    }

    public function testStaleBrowserConsentCannotTrackAnAccountAfterAnalyticsWithdrawal(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $userId = wp_create_user('wmos-stale-' . $suffix, wp_generate_password(30), 'stale-facts-' . $suffix . '@example.test');
        self::assertIsInt($userId);
        $profile = $this->profile($userId);
        wp_set_current_user($userId);
        $nonce = wp_create_nonce('wp_rest');
        $this->preferences(['analytics'], $nonce);
        $cookie = $_COOKIE['wmos_preferences'];
        $tracking = $this->services['tracking'];
        $accepted = $tracking->events($this->analyticsRequest(Database::uuid(), $nonce));
        self::assertSame(202, $accepted->get_status());
        self::assertCount(1, $this->database->list('events', ['source' => 'storefront', 'profile_id' => $profile['id']], 100));
        $this->services['consent']->withdraw($profile['uuid'], 'analytics', 'analytics', Database::uuid());
        self::assertSame($cookie, $_COOKIE['wmos_preferences'], 'Administrative withdrawal leaves a stale browser cookie for this regression.');
        foreach ([$nonce, null] as $requestNonce) {
            try {
                $tracking->events($this->analyticsRequest(Database::uuid(), $requestNonce));
                self::fail('Known account analytics must reject a stale browser grant.');
            } catch (\Wmos\Infrastructure\ValidationException $error) {
                self::assertSame('Current account analytics consent required.', $error->getMessage());
            }
        }
        self::assertCount(1, $this->database->list('events', ['source' => 'storefront', 'profile_id' => $profile['id']], 100));

        $link = $tracking->create(home_url('/facts-analytics-target'));
        $query = $_GET;
        $_GET['wmos_link'] = $link['slug'];
        $location = null;
        $intercept = static function (string $url) use (&$location): never {
            $location = $url;
            throw new \RuntimeException('wmos_facts_redirect_intercepted');
        };
        add_filter('wp_redirect', $intercept);
        try {
            $tracking->redirect();
            self::fail('The test redirect hook must intercept the exit.');
        } catch (\RuntimeException $error) {
            self::assertSame('wmos_facts_redirect_intercepted', $error->getMessage());
        } finally {
            remove_filter('wp_redirect', $intercept);
            $_GET = $query;
        }
        self::assertSame($link['destination'], $location, 'Consent denial preserves redirect availability.');
        self::assertCount(0, $this->database->list('touchpoints', ['link_id' => $link['id']], 100));

        wp_set_current_user(0);
        $eventId = Database::uuid();
        self::assertSame(202, $tracking->events($this->analyticsRequest($eventId, null))->get_status(), 'A consented anonymous session can remain anonymous.');
        $preferences = Json::decode((new \Wmos\Infrastructure\Secrets())->decrypt($cookie, 'tracking.preferences'));
        $anonymous = $this->database->find('events', 'source_key', hash('sha256', $preferences['session'] . ':' . $eventId));
        self::assertNotNull($anonymous);
        self::assertNull($anonymous['profile_id']);
        self::assertCount(1, $this->database->list('events', ['source' => 'storefront', 'profile_id' => $profile['id']], 100));
    }
}
