<?php

declare(strict_types=1);

namespace Wmos\Integration;

use Wmos\Application\{Contacts, Consent, Measurement, Programs};
use Wmos\Infrastructure\{Audit, Database, Events, Json, Queue, Secrets};

/** Public WooCommerce hooks only. Checkout captures bounded local facts; workers do the work. */
final class WooCommerce
{
    public function __construct(private Database $database, private Queue $queue, private Events $events, private Contacts $contacts, private Consent $consent, private Measurement $measurement, private Programs $programs, private Secrets $secrets)
    {
        $queue->register('woo.reconcile', [$this, 'reconcile']);
        $queue->register('woo.customer', function (array $job, array $payload): void {
            if (!$this->enabled() || !$this->secrets->available()) {
                return;
            }
            $user = get_user_by('id', (int)$payload['user_id']);
            if (!$user) {
                return;
            }
            $profile = $this->contacts->create($user->user_email, (int)$user->ID, (bool)$payload['verified']);
            if (!empty($payload['verified']) && (int)($profile['user_id'] ?? 0) === (int)$user->ID) {
                $this->contacts->addIdentity($profile['uuid'], 'email', $user->user_email, 'store', true);
            }
        });
        $queue->register('woo.sweep', [$this, 'sweep']);
    }

    public function register(): void
    {
        add_action('woocommerce_init', [$this, 'checkoutField']);
        add_action('woocommerce_after_order_object_save', [$this, 'dirty'], 20, 1);
        add_action('woocommerce_payment_complete', [$this, 'dirty'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'dirty'], 20, 1);
        add_action('woocommerce_order_refunded', [$this, 'dirty'], 20, 1);
        add_action('woocommerce_checkout_order_processed', function ($id): void {
            $this->checkout($id, 'classic');
        }, 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', function ($order): void {
            $this->checkout($order, 'blocks');
        }, 20, 1);
        add_filter('woocommerce_checkout_fields', [$this, 'classicField']);
        add_action('woocommerce_checkout_create_order', [$this, 'classicChoice'], 20, 2);
        add_action('wp_login', function ($login, $user): void {
            $this->safe(function () use ($user): void {
                if ($this->enabled()) {
                    $this->queue->enqueue('woo.customer', ['user_id' => (int)$user->ID,'verified' => true], 'customer-auth:' . $user->ID . ':' . hash('sha256', $user->user_email));
                }
            });
        }, 20, 2);
        add_action('woocommerce_cart_updated', [$this, 'cart']);
    }

    public function checkoutField(): void
    {
        if (!$this->optInEnabled() || !function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }
        woocommerce_register_additional_checkout_field(['id' => 'wmos/marketing_email','label' => __('Email me offers and news. Confirm by email; unsubscribe at any time.', 'woocommerce-marketing-os'),'location' => 'order','type' => 'checkbox','required' => false]);
    }

    public function classicField(array $fields): array
    {
        if ($this->optInEnabled()) {
            $fields['order']['wmos_marketing_email'] = ['type' => 'checkbox','label' => __('Email me offers and news. Confirm by email; unsubscribe at any time.', 'woocommerce-marketing-os'),'required' => false,'default' => false,'priority' => 100];
        }
        return $fields;
    }

    public function classicChoice(\WC_Order $order, array $data): void
    {
        if ($this->optInEnabled() && !empty($data['wmos_marketing_email'])) {
            $order->update_meta_data('_wmos_email_choice', ['selected' => true,'policy' => $this->policy()]);
        }
    }

    public function dirty(mixed $subject): void
    {
        $this->safe(function () use ($subject): void {
            if (!$this->enabled()) {
                return;
            }
            $order = $subject instanceof \WC_Order ? $subject : wc_get_order((int)$subject);
            if (!$order instanceof \WC_Order) {
                return;
            }
            $digest = hash('sha256', Json::encode([$order->get_status(),(string)$order->get_total(),(string)$order->get_total_refunded(),$order->get_date_paid()?->getTimestamp(),$order->get_date_modified()?->getTimestamp(),array_map(static fn($r): int=>$r->get_id(), $order->get_refunds())]));
            $this->queue->enqueue('woo.reconcile', ['order_id' => $order->get_id()], 'order:' . $order->get_id() . ':' . $digest);
        });
    }

    public function checkout(mixed $subject, string $source): void
    {
        $this->safe(function () use ($subject, $source): void {
            if (!$this->enabled()) {
                return;
            }
            $order = $subject instanceof \WC_Order ? $subject : wc_get_order((int)$subject);
            if (!$order instanceof \WC_Order) {
                return;
            }
            $profile = $this->profile($order);
            $this->events->capture('checkout.accepted', 'woocommerce', 'checkout:' . $order->get_id(), ['checkout_type' => $source,'status' => $order->get_status()], $profile['uuid'] ?? null, ['type' => 'order','id' => (string)$order->get_id()]);
            // Acceptance cancels reminders even when payment is still pending.
            $key = $this->cartKey();
            if ($key) {
                $cart = $this->database->find('carts', 'cart_key', $key);
                if ($cart) {
                    $this->database->update('carts', $cart['uuid'], ['state' => 'converted','order_id' => $order->get_id(),'generation' => (int)$cart['generation'] + 1]);
                }
            }
            if ($this->optInEnabled()) {
                $choice = $order->get_meta('_wmos_email_choice', true);
                $blockChoice = $order->get_meta('_wc_other/wmos/marketing_email', true);
                if (($choice['selected'] ?? false) || $blockChoice === true || $blockChoice === '1') {
                    $order->update_meta_data('_wmos_email_choice', ['selected' => true,'policy' => $choice['policy'] ?? $this->policy()]);
                    $order->save_meta_data();
                    $this->queue->enqueue('woo.reconcile', ['order_id' => $order->get_id()], 'optin-order:' . $order->get_id());
                }
            }
            $this->dirty($order);
        });
    }

    public function reconcile(array $job, array $payload): void
    {
        if (!$this->enabled()) {
            return;
        }
        $order = wc_get_order((int)$payload['order_id']);
        if (!$order instanceof \WC_Order || $order->get_status() === 'checkout-draft') {
            return;
        }
        $profile = $this->profile($order);
        $this->measurement->reconcile($order);
        $this->programs->reconcileOrder($order);
        do_action('wmos_order_reconciled', $order);
        $object = ['type' => 'order','id' => (string)$order->get_id()];
        $properties = ['order_id' => (string)$order->get_id(),'status' => $order->get_status(),'currency' => $order->get_currency(),'total_decimal' => (string)$order->get_total()];
        $productIds = [];
        $categoryIds = [];
        $items = $order->get_items('line_item');
        foreach (array_slice($items, 0, 100) as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            } $productIds[] = $item->get_product_id();
        }
        if ($productIds) {
            foreach (wc_get_products(['include' => array_values(array_unique($productIds)),'limit' => 100]) as $product) {
                $categoryIds = array_merge($categoryIds, $product->get_category_ids());
            }
        }
        $properties += ['product_ids' => array_values(array_unique($productIds)),'category_ids' => array_values(array_unique($categoryIds)),'coupon_codes' => $order->get_coupon_codes(),'items_truncated' => count($items) > 100];
        $this->events->capture('order.created', 'woocommerce', 'created:' . $order->get_id(), $properties, $profile['uuid'] ?? null, $object, $order->get_date_created()?->date('Y-m-d H:i:s'));
        if ($order->get_date_paid()) {
            $this->events->capture('order.paid', 'woocommerce', 'paid:' . $order->get_id(), $properties, $profile['uuid'] ?? null, $object, gmdate('Y-m-d H:i:s', $order->get_date_paid()->getTimestamp()));
        }
        if (in_array($order->get_status(), ['completed','cancelled','failed','refunded'], true)) {
            $this->events->capture('order.' . $order->get_status(), 'woocommerce', $order->get_status() . ':' . $order->get_id(), $properties, $profile['uuid'] ?? null, $object);
        }
        foreach ($order->get_refunds() as $refund) {
            $this->events->capture('order.refund_recorded', 'woocommerce', 'refund:' . $refund->get_id(), ['order_id' => (string)$order->get_id(),'refund_id' => (string)$refund->get_id(),'amount_decimal' => (string)$refund->get_amount(),'currency' => $order->get_currency()], $profile['uuid'] ?? null, $object);
        }
        $choice = $order->get_meta('_wmos_email_choice', true);
        if (($choice['selected'] ?? false) && !$order->get_meta('_wmos_optin_requested', true) && $this->optInEnabled() && $this->secrets->available()) {
            $contact = $this->contacts->create($order->get_billing_email(), null, false);
            $request = $this->consent->requestDoubleOptIn($contact['uuid'], 'marketing', 'email', (string)$choice['policy'], ['order_id' => (string)$order->get_id(),'choice' => 'unchecked_checkout_checkbox'], 'checkout:' . $order->get_id());
            do_action('wmos_double_optin_requested', $contact['uuid'], $request);
            $order->update_meta_data('_wmos_optin_requested', '1');
            $order->save_meta_data();
        }
    }

    public function cart(): void
    {
        $this->safe(function (): void {
            if (!$this->enabled() || !Tracking::purposeAllowed('personalization') || !$this->secrets->available()) {
                return;
            }
            $key = $this->cartKey();
            $cart = WC()->cart;
            if (!$key || !$cart) {
                return;
            }
            $contents = [];
            foreach (array_slice($cart->get_cart(), 0, 200) as $item) {
                $contents[] = ['product_id' => (string)$item['product_id'],'variation_id' => (string)($item['variation_id'] ?? 0),'quantity' => (int)$item['quantity']];
            }
            $profile = get_current_user_id() ? $this->database->find('profiles', 'user_id', get_current_user_id()) : null;
            $currency = get_woocommerce_currency();
            $exponent = wc_get_price_decimals();
            $total = \Wmos\Domain\Money::fromDecimal((string)$cart->get_total('edit'), $currency, $exponent)->minor;
            $old = $this->database->find('carts', 'cart_key', $key);
            $encoded = Json::encode($contents);
            $fingerprint = hash('sha256', $encoded . ':' . $currency . ':' . $exponent . ':' . $total);
            $data = ['profile_id' => $profile['id'] ?? null,'fingerprint' => $fingerprint,'state' => $contents ? 'active' : 'empty','last_activity' => Database::now(),'contents' => $encoded,'total_minor' => $total,'currency' => $currency,'exponent' => $exponent,'generation' => (int)($old['generation'] ?? 0) + 1];
            $old ? $this->database->update('carts', $old['uuid'], $data, (int)$old['row_version']) : $this->database->insert('carts', ['cart_key' => $key] + $data);
        });
    }

    public function sweep(array $job, array $payload): void
    {
        if (!$this->enabled()) {
            return;
        }
        $db = $this->database->db();
        $table = $this->database->table('carts');
        $cursor = (int)($payload['cursor'] ?? 0);
        $rows = $db->get_results($db->prepare("SELECT uuid,id,profile_id,generation FROM {$table} WHERE state='active' AND last_activity<%s AND id>%d ORDER BY id LIMIT 100", gmdate('Y-m-d H:i:s', time() - 3600), $cursor), ARRAY_A);
        foreach ($rows as $row) {
            $current = $this->database->get('carts', $row['uuid']);
            if (!$current || $current['state'] !== 'active' || (int)$current['generation'] !== (int)$row['generation']) {
                continue;
            }
            $profile = $current['profile_id'] ? $this->database->find('profiles', 'id', (int)$current['profile_id']) : null;
            $this->database->transaction(function () use ($current, $profile): void {
                $this->database->update('carts', $current['uuid'], ['state' => 'abandoned'], (int)$current['row_version']);
                $this->events->capture('cart.abandoned', 'woocommerce', 'cart:' . $current['uuid'] . ':' . $current['generation'], ['cart_uuid' => $current['uuid'],'generation' => (int)$current['generation']], $profile['uuid'] ?? null, ['type' => 'cart','id' => $current['uuid']]);
            });
            $cursor = (int)$row['id'];
        }
        if (count($rows) === 100) {
            $this->queue->enqueue('woo.sweep', ['cursor' => $cursor], 'cart-sweep:' . $job['uuid'] . ':' . $cursor);
        }
    }

    public function reconcileRecent(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $cursor = (int)get_option('wmos_order_reconcile_offset', 0);
        $after = (int)get_option('wmos_order_reconcile_since', time() - 86400);
        $ids = wc_get_orders(['limit' => 100,'offset' => $cursor,'return' => 'ids','orderby' => 'ID','order' => 'ASC','date_modified' => '>=' . max(0, $after - 300),'type' => 'shop_order']);
        foreach ($ids as $id) {
            $this->dirty($id);
        }
        if (count($ids) === 100) {
            update_option('wmos_order_reconcile_offset', $cursor + 100, false);
        } else {
            update_option('wmos_order_reconcile_offset', 0, false);
            update_option('wmos_order_reconcile_since', time() - 300, false);
        }
    }

    private function profile(\WC_Order $order): ?array
    {
        return $order->get_customer_id() ? $this->database->find('profiles', 'user_id', $order->get_customer_id()) : null;
    }
    private function cartKey(): ?string
    {
        $session = function_exists('WC') ? WC()->session : null;
        return $this->secrets->available() && $session instanceof \WC_Session_Handler && $session->has_session() ? bin2hex($this->secrets->hash((string)$session->get_customer_id(), 'cart')) : null;
    }
    private function enabled(): bool
    {
        return (bool)(get_option('wmos_settings', [])['commerce_enabled'] ?? false) && (bool)get_option('wmos_active', false);
    }
    private function policy(): string
    {
        return (string)(get_option('wmos_settings', [])['consent_policy_version'] ?? '');
    }
    private function optInEnabled(): bool
    {
        return $this->enabled() && $this->policy() !== '' && $this->secrets->available();
    }
    private function safe(callable $capture): void
    {
        try {
            $capture();
        } catch (\Throwable $error) {
            update_option('wmos_capture_error', ['class' => get_class($error),'at' => Database::now()], false);
        }
    }
}
