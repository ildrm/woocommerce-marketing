<?php

declare(strict_types=1);

namespace Wmos\Platform;

use Wmos\Infrastructure\Schema;

final class Lifecycle
{
    public const CAPABILITIES = [
        'view_analytics','view_contacts','manage_contacts','manage_consent','manage_campaigns','publish_campaigns',
        'manage_automations','publish_automations','run_automations','manage_promotions','adjust_rewards','manage_partners',
        'manage_payouts','manage_integrations','manage_ad_spend','manage_privacy','view_health','operate_queue','manage_settings',
    ];

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(esc_html__('Activate Marketing OS separately for each site. Network-wide activation is not supported.', 'woocommerce-marketing-os'));
        }
        global $wpdb;
        Schema::install($wpdb);
        $administrator = get_role('administrator');
        foreach (self::CAPABILITIES as $capability) {
            $administrator?->add_cap('wmos_' . $capability);
        }
        add_option('wmos_settings', ['tracking_enabled' => false,'commerce_enabled' => false,'retention_days' => 90,'time_zone' => 'UTC','enabled_modules' => [],'consent_policy_version' => '','financial_retention_days' => 0,'telemetry' => false], '', false);
        add_option('wmos_settings_revision', 1, '', false);
        update_option('wmos_active', true, false);
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        update_option('wmos_active', false, false);
        update_option('wmos_execution_epoch', (int)get_option('wmos_execution_epoch', 0) + 1, false);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wmos_queue_tick', [], 'wmos');
            as_unschedule_all_actions('wmos_maintenance', [], 'wmos');
            as_unschedule_all_actions('wmos_migrate', [], 'wmos');
        }
        wp_clear_scheduled_hook('wmos_queue_tick');
        wp_clear_scheduled_hook('wmos_maintenance');
        flush_rewrite_rules(false);
    }
}
