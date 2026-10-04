<?php
declare(strict_types=1);
namespace Wmos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Wmos\Platform\Plugin;

/** Real WordPress dispatcher, plugin services and InnoDB tables; no mocked persistence. */
final class RestTest extends TestCase
{
    private int $administrator;
    private array $failures = [];
    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !class_exists(Plugin::class)) { self::markTestSkipped('WMOS_INTEGRATION=1 requires the isolated WordPress runtime.'); }
        $this->administrator = (int) get_user_by('login', 'wmos_test_admin')->ID;
        wp_set_current_user($this->administrator);
        add_action('wmos_rest_failure', [$this, 'failure'], 10, 4);
    }
    public function failure(string $class, string $file, int $line, string $correlation): void { $this->failures[] = $class . ' at ' . basename($file) . ':' . $line; }
    protected function tearDown(): void { remove_action('wmos_rest_failure', [$this, 'failure'], 10); if (isset($this->administrator)) { wp_set_current_user($this->administrator); } }
    private function request(string $method, string $path, ?array $data = null, ?string $key = null, ?int $revision = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/wmos/v1/' . $path);
        if ($data !== null) { $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($data)); }
        if ($key !== null) { $request->set_header('Idempotency-Key', $key); }
        if ($revision !== null) { $request->set_header('If-Match', '"' . $revision . '"'); }
        return rest_do_request($request);
    }
    private function draft(string $key): \WP_REST_Response
    {
        return $this->request('POST', 'segments', ['name' => 'REST fixture ' . $key, 'body' => ['rule' => ['field' => 'order_count', 'operator' => 'gte', 'value' => 0]]], $key);
    }
    public function testEveryPrivateEndpointHasAnActualCapabilityCheck(): void
    {
        wp_set_current_user(0);
        foreach (['contacts', 'campaigns', 'providers', 'channel-providers', 'messages', 'reports', 'settings', 'health', 'jobs', 'audit', 'ledger', 'commissions'] as $route) {
            self::assertSame(401, $this->request('GET', $route)->get_status(), $route);
        }
        $subscriber = wp_create_user('rest-' . bin2hex(random_bytes(5)), wp_generate_password(30), 'rest-' . bin2hex(random_bytes(5)) . '@example.test');
        wp_set_current_user($subscriber);
        self::assertSame(403, $this->request('GET', 'contacts')->get_status());
        self::assertSame(403, $this->request('POST', 'segments', ['name' => 'Denied', 'body' => ['rule' => ['all' => []]]], 'unauthorized-key')->get_status());
        wp_delete_user($subscriber);
    }
    public function testDurableReplayBodyConflictAndFreshPermissionCheck(): void
    {
        $key = 'rest-replay-' . bin2hex(random_bytes(6));
        $first = $this->draft($key); self::assertSame(201, $first->get_status(), wp_json_encode($first->get_data()));
        $repeat = $this->draft($key); self::assertSame($first->get_data(), $repeat->get_data());
        $conflict = $this->request('POST', 'segments', ['name' => 'Changed input', 'body' => ['rule' => ['field' => 'order_count', 'operator' => 'gte', 'value' => 0]]], $key);
        self::assertSame(409, $conflict->get_status());
        wp_set_current_user(0); self::assertSame(401, $this->draft($key)->get_status());
    }
    public function testCompareAndSwapRejectsStaleDefinitionAndWrongResource(): void
    {
        $created = $this->draft('rest-cas-' . bin2hex(random_bytes(6)))->get_data();
        $body = ['body' => ['rule' => ['field' => 'order_count', 'operator' => 'gte', 'value' => 2]]];
        $saved = $this->request('PUT', 'segments/' . $created['uuid'], $body, 'rest-save-' . bin2hex(random_bytes(6)), (int) $created['row_version']);
        self::assertSame(200, $saved->get_status());
        $stale = $this->request('PUT', 'segments/' . $created['uuid'], $body, 'rest-stale-' . bin2hex(random_bytes(6)), (int) $created['row_version']); self::assertSame(409, $stale->get_status(), wp_json_encode($stale->get_data()));
        self::assertSame(404, $this->request('GET', 'campaigns/' . $created['uuid'])->get_status());
        self::assertSame(400, $this->request('PUT', 'segments/' . $created['uuid'], $body, 'rest-no-match-' . bin2hex(random_bytes(6)))->get_status());
        self::assertSame(400, $this->request('PUT', 'segments/' . $created['uuid'], $body + ['uuid' => $created['uuid']], 'rest-path-override-' . bin2hex(random_bytes(6)), 2)->get_status());
    }
    public function testBoundedSignedCursorAndClosedRequestFields(): void
    {
        $a = $this->draft('rest-page-' . bin2hex(random_bytes(6))); $b = $this->draft('rest-page-' . bin2hex(random_bytes(6)));
        $request = new \WP_REST_Request('GET', '/wmos/v1/segments'); $request->set_param('per_page', 1);
        $response = rest_do_request($request); self::assertSame(200, $response->get_status(), wp_json_encode($response->get_data()) . implode(',', $this->failures)); $first = $response->get_data(); self::assertCount(1, $first['items']); self::assertNotEmpty($first['next_cursor']);
        $request->set_param('cursor', $first['next_cursor']); $second = rest_do_request($request); self::assertSame(200, $second->get_status());
        self::assertNotSame($first['items'][0]['uuid'], $second->get_data()['items'][0]['uuid']);
        $request->set_param('cursor', $first['next_cursor'] . 'x'); self::assertSame(400, rest_do_request($request)->get_status());
        $request = new \WP_REST_Request('GET', '/wmos/v1/contacts'); $request->set_param('cursor', $first['next_cursor']); self::assertSame(400, rest_do_request($request)->get_status());
        $request->set_param('cursor', ''); $request->set_param('per_page', 101); self::assertSame(400, rest_do_request($request)->get_status());
        self::assertSame(400, $this->request('POST', 'contacts', ['email' => 'valid@example.test', 'role' => 'administrator'], 'rest-fields-' . bin2hex(random_bytes(6)))->get_status());
    }
    public function testSafeContactReceiptsAndCredentialPresentation(): void
    {
        $email = 'receipt-' . bin2hex(random_bytes(5)) . '@example.test';
        $contact = $this->request('POST', 'contacts', ['email' => $email], 'rest-contact-' . bin2hex(random_bytes(6)));
        self::assertSame(201, $contact->get_status()); self::assertArrayNotHasKey('email', $contact->get_data());
        $response = $this->request('GET', 'contacts/' . $contact->get_data()['uuid']); self::assertSame(200, $response->get_status(), wp_json_encode($response->get_data()) . implode(',', $this->failures)); $data = $response->get_data(); self::assertSame($email, $data['email']);
        self::assertArrayNotHasKey('email_cipher', $data); self::assertArrayNotHasKey('id', $data);
        $token = 'test-credential-' . bin2hex(random_bytes(12));
        $provider = $this->request('POST', 'providers', ['name' => 'REST test connection', 'type' => 'resend', 'configuration' => ['from' => 'tests@example.test', 'enabled' => false, 'policy_acknowledged' => false], 'secret' => $token], 'rest-provider-' . bin2hex(random_bytes(6)));
        self::assertSame(201, $provider->get_status(), wp_json_encode($provider->get_data()));
        $presented = wp_json_encode($this->request('GET', 'providers')->get_data()); self::assertStringNotContainsString($token, $presented); self::assertStringNotContainsString('secret', $presented);
        $providerId = $provider->get_data()['uuid'];
        $update = ['name' => 'REST test connection', 'type' => 'resend', 'configuration' => ['from' => 'tests@example.test', 'enabled' => false, 'policy_acknowledged' => false], 'secret' => null];
        self::assertSame(200, $this->request('PUT', 'providers/' . $providerId, $update, 'rest-provider-update-' . bin2hex(random_bytes(6)), 1)->get_status());
        self::assertSame(409, $this->request('PUT', 'providers/' . $providerId, $update, 'rest-provider-stale-' . bin2hex(random_bytes(6)), 1)->get_status());
        $receipts = Plugin::instance()->database()->list('api_requests', ['actor_id' => $this->administrator], 1000);
        self::assertStringNotContainsString($email, wp_json_encode($receipts)); self::assertStringNotContainsString($token, wp_json_encode($receipts));
    }
    public function testPublicProofFailuresAndPayloadLimitsFailClosed(): void
    {
        wp_set_current_user(0);
        self::assertGreaterThanOrEqual(400, $this->request('POST', 'subscriptions/confirm', ['token' => 'invalid'])->get_status());
        self::assertGreaterThanOrEqual(400, $this->request('POST', 'unsubscribe', ['token' => 'invalid'])->get_status());
        wp_set_current_user($this->administrator);
        self::assertSame(413, $this->request('POST', 'segments', ['name' => 'Huge', 'body' => ['rule' => ['all' => []], 'extra' => str_repeat('x', 262144)]], 'rest-large-' . bin2hex(random_bytes(6)))->get_status());
    }
    public function testSettingsCasAndConfirmationLandingNeverMutatePermission(): void
    {
        $settings = $this->request('GET', 'settings')->get_data(); $revision = (int) $settings['row_version']; unset($settings['row_version']);
        $saved = $this->request('PUT', 'settings', $settings, 'rest-settings-' . bin2hex(random_bytes(6)), $revision);
        self::assertSame(200, $saved->get_status(), wp_json_encode($saved->get_data()));
        self::assertSame(409, $this->request('PUT', 'settings', $settings, 'rest-settings-stale-' . bin2hex(random_bytes(6)), $revision)->get_status());
        wp_set_current_user(0);
        $before = (int) Plugin::instance()->database()->db()->get_var('SELECT COUNT(*) FROM ' . Plugin::instance()->database()->table('consents'));
        $request = new \WP_REST_Request('GET', '/wmos/v1/subscriptions/confirm'); $request->set_param('token', str_repeat('a', 64));
        $landing = rest_do_request($request); self::assertSame(200, $landing->get_status()); self::assertStringContainsString('method="post"', $landing->get_data());
        $after = (int) Plugin::instance()->database()->db()->get_var('SELECT COUNT(*) FROM ' . Plugin::instance()->database()->table('consents')); self::assertSame($before, $after);
    }
    public function testDefinitionExportAndImportRemainDraftWithoutCredentials(): void
    {
        $created = $this->draft('rest-export-' . bin2hex(random_bytes(6)))->get_data();
        $export = $this->request('POST', 'definitions/export', ['uuid' => $created['uuid']]); self::assertSame(200, $export->get_status());
        self::assertSame(1, $export->get_data()['schema_version']);
        $imported = $this->request('POST', 'definitions/import', $export->get_data(), 'rest-import-' . bin2hex(random_bytes(6))); self::assertSame(201, $imported->get_status());
        $draft = $this->request('GET', 'segments/' . $imported->get_data()['uuid'])->get_data(); self::assertSame('draft', $draft['state']); self::assertFalse($draft['published']);
        self::assertNotSame($created['uuid'], $draft['uuid']);
    }
}
