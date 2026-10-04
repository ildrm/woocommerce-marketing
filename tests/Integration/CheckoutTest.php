<?php

declare(strict_types=1);

namespace Wmos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Wmos\Platform\Plugin;

/** Exercises WooCommerce's actual public Store API cart and checkout routes. */
final class CheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('WMOS_INTEGRATION') !== '1') {
            self::markTestSkipped('Enable isolated WordPress integration.');
        }
        update_option('wmos_active', true, false);
        update_option('woocommerce_cod_settings', ['enabled' => 'yes','enable_for_virtual' => 'yes','title' => 'Cash on delivery']);
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if ($gateway->id === 'cod') {
                $gateway->enabled = 'yes';
                $gateway->enable_for_virtual = true;
            }
        }
        update_option('woocommerce_default_country', 'US:CA');
        if (!WC()->session) {
            wc_load_cart();
        }
        WC()->cart->empty_cart();
    }
    private function request(string $method, string $path, array $data = [], ?string $cartToken = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/wc/store/v1/' . $path);
        $request->set_header('Nonce', wp_create_nonce('wc_store_api'));
        if ($cartToken) {
            $request->set_header('Cart-Token', $cartToken);
        }
        if ($data) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($data));
        }
        return rest_do_request($request);
    }
    private function checkout(?int $userId): \WC_Order
    {
        wp_set_current_user($userId ?? 0);
        $product = new \WC_Product_Simple();
        $product->set_name('WMOS checkout fixture');
        $product->set_status('publish');
        $product->set_regular_price('12.34');
        $product->set_virtual(true);
        $product->set_tax_status('none');
        $product->save();
        $cart = $this->request('POST', 'cart/add-item', ['id' => $product->get_id(),'quantity' => 1]);
        self::assertSame(201, $cart->get_status(), wp_json_encode($cart->get_data()));
        $token = $cart->get_headers()['Cart-Token'] ?? null;
        $address = ['first_name' => 'Test','last_name' => 'Shopper','address_1' => '100 Test Street','address_2' => '','city' => 'Los Angeles','state' => 'CA','postcode' => '90001','country' => 'US','email' => 'checkout-' . bin2hex(random_bytes(5)) . '@example.test','phone' => ''];
        $checkout = $this->request('POST', 'checkout', ['billing_address' => $address,'shipping_address' => array_diff_key($address, ['email' => true,'phone' => true]),'payment_method' => 'cod','payment_data' => [],'customer_note' => '','additional_fields' => ['wmos/marketing_email' => false]], $token);
        self::assertSame(200, $checkout->get_status(), wp_json_encode($checkout->get_data()));
        $order = wc_get_order($checkout->get_data()['order_id']);
        self::assertInstanceOf(\WC_Order::class, $order);
        self::assertFalse((bool)$order->get_meta('_wmos_email_choice', true));
        $db = Plugin::instance()->database();
        $events = $db->db()->get_var($db->db()->prepare('SELECT COUNT(*) FROM ' . $db->table('events') . ' WHERE name=%s AND object_id=%s', 'checkout.accepted', (string)$order->get_id()));
        self::assertSame(1, (int)$events);
        $profile = $userId ? $db->find('profiles', 'user_id', $userId) : null;
        if ($profile) {
            self::assertFalse(Plugin::instance()->services()['consent']->allowed($profile['uuid'], 'marketing', 'email'));
        }
        return $order;
    }
    public function testGuestBlocksCheckoutDoesNotCreateOrInferMarketingConsent(): void
    {
        $order = $this->checkout(null);
        self::assertSame(0, $order->get_customer_id());
    }
    public function testRegisteredBlocksCheckoutUsesSameSemanticFactWithoutConsentInference(): void
    {
        $email = 'registered-' . bin2hex(random_bytes(5)) . '@example.test';
        $id = wp_create_user('registered-' . bin2hex(random_bytes(5)), wp_generate_password(24), $email);
        Plugin::instance()->services()['contacts']->create($email, $id, true);
        $order = $this->checkout($id);
        self::assertSame($id, $order->get_customer_id());
    }
    public function testLegacyAndHposPublicOrderCrudAndRepeatedPaidHooks(): void
    {
        foreach ([get_option('woocommerce_custom_orders_table_enabled', 'no')] as $hpos) {
            $order = wc_create_order();
            $order->set_currency('USD');
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name('Storage fixture');
            $fee->set_total('2.03');
            $order->add_item($fee);
            $order->calculate_totals();
            $order->payment_complete();
            $order->save();
            do_action('woocommerce_payment_complete', $order->get_id());
            do_action('woocommerce_payment_complete', $order->get_id());
            $this->processOrderJobs($order->get_id());
            $db = Plugin::instance()->database();
            $conversion = $db->find('conversions', 'order_id', $order->get_id());
            self::assertNotNull($conversion);
            self::assertSame(203, (int)$conversion['net_minor']);
            $count = $db->db()->get_var($db->db()->prepare('SELECT COUNT(*) FROM ' . $db->table('events') . ' WHERE name=%s AND object_id=%s', 'order.paid', (string)$order->get_id()));
            self::assertSame(1, (int)$count);
        }
    }

    private function processOrderJobs(int $orderId): void
    {
        $database = Plugin::instance()->database();
        $db = $database->db();
        $jobs = $database->table('jobs');
        $uuids = $db->get_col($db->prepare("SELECT uuid FROM {$jobs} WHERE kind='woo.reconcile' AND state='pending' AND JSON_EXTRACT(payload,'$.order_id')=%d", $orderId));
        self::assertNotEmpty($uuids, 'The public Woo hooks must persist reconciliation jobs.');
        foreach ($uuids as $uuid) {
            $database->update('jobs', $uuid, ['available_at' => '2000-01-01 00:00:00']);
        }
        Plugin::instance()->services()['queue']->tick();
        foreach ($uuids as $uuid) {
            self::assertSame('completed', $database->get('jobs', $uuid)['state']);
        }
    }

    public function testClassicCheckoutCreatesOneFactAndQueuesOptInWithoutGrantingMarketing(): void
    {
        wp_set_current_user(0);
        $fields = apply_filters('woocommerce_checkout_fields', ['order' => []]);
        self::assertFalse($fields['order']['wmos_marketing_email']['default']);
        $product = new \WC_Product_Simple();
        $product->set_name('Classic checkout fixture');
        $product->set_status('publish');
        $product->set_regular_price('4.56');
        $product->set_virtual(true);
        $product->save();
        WC()->cart->add_to_cart($product->get_id(), 1);
        WC()->cart->calculate_totals();
        $email = 'classic-' . bin2hex(random_bytes(5)) . '@example.test';
        $data = ['billing_first_name' => 'Classic','billing_last_name' => 'Fixture','billing_email' => $email,'billing_address_1' => '100 Test Street','billing_city' => 'Los Angeles','billing_state' => 'CA','billing_postcode' => '90001','billing_country' => 'US','payment_method' => 'cod','wmos_marketing_email' => true];
        $id = WC()->checkout()->create_order($data);
        self::assertIsInt($id);
        $order = wc_get_order($id);
        self::assertTrue($order->get_meta('_wmos_email_choice', true)['selected']);
        do_action('woocommerce_checkout_order_processed', $id, $data, $order);
        do_action('woocommerce_store_api_checkout_order_processed', $order);
        $this->processOrderJobs($id);
        $services = Plugin::instance()->services();
        $profile = $services['contacts']->findByEmail($email);
        self::assertNotNull($profile);
        self::assertFalse($services['consent']->allowed($profile['uuid'], 'marketing', 'email'));
        $db = Plugin::instance()->database();
        self::assertSame(1, (int)$db->db()->get_var($db->db()->prepare('SELECT COUNT(*) FROM ' . $db->table('events') . ' WHERE name=%s AND object_id=%s', 'checkout.accepted', (string)$id)));
        self::assertSame(1, count($db->list('consent_tokens', ['profile_id' => $profile['id']])));
    }
}
