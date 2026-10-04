<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root . '/vendor/autoload.php';
if(getenv('WMOS_INTEGRATION')==='1') {
    define('WP_USE_THEMES',false);
    $_SERVER['HTTP_HOST']='127.0.0.1:8089'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SERVER_PROTOCOL']='HTTP/1.1';
    require $root . '/.runtime/wordpress/wp-load.php';
    add_filter('pre_wp_mail','__return_true');
    add_filter('pre_http_request',static function($response,array $args,string $url) { return $response===false ? new WP_Error('wmos_test_network_blocked','Integration tests never contact external providers.'):$response; },PHP_INT_MAX,3);
    wp_set_current_user((int)get_user_by('login','wmos_test_admin')->ID);
    Wmos\Infrastructure\Schema::install($GLOBALS['wpdb']);
    update_option('wmos_active',true,false);
    update_option('wmos_settings',array_replace(get_option('wmos_settings',[]),['commerce_enabled'=>true,'tracking_enabled'=>true,'consent_policy_version'=>'test-policy-v1','financial_retention_days'=>365,'enabled_modules'=>['messaging','programs','promotions','recommendations','personalization','campaigns','automations','segments','measurement']]),false);
    do_action('rest_api_init');
}
