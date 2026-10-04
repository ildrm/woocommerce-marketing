<?php

declare(strict_types=1);

namespace Wmos\Platform;

use Wmos\Application\{Automation, Contacts, Consent, Definitions, Experiments, Facts, Measurement, Messaging, Personalization, Privacy, Programs, Promotions, Recommendations, Segments};
use Wmos\Infrastructure\{Audit, Database, Events, Json, Queue, Schema, Secrets, ValidationException};
use Wmos\Integration\{Preferences, Tracking, WooCommerce};
use Wmos\Rest\Api;

/** Explicit composition root. No domain service resolves its dependencies globally. */
final class Plugin
{
    private static ?self $instance = null;
    private Database $database;
    private array $services = [];
    private WooCommerce $commerce;
    private Secrets $secrets;

    public static function boot(): void
    {
        if (self::$instance || !class_exists('WooCommerce')) {
            return;
        }
        global $wpdb;
        self::$instance = new self(new Database($wpdb));
        self::$instance->register();
    }
    public static function instance(): self
    {
        return self::$instance ?? throw new \RuntimeException('Marketing OS has not booted.');
    }
    public function database(): Database
    {
        return $this->database;
    }
    public function services(): array
    {
        return $this->services;
    }

    private function __construct(Database $database)
    {
        $this->database = $database;
        $audit = new Audit($database);
        $queue = new Queue($database, $audit);
        $this->secrets = new Secrets();
        $contacts = new Contacts($database, $this->secrets, $audit);
        $consent = new Consent($database, $audit, $this->secrets);
        $definitions = new Definitions($database, $audit, $queue);
        $segments = new Segments($database, $queue, $audit);
        $automation = new Automation($database, $definitions, $queue, $audit);
        $measurement = new Measurement($database, $audit);
        $measurement->setQueue($queue);
        $experiments = new Experiments($database, $definitions, $audit);
        $messaging = new Messaging($database, $queue, $contacts, $consent, $this->secrets, $audit);
        $consent->setChallengeSender([$messaging,'queueConfirmation']);
        $definitions->setProviderCapabilities([$messaging,'capabilities']);
        do_action('wmos_register_providers', $messaging);
        $programs = new Programs($database, $queue, $audit);
        $promotions = new Promotions($database, $contacts, $audit);
        $privacy = new Privacy($database, $queue, $contacts, $consent, $audit);
        $events = new Events($database, $queue);
        $recommendations = new Recommendations($database, $consent, $queue, $audit);
        $personalization = new Personalization($database, $definitions, $consent, $recommendations, $audit);
        $facts = new Facts($database);
        $segments->setFactLoader([$facts,'load']);
        $automation->setFactLoader([$facts,'load']);
        $tracking = new Tracking($database, $this->secrets, $events, $measurement, $audit, $consent);
        $this->commerce = new WooCommerce($database, $queue, $events, $contacts, $consent, $measurement, $programs, $this->secrets);
        $this->services = compact('audit', 'queue', 'contacts', 'consent', 'definitions', 'segments', 'automation', 'measurement', 'experiments', 'messaging', 'programs', 'promotions', 'privacy', 'events', 'tracking', 'recommendations', 'personalization', 'facts');
        $this->services['health'] = [$this,'health'];
        $automation->setExperiments($experiments);
        $definitions->setBroadcastPlanner(function (array $definition, string $profileUuid, string $logicalKey) use ($messaging, $consent, $audit): void {
            $body = $definition['execution_body'];
            if (!$consent->allowed($profileUuid, $body['purpose'] ?? 'marketing', $body['channel'])) {
                $audit->record('campaign.recipient_skipped', $definition['uuid'], ['reason' => 'consent_blocked']);
                return;
            }
            $messaging->plan($profileUuid, $body['channel'], $body['provider_uuid'], $body['content'], $logicalKey, $body['purpose'] ?? 'marketing', $definition['uuid']);
        });
        $automation->setActions([
            'message' => fn(array $config, array $run, array $step): array=>$this->messageAction($config, $run, $step),
            'review' => fn(array $config, array $run, array $step): array=>$this->messageAction($config, $run, $step),
            'webhook' => fn(array $config, array $run, array $step): array=>$this->messageAction($config + ['channel' => 'webhook'], $run, $step),
            'tag' => function (array $config, array $run, array $step) use ($contacts): array {
                $profile = $this->runProfile($run);
                $tag = $config['tag'] ?? '';
                if (!is_string($tag) || $tag === '' || strlen($tag) > 64) {
                    throw new ValidationException('Tag is required.');
                }
                $safe = $contacts->get($profile['uuid']);
                $tags = $safe['tags'];
                if (($config['operation'] ?? 'add') === 'remove') {
                    $tags = array_values(array_diff($tags, [$tag]));
                } else {
                    $tags[] = $tag;
                }
                $updated = $contacts->update($profile['uuid'], $safe['attributes'], $tags, (int)$safe['row_version']);
                return ['profile_uuid' => $updated['uuid']];
            },
            'coupon' => function (array $config, array $run, array $step) use ($promotions): array {
                $profile = $this->runProfile($run);
                return $promotions->issue((string)($config['promotion_uuid'] ?? ''), $profile['uuid'], 'step:' . $step['activation_key'], isset($config['version_id']) ? (int)$config['version_id'] : null);
            },
            'points' => function (array $config, array $run, array $step) use ($programs): array {
                $profile = $this->runProfile($run);
                if (!is_int($config['points'] ?? null) || $config['points'] < 1) {
                    throw new ValidationException('Positive integer points required.');
                } return $programs->earn($profile['uuid'], (string)($config['program_uuid'] ?? ''), $config['points'], 'step:' . $step['activation_key'], null, isset($config['version_id']) ? (int)$config['version_id'] : null);
            },
        ]);
        $events->subscribe('automation', [$automation,'ingest']);
        $events->registerConsumers();
        $segments->setEventConsumer([$automation,'ingest']);
    }

    private function register(): void
    {
        (new Admin())->register();
        (new Api($this->database, $this->services))->register();
        $this->services['tracking']->register();
        $this->commerce->register();
        $this->services['privacy']->registerWordPress();
        (new Preferences($this->services['consent'], $this->services['messaging']))->register();
        add_shortcode('wmos_personalization', function (array|string $attributes): string {
            $attributes = shortcode_atts(['uuid' => ''], $attributes);
            try {
                return $this->services['personalization']->render((string)$attributes['uuid']);
            } catch (\Throwable) {
                return '';
            }
        });
        add_action('wmos_order_reconciled', [$this->services['recommendations'],'reconcile']);
        add_filter('woocommerce_coupon_is_valid', [$this->services['promotions'],'valid'], 20, 2);
        add_action('wmos_queue_tick', [$this->services['queue'],'tick']);
        add_action('wmos_maintenance', [$this,'maintenance']);
        add_action('wmos_migrate', [$this,'migrate']);
        add_action('action_scheduler_init', [$this,'schedule']);
        if (did_action('action_scheduler_init')) {
            $this->schedule();
        }
        add_action('init', [$this,'upgrade'], 30);
        add_action('admin_notices', [$this,'notice']);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('wmos', new Command($this));
        }
    }

    private function messageAction(array $config, array $run, array $step): array
    {
        $profile = empty($run['profile_id']) ? null : $this->runProfile($run);
        $owner = $this->database->find('definitions', 'id', (int)$run['definition_id']);
        $content = $config['content'] ?? array_intersect_key($config, array_flip(['subject','html','text','title','template','language','components','data']));
        if (isset($config['body']) && !isset($content['text'])) {
            $content['text'] = $config['body'];
        }
        if (isset($config['template_id']) && !isset($content['template'])) {
            $content['template'] = $config['template_id'];
        }
        return $this->services['messaging']->plan($profile['uuid'] ?? '', (string)($config['channel'] ?? 'email'), (string)($config['provider_uuid'] ?? ''), $content, 'step:' . $step['activation_key'], (string)($config['purpose'] ?? 'marketing'), $owner['uuid'] ?? null, $run['uuid']);
    }
    private function runProfile(array $run): array
    {
        $profile = empty($run['profile_id']) ? null : $this->database->find('profiles', 'id', (int)$run['profile_id']);
        if (!$profile || $profile['state'] !== 'active') {
            throw new ValidationException('An active contact is required for this action.');
        } return $profile;
    }

    public function schedule(): void
    {
        if (!get_option('wmos_active', false) || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        foreach (['wmos_queue_tick' => 60,'wmos_maintenance' => 900] as $hook => $interval) {
            if (!as_has_scheduled_action($hook, [], 'wmos')) {
                as_schedule_recurring_action(time() + $interval, $interval, $hook, [], 'wmos', true);
            }
        }
    }
    public function upgrade(): void
    {
        if (!get_option('wmos_active', false) || (int)get_option('wmos_schema_version', 0) >= Schema::VERSION) {
            return;
        }
        if (function_exists('as_enqueue_async_action') && !as_has_scheduled_action('wmos_migrate', [], 'wmos')) {
            as_enqueue_async_action('wmos_migrate', [], 'wmos', true);
        }
    }
    public function migrate(): void
    {
        if (!get_option('wmos_active', false) || (int)get_option('wmos_schema_version', 0) >= Schema::VERSION) {
            return;
        }
        $db = $this->database->db();
        $lock = 'wmos.migrate.' . substr(hash('sha256', $db->prefix), 0, 32);
        if ((int)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
            return;
        }
        try {
            wp_cache_delete('wmos_migration_cursor', 'options');
            $cursor = (int)get_option('wmos_migration_cursor', 0);
            $names = array_keys(Schema::tables());
            if (isset($names[$cursor])) {
                Schema::installTable($db, $names[$cursor]);
                update_option('wmos_migration_cursor', $cursor + 1, false);
                as_schedule_single_action(time() + 5, 'wmos_migrate', [], 'wmos', false);
            } else {
                update_option('wmos_schema_version', Schema::VERSION, false);
                delete_option('wmos_migration_cursor');
            }
        } finally {
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    public function maintenance(): void
    {
        if (!get_option('wmos_active', false) || (int)get_option('wmos_schema_version', 0) !== Schema::VERSION) {
            return;
        }
        $this->services['privacy']->retention();
        $this->services['programs']->expire();
        $this->commerce->reconcileRecent();
        if (in_array('recommendations', get_option('wmos_settings', [])['enabled_modules'] ?? [], true)) {
            $this->services['recommendations']->refresh();
        }
        $this->services['queue']->enqueue('woo.sweep', ['cursor' => 0], 'cart-sweep:' . intdiv(time(), 900));
        update_option('wmos_maintenance_heartbeat', Database::now(), false);
    }
    public function health(): array
    {
        $db = $this->database->db();
        $jobs = $this->database->table('jobs');
        $counts = $db->get_results("SELECT state,COUNT(*) AS count FROM {$jobs} GROUP BY state", ARRAY_A) ?: [];
        $settings = get_option('wmos_settings', []);
        $evidence = is_file(WMOS_DIR . 'release-evidence.json') ? json_decode((string)file_get_contents(WMOS_DIR . 'release-evidence.json'), true) : null;
        return ['version' => WMOS_VERSION,'schema_version' => (int)get_option('wmos_schema_version', 0),'schema_expected' => Schema::VERSION,'active' => (bool)get_option('wmos_active', false),'encryption_configured' => $this->secrets->available(),'scheduler_available' => function_exists('as_schedule_recurring_action'),'queue_heartbeat' => get_option('wmos_worker_heartbeat', null),'maintenance_heartbeat' => get_option('wmos_maintenance_heartbeat', null),'queue' => $counts,'commerce_enabled' => (bool)($settings['commerce_enabled'] ?? false),'tracking_enabled' => (bool)($settings['tracking_enabled'] ?? false),'capture_error' => get_option('wmos_capture_error', null),'telemetry' => false,'compatibility' => $evidence['compatibility'] ?? ['hpos' => false,'blocks' => false],'tested_environment' => $evidence['environment'] ?? null];
    }
    public function notice(): void
    {
        if (!current_user_can('wmos_manage_settings') || $this->secrets->available()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('Marketing OS needs a base64-encoded 32-byte WMOS_ENCRYPTION_KEY in wp-config.php or the server environment before storing contacts or provider credentials. Back up this key securely.', 'woocommerce-marketing-os') . '</p></div>';
    }
}
