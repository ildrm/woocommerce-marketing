<?php

declare(strict_types=1);

namespace Wmos\Platform;

/** Screen-scoped WordPress administration application. */
final class Admin
{
    private string $screen = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        $this->screen = (string) add_submenu_page(
            'woocommerce',
            __('Marketing operating system', 'woocommerce-marketing-os'),
            __('Marketing', 'woocommerce-marketing-os'),
            'wmos_view_analytics',
            'woocommerce-marketing-os',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('wmos_view_analytics')) {
            wp_die(esc_html__('You cannot access marketing administration.', 'woocommerce-marketing-os'), '', ['response' => 403]);
        }
        echo '<div class="wrap wmos-admin"><div id="wmos-admin-root"><p>';
        echo esc_html__('Loading marketing…', 'woocommerce-marketing-os');
        echo '</p></div><noscript><p>';
        echo esc_html__('Enable JavaScript to use the marketing builders.', 'woocommerce-marketing-os');
        echo '</p></noscript></div>';
    }

    public function assets(string $screen): void
    {
        if ($screen !== $this->screen || $this->screen === '') {
            return;
        }
        $pluginFile = dirname(__DIR__, 2) . '/woocommerce-marketing-os.php';
        $version = defined('WMOS_VERSION') ? WMOS_VERSION : '1.0.0-rc.1';
        wp_enqueue_style('wmos-admin', plugins_url('assets/admin.css', $pluginFile), ['wp-components'], $version);
        wp_enqueue_media();
        wp_enqueue_script('wmos-builders', plugins_url('assets/builders.js', $pluginFile), ['wp-i18n'], $version, true);
        wp_enqueue_script('wmos-qr', plugins_url('assets/vendor/qrcode.js', $pluginFile), [], $version, true);
        wp_enqueue_script('wmos-admin', plugins_url('assets/admin.js', $pluginFile), ['wmos-builders', 'wmos-qr', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'], $version, true);
        wp_set_script_translations('wmos-admin', 'woocommerce-marketing-os', dirname(__DIR__, 2) . '/languages');
        wp_set_script_translations('wmos-builders', 'woocommerce-marketing-os', dirname(__DIR__, 2) . '/languages');
        $capabilities = [];
        foreach (['view_analytics', 'manage_campaigns', 'publish_campaigns', 'manage_automations', 'publish_automations', 'run_automations', 'view_contacts', 'manage_contacts', 'manage_consent', 'manage_integrations', 'manage_settings', 'manage_privacy', 'manage_promotions', 'adjust_rewards', 'manage_partners', 'manage_payouts', 'view_health', 'operate_queue'] as $capability) {
            $capabilities[$capability] = current_user_can('wmos_' . $capability);
        }
        wp_add_inline_script('wmos-admin', 'window.wmosAdmin=' . wp_json_encode([
            'restURL' => esc_url_raw(rest_url('wmos/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'locale' => str_replace('_', '-', get_user_locale()),
            'timeZone' => get_option('wmos_settings', [])['time_zone'] ?? 'UTC',
            'capabilities' => $capabilities,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }
}
