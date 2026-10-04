<?php

/**
 * Plugin Name: WooCommerce Marketing OS
 * Plugin URI: https://github.com/ildrm/woocommerce-marketing
 * Description: Consent-aware campaigns, workflows, customer profiles, messaging, programs and attribution for WooCommerce.
 * Version: 1.0.0
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 11.1
 * WC tested up to: 11.1.2
 * Text Domain: woocommerce-marketing-os
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WMOS_VERSION', '1.0.0');
define('WMOS_FILE', __FILE__);
define('WMOS_DIR', __DIR__ . '/');

register_activation_hook(__FILE__, function (bool $networkWide = false): void {
    if (version_compare(PHP_VERSION, '8.3', '<') || !extension_loaded('sodium') || !is_file(__DIR__ . '/vendor/autoload.php')) {
        wp_die(esc_html__('Marketing OS requires PHP 8.3+, sodium, and the complete release ZIP with its Composer autoloader.', 'woocommerce-marketing-os'));
    }
    \Wmos\Platform\Lifecycle::activate($networkWide);
});

if (version_compare(PHP_VERSION, '8.3', '<') || !extension_loaded('sodium') || !is_file(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Marketing OS requires PHP 8.3+, sodium, and the complete release ZIP with its Composer autoloader.', 'woocommerce-marketing-os') . '</p></div>';
        }
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';
register_deactivation_hook(__FILE__, [\Wmos\Platform\Lifecycle::class, 'deactivate']);
add_action('before_woocommerce_init', [\Wmos\Platform\Compatibility::class, 'declare']);
add_action('plugins_loaded', [\Wmos\Platform\Plugin::class, 'boot'], 30);
