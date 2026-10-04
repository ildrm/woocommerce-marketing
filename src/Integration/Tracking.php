<?php

declare(strict_types=1);

namespace Wmos\Integration;

use Wmos\Application\{Consent, Measurement};
use Wmos\Infrastructure\{Audit, Database, Events, Json, Secrets, ValidationException};

/** Bounded first-party collection; no browser identifiers exist before affirmative consent. */
final class Tracking
{
    private const COOKIE = 'wmos_preferences';
    private const PURPOSES = ['analytics','personalization','profiling'];
    private const EVENT_NAMES = ['page.viewed','product.viewed','product.searched','cart.updated','link.clicked'];
    private static ?self $active = null;

    public function __construct(private Database $database, private Secrets $secrets, private Events $events, private Measurement $measurement, private Audit $audit, private Consent $consents)
    {
        self::$active = $this;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this,'routes']);
        add_action('template_redirect', [$this,'redirect'], 1);
        add_action('wp_enqueue_scripts', [$this,'assets']);
        add_shortcode('wmos_preferences', [$this,'preferences']);
    }

    public static function purposeAllowed(string $purpose): bool
    {
        return self::$active && in_array($purpose, self::$active->cookie()['purposes'] ?? [], true);
    }

    public function create(string $destination, ?string $definitionUuid = null, array $dimensions = [], ?string $placementUuid = null): array
    {
        $this->destination($destination);
        if (array_diff(array_keys($dimensions), ['source','medium','campaign','content','term','channel']) || strlen(Json::encode($dimensions)) > 2048) {
            throw new ValidationException('Invalid tracking dimensions.');
        }
        foreach ($dimensions as $value) {
            if (!is_string($value) || strlen($value) > 191 || preg_match('/[\x00-\x1F<>]/', $value)) {
                throw new ValidationException('Invalid dimension value.');
            }
        }
        if ($placementUuid !== null && !preg_match('/^[a-f0-9-]{36}$/Di', $placementUuid)) {
            throw new ValidationException('Invalid placement UUID.');
        }
        $definition = $definitionUuid ? $this->database->get('definitions', $definitionUuid) : null;
        if ($definitionUuid && (!$definition || $definition['kind'] !== 'campaign')) {
            throw new ValidationException('Campaign not found.');
        }
        if ($placementUuid) {
            $placement = $this->database->get('definitions', $placementUuid);
            if (!$placement || !in_array($placement['kind'], ['offline','event','influencer','partner'], true)) {
                throw new ValidationException('Placement not found.');
            }
        }
        $row = $this->database->insert('links', ['slug' => bin2hex(random_bytes(12)),'destination' => $destination,'definition_id' => $definition['id'] ?? null,'placement_uuid' => $placementUuid,'dimensions' => Json::encode($dimensions),'state' => 'active']);
        $this->audit->record('link.created', $row['uuid']);
        return $this->safeLink($row);
    }

    public function list(int $limit = 25, int $after = 0): array
    {
        return array_map([$this,'safeLink'], $this->database->list('links', [], min(101, $limit), $after));
    }

    public function redirect(): void
    {
        $slug = isset($_GET['wmos_link']) ? sanitize_text_field(wp_unslash($_GET['wmos_link'])) : '';
        if ($slug === '' || !preg_match('/^[a-f0-9]{24}$/D', $slug)) {
            return;
        }
        $link = $this->database->find('links', 'slug', $slug);
        if (!$link || $link['state'] !== 'active') {
            status_header(404);
            return;
        }
        try {
            $this->destination($link['destination']);
        } catch (\Throwable) {
            status_header(410);
            return;
        }
        $preferences = $this->cookie();
        if ($this->enabled() && in_array('analytics', $preferences['purposes'] ?? [], true)) {
            try {
                $dimensions = Json::decode($link['dimensions']);
                $profile = $this->analyticsProfile();
                $this->measurement->track(['profile_uuid' => $profile['uuid'] ?? null,'session_hash' => $preferences['session'],'link_id' => $link['id'],'definition_id' => $link['definition_id'],'channel' => $dimensions['channel'] ?? $dimensions['medium'] ?? 'offline','event_key' => hash('sha256', $preferences['session'] . ':' . $link['uuid'] . ':' . gmdate('Y-m-d H:i'))]);
            } catch (\Throwable) {
/* Redirect availability does not depend on collection. */
            }
        }
        nocache_headers();
        wp_safe_redirect($link['destination'], 302, 'Marketing OS');
        exit;
    }

    public function routes(): void
    {
        foreach (['bootstrap' => 'GET','consent' => 'POST','events' => 'POST'] as $name => $method) {
            register_rest_route('wmos/v1', '/tracking/' . $name, ['methods' => $method,'permission_callback' => '__return_true','callback' => function (\WP_REST_Request $request) use ($name) {
                try {
                    return $this->$name($request);
                } catch (ValidationException $error) {
                    return new \WP_Error('wmos_invalid', $error->getMessage(), ['status' => 400]);
                } catch (\Throwable) {
                    return new \WP_Error('wmos_tracking_unavailable', __('Tracking unavailable.', 'woocommerce-marketing-os'), ['status' => 403]);
                }
            }]);
        }
    }

    public function bootstrap(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->sameOrigin($request);
        if (!$this->enabled() || !$this->secrets->available()) {
            return new \WP_REST_Response(['enabled' => false,'purposes' => []]);
        }
        $challenge = $this->secrets->encrypt(Json::encode(['expires' => time() + 600,'salt' => bin2hex(random_bytes(16))]), 'tracking.challenge');
        return new \WP_REST_Response(['enabled' => true,'challenge' => $challenge,'purposes' => $this->cookie()['purposes'] ?? [],'policy_version' => (string)(get_option('wmos_settings', [])['consent_policy_version'] ?? '')], 200, ['Cache-Control' => 'no-store']);
    }

    public function consent(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->sameOrigin($request);
        $body = $this->body($request);
        if (!$this->enabled()) {
            throw new ValidationException('Tracking is disabled.');
        }
        $challenge = Json::decode($this->secrets->decrypt((string)($body['challenge'] ?? ''), 'tracking.challenge'));
        if ((int)($challenge['expires'] ?? 0) < time()) {
            throw new ValidationException('Preference form expired.');
        }
        $purposes = $body['purposes'] ?? null;
        if (!is_array($purposes) || !array_is_list($purposes) || array_diff($purposes, self::PURPOSES) || count($purposes) > 3) {
            throw new ValidationException('Invalid consent purposes.');
        }
        $policy = (string)(get_option('wmos_settings', [])['consent_policy_version'] ?? '');
        if ($purposes && ($policy === '' || ($body['policy_version'] ?? '') !== $policy)) {
            throw new ValidationException('Current consent policy must be displayed and accepted.');
        }
        $old = $this->cookie();
        $profile = $this->currentProfile($request);
        if ($profile) {
            $this->database->transaction(function () use ($profile, $purposes, $policy, $challenge): void {
                foreach (self::PURPOSES as $purpose) {
                    $selected = in_array($purpose, $purposes, true);
                    $allowed = $this->consents->allowed($profile['uuid'], $purpose, $purpose);
                    $operation = 'storefront:' . $profile['uuid'] . ':' . $challenge['salt'] . ':' . $purpose . ':' . ($selected ? 'grant' : 'withdraw');
                    if ($selected && !$allowed) {
                        $this->consents->grant($profile['uuid'], $purpose, $purpose, 'storefront_preferences', $policy, ['confirmed' => true,'form' => 'wmos_preferences'], $operation);
                    } elseif (!$selected && $allowed) {
                        $this->consents->withdraw($profile['uuid'], $purpose, $purpose, $operation);
                    }
                }
            });
        }
        $preferences = ['expires' => time() + 30 * 86400,'session' => $old['session'] ?? bin2hex(random_bytes(32)),'purposes' => array_values(array_unique($purposes)),'policy' => $policy];
        $cipher = $purposes ? $this->secrets->encrypt(Json::encode($preferences), 'tracking.preferences') : '';
        setcookie(self::COOKIE, $cipher, ['expires' => $purposes ? $preferences['expires'] : time() - 3600,'path' => '/','secure' => is_ssl(),'httponly' => true,'samesite' => 'Lax']);
        if ($purposes) {
            $_COOKIE[self::COOKIE] = $cipher;
        } else {
            unset($_COOKIE[self::COOKIE]);
        }
        $this->audit->record('tracking.preference_changed', null, ['count' => count($purposes),'policy_version' => $policy]);
        return new \WP_REST_Response(['purposes' => $preferences['purposes']], 200, ['Cache-Control' => 'no-store']);
    }

    public function events(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->sameOrigin($request);
        $body = $this->body($request);
        $preferences = $this->cookie();
        if (!$this->enabled() || !in_array('analytics', $preferences['purposes'] ?? [], true)) {
            throw new ValidationException('Analytics consent required.');
        }
        $events = $body['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || count($events) > 20) {
            throw new ValidationException('At most twenty events per request.');
        }
        $profile = $this->analyticsProfile($request);
        $bucket = 'wmos_track_' . substr($preferences['session'], 0, 32);
        $count = (int)get_transient($bucket);
        if ($count + count($events) > 200) {
            return new \WP_REST_Response(['error' => 'rate_limit'], 429);
        }
        $accepted = 0;
        foreach ($events as $event) {
            if (!is_array($event) || !in_array($event['name'] ?? '', self::EVENT_NAMES, true) || !preg_match('/^[a-f0-9-]{36}$/Di', $event['id'] ?? '')) {
                throw new ValidationException('Invalid browser event.');
            }
            $properties = $event['properties'] ?? [];
            if (!is_array($properties) || array_diff(array_keys($properties), ['path','product_id','quantity','link_slug','search_length']) || strlen(Json::encode($properties)) > 1024) {
                throw new ValidationException('Invalid browser properties.');
            }
            if (isset($properties['path']) && (!is_string($properties['path']) || strlen($properties['path']) > 512 || !str_starts_with($properties['path'], '/') || str_contains($properties['path'], '?'))) {
                throw new ValidationException('Path must exclude query strings.');
            }
            foreach (['product_id','quantity','search_length'] as $number) {
                if (isset($properties[$number]) && (!is_int($properties[$number]) || $properties[$number] < 0 || $properties[$number] > 100000000)) {
                    throw new ValidationException('Invalid numeric browser property.');
                }
            }
            $this->events->capture($event['name'], 'storefront', $preferences['session'] . ':' . $event['id'], $properties, $profile['uuid'] ?? null);
            ++$accepted;
        }
        set_transient($bucket, $count + $accepted, MINUTE_IN_SECONDS);
        return new \WP_REST_Response(['accepted' => $accepted], 202, ['Cache-Control' => 'no-store']);
    }

    public function assets(): void
    {
        if (!$this->enabled() || !$this->secrets->available()) {
            return;
        }
        wp_enqueue_script('wmos-tracker', plugins_url('assets/tracker.js', WMOS_FILE), [], WMOS_VERSION, true);
        wp_add_inline_script('wmos-tracker', 'window.wmosTracking=' . wp_json_encode(['endpoint' => rest_url('wmos/v1/tracking/'),'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : null,'policy' => (string)(get_option('wmos_settings', [])['consent_policy_version'] ?? ''),'messages' => ['failed' => __('Preferences could not be saved.', 'woocommerce-marketing-os'),'disabled' => __('Store tracking is disabled.', 'woocommerce-marketing-os'),'saved' => __('Preferences saved.', 'woocommerce-marketing-os')]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    public function preferences(): string
    {
        return '<fieldset class="wmos-preferences"><legend>' . esc_html__('Privacy preferences', 'woocommerce-marketing-os') . '</legend><label><input type="checkbox" data-wmos-purpose="analytics"> ' . esc_html__('Allow store analytics', 'woocommerce-marketing-os') . '</label> <label><input type="checkbox" data-wmos-purpose="personalization"> ' . esc_html__('Allow personalized shopping reminders', 'woocommerce-marketing-os') . '</label> <label><input type="checkbox" data-wmos-purpose="profiling"> ' . esc_html__('Allow purchase and engagement profiling', 'woocommerce-marketing-os') . '</label> <button type="button" data-wmos-save>' . esc_html__('Save preferences', 'woocommerce-marketing-os') . '</button><p role="status" data-wmos-status></p></fieldset>';
    }

    private function cookie(): array
    {
        if (!$this->secrets->available() || empty($_COOKIE[self::COOKIE])) {
            return [];
        }
        $cipher = wp_unslash($_COOKIE[self::COOKIE]);
        if (!is_string($cipher) || strlen($cipher) > 4096 || !preg_match('/^v1:[A-Za-z0-9+\/=]+$/D', $cipher)) {
            return [];
        }
        try {
            $value = Json::decode($this->secrets->decrypt($cipher, 'tracking.preferences'));
            return (int)($value['expires'] ?? 0) > time() && ($value['policy'] ?? '') === (get_option('wmos_settings', [])['consent_policy_version'] ?? '') ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }
    private function currentProfile(?\WP_REST_Request $request = null): ?array
    {
        if (!get_current_user_id() || ($request && !wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest'))) {
            return null;
        }
        return $this->database->find('profiles', 'user_id', get_current_user_id());
    }
    private function analyticsProfile(?\WP_REST_Request $request = null): ?array
    {
        $account = $this->currentProfile();
        if ($account && !$this->consents->allowed($account['uuid'], 'analytics', 'analytics')) {
            throw new ValidationException('Current account analytics consent required.');
        }
        return $request ? $this->currentProfile($request) : $account;
    }
    private function sameOrigin(\WP_REST_Request $request): void
    {
        if ($request->get_method() === 'GET' && $request->get_header('Sec-Fetch-Site') === 'same-origin') {
            return;
        }
        $origin = $request->get_header('Origin');
        $site = wp_parse_url(home_url());
        $parsed = wp_parse_url($origin);
        if (!$parsed || !isset($parsed['host'], $parsed['scheme']) || strtolower($parsed['host']) !== strtolower($site['host']) || $parsed['scheme'] !== $site['scheme'] || (int)($parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80)) !== (int)($site['port'] ?? ($site['scheme'] === 'https' ? 443 : 80))) {
            throw new ValidationException('Same-origin request required.');
        }
    }
    private function body(\WP_REST_Request $request): array
    {
        if (strlen((string)$request->get_body()) > 32768 || !str_starts_with(strtolower($request->get_header('Content-Type')), 'application/json')) {
            throw new ValidationException('Bounded JSON body required.');
        } return $request->get_json_params();
    }
    private function enabled(): bool
    {
        return (bool)(get_option('wmos_settings', [])['tracking_enabled'] ?? false) && (bool)get_option('wmos_active', false);
    }
    private function destination(string $url): void
    {
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url());
        if (!$parts || !isset($parts['host'], $parts['scheme']) || $parts['host'] !== $home['host'] || $parts['scheme'] !== $home['scheme'] || (int)($parts['port'] ?? 0) !== (int)($home['port'] ?? 0) || isset($parts['user']) || strlen($url) > 2048) {
            throw new ValidationException('Tracking links require an absolute URL on this store.');
        }
    }
    private function safeLink(array $row): array
    {
        unset($row['definition_id']);
        $row['dimensions'] = Json::decode($row['dimensions']);
        $row['url'] = add_query_arg('wmos_link', $row['slug'], home_url('/'));
        return $row;
    }
}
