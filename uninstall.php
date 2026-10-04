<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Merchant history is preserved unless removal was explicitly selected.
if (!get_option('wmos_remove_data', false)) {
    return;
}
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    return;
}
require_once __DIR__ . '/vendor/autoload.php';
global $wpdb;
$database = new \Wmos\Infrastructure\Database($wpdb);
foreach (array_keys(\Wmos\Infrastructure\Schema::tables()) as $name) {
    $wpdb->query('DROP TABLE IF EXISTS `' . $database->table($name) . '`');
}
$names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wmos_') . '%'));
foreach ($names as $name) {
    delete_option($name);
}
foreach (array_keys(wp_roles()->roles) as $roleName) {
    $role = get_role($roleName);
    foreach (\Wmos\Platform\Lifecycle::CAPABILITIES as $capability) {
        $role?->remove_cap('wmos_' . $capability);
    }
}
