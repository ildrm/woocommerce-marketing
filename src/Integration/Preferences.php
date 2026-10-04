<?php

declare(strict_types=1);

namespace Wmos\Integration;

use Wmos\Application\{Consent, Messaging};

/** Human-confirmed preference links. GET and link scanners never grant or withdraw consent. */
final class Preferences
{
    public function __construct(private Consent $consent, private Messaging $messaging)
    {
    }
    public function register(): void
    {
        add_action('template_redirect', [$this,'page'], 0);
    }
    public function page(): void
    {
        $action = isset($_GET['wmos_preference']) ? sanitize_key(wp_unslash($_GET['wmos_preference'])) : '';
        if (!in_array($action, ['confirm','unsubscribe'], true)) {
            return;
        }
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if (strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9_:+=\/-]+$/D', $token)) {
            status_header(400);
            return;
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $message = '';
        $success = false;
        if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
            $nonce = isset($_POST['_wmos_nonce']) ? sanitize_text_field(wp_unslash($_POST['_wmos_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wmos.preference.' . hash('sha256', $token))) {
                $message = __('The form expired. Reload this page and try again.', 'woocommerce-marketing-os');
            } else {
                try {
                    $action === 'confirm' ? $this->consent->confirm($token) : $this->messaging->unsubscribe($token);
                    $success = true;
                    $message = $action === 'confirm' ? __('Subscription confirmed.', 'woocommerce-marketing-os') : __('You are unsubscribed.', 'woocommerce-marketing-os');
                } catch (\Throwable) {
                    $message = __('This preference link is invalid or expired.', 'woocommerce-marketing-os');
                }
            }
        }
        $title = $action === 'confirm' ? __('Confirm email subscription', 'woocommerce-marketing-os') : __('Unsubscribe from email', 'woocommerce-marketing-os');
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html($title) . '</title></head><body><main><h1>' . esc_html($title) . '</h1>';
        if ($message !== '') {
            echo '<p role="status">' . esc_html($message) . '</p>';
        }
        if (!$success) {
            echo '<form method="post">';
            wp_nonce_field('wmos.preference.' . hash('sha256', $token), '_wmos_nonce');
            echo '<button type="submit">' . esc_html($title) . '</button></form>';
        }
        echo '</main></body></html>';
        exit;
    }
}
