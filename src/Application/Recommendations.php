<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Audit, Queue, Json};

/** Deterministic recommendations using public Woo queries and rebuildable score facts. */
final class Recommendations
{
    public const STRATEGIES = ['latest', 'category', 'bestsellers', 'trending', 'pinned', 'recent_views', 'purchase_history'];
    public function __construct(private Database $database, private Consent $consent, private Queue $queue, private Audit $audit)
    {
        $queue->register('recommendation_catalog', [$this, 'process']);
    }

    public function refresh(): array
    {
        if (!$this->enabled()) {
            throw new \DomainException('Recommendations module is disabled.');
        }
        $generation = Database::uuid();
        return $this->queue->enqueue('recommendation_catalog', ['page' => 1, 'generation' => $generation], 'recommendation-catalog:' . $generation . ':1');
    }

    public function reconcile(\WC_Order $order): void
    {
        if (!$this->enabled()) {
            return;
        }
        $eligible = $order->get_date_paid() !== null && in_array($order->get_status(), ['processing', 'completed'], true);
        $observed = [];
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $productId = $item->get_product_id();
            if ($productId < 1) {
                continue;
            }
            $quantity = $eligible ? max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($itemId))) : 0;
            $observed[$productId] = ($observed[$productId] ?? 0) + $quantity;
        }
        ksort($observed);
        $digest = hash('sha256', Json::encode($observed));
        $paidAt = $order->get_date_paid() ?? $order->get_date_created();
        $occurredAt = gmdate('Y-m-d H:i:s', $paidAt?->getTimestamp() ?? 0);
        $this->database->transaction(function () use ($order, $observed, $digest, $occurredAt): void {
            $wpdb = $this->database->db();
            $table = $this->database->table('recommendation_order_products');
            foreach ($observed as $productId => $quantity) {
                $uuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE order_id=%d AND product_id=%d FOR UPDATE", $order->get_id(), $productId));
                $old = $uuid ? $this->database->get('recommendation_order_products', $uuid) : null;
                if ($old && $old['source_digest'] === $digest) {
                    continue;
                }
                $data = ['order_id' => $order->get_id(), 'product_id' => $productId, 'units' => $quantity, 'occurred_at' => $occurredAt, 'source_digest' => $digest];
                $old ? $this->database->update('recommendation_order_products', $old['uuid'], $data, (int) $old['row_version']) : $this->database->insert('recommendation_order_products', $data);
            }
            $kept = $observed ? implode(',', array_map('intval', array_keys($observed))) : '0';
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET units=0,source_digest=%s,row_version=row_version+1,updated_at=%s WHERE order_id=%d AND product_id NOT IN ({$kept}) AND units<>0", $digest, Database::now(), $order->get_id()));
        });
    }

    public function process(array $job, array $payload): void
    {
        if (!$this->enabled()) {
            throw new \Wmos\Infrastructure\DeferredException('Recommendations module is disabled.');
        }
        // Public query pagination is supported; catalogue work never runs on checkout.
        $products = wc_get_products(['status' => ['publish', 'draft', 'private'], 'limit' => 100, 'page' => (int) $payload['page'], 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects']);
        $this->database->transaction(function () use ($products, $payload): void {
            foreach ($products as $product) {
                $old = $this->database->find('recommendation_products', 'product_id', $product->get_id());
                $data = ['product_id' => $product->get_id(), 'total_sales' => max(0, (int) $product->get_total_sales()), 'published' => $product->get_status() === 'publish' && $product->is_visible() ? 1 : 0, 'observed_at' => Database::now()];
                $old ? $this->database->update('recommendation_products', $old['uuid'], $data, (int) $old['row_version']) : $this->database->insert('recommendation_products', $data);
            }
            if (count($products) === 100) {
                $next = $payload;
                ++$next['page'];
                $this->queue->enqueue('recommendation_catalog', $next, 'recommendation-catalog:' . $payload['generation'] . ':' . $next['page']);
            } else {
                $this->audit->record('recommendations.catalog_refreshed', null, ['generation' => $payload['generation']]);
            }
        });
    }

    public function recommend(string $strategy, array $config = [], ?string $profileUuid = null): array
    {
        if (!$this->enabled()) {
            return ['strategy' => 'disabled', 'personalized' => false, 'products' => [], 'as_of' => Database::now()];
        }
        self::validate($strategy, $config);
        $limit = $config['limit'] ?? 6;
        $private = in_array($strategy, ['recent_views', 'purchase_history'], true);
        if ($private && ($profileUuid === null || !$this->consent->allowed($profileUuid, 'personalization', 'personalization'))) {
            $strategy = 'latest';
            $private = false;
        }
        $cacheKey = 'wmos-recommend-' . hash('sha256', Json::encode([$strategy, $config]));
        $ids = $private ? false : wp_cache_get($cacheKey, 'wmos');
        if ($ids === false) {
            $ids = $this->candidates($strategy, $config, $profileUuid, $limit);
            if (!$private) {
                wp_cache_set($cacheKey, $ids, 'wmos', 300);
            }
        }
        $result = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $product = wc_get_product((int) $id);
            if (!$product || $product->get_status() !== 'publish' || !$product->is_visible() || !$product->is_purchasable() || !$product->is_in_stock()) {
                continue;
            }
            $imageId = (int) $product->get_image_id();
            $result[] = ['id' => $product->get_id(), 'name' => $product->get_name(), 'url' => $product->get_permalink(), 'price_html' => wp_kses_post($product->get_price_html()), 'image_url' => $imageId ? wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') : '', 'image_alt' => $product->get_name()];
        }
        if ($result === [] && $strategy !== 'latest') {
            return $this->recommend('latest', ['limit' => $limit], null);
        }
        return ['strategy' => $strategy, 'personalized' => $private, 'products' => $result, 'as_of' => Database::now()];
    }

    public static function validate(string $strategy, array $config): void
    {
        if (!in_array($strategy, self::STRATEGIES, true) || array_diff(array_keys($config), ['limit', 'product_ids', 'category_ids', 'exclude_ids', 'days'])) {
            throw new \InvalidArgumentException('Unknown recommendation strategy/configuration.');
        }
        if (isset($config['limit']) && (!is_int($config['limit']) || $config['limit'] < 1 || $config['limit'] > 20) || isset($config['days']) && (!is_int($config['days']) || $config['days'] < 1 || $config['days'] > 90)) {
            throw new \InvalidArgumentException('Recommendation limits are out of range.');
        }
        foreach (['product_ids', 'category_ids', 'exclude_ids'] as $key) {
            if (isset($config[$key]) && (!is_array($config[$key]) || !array_is_list($config[$key]) || count($config[$key]) > 100 || array_filter($config[$key], static fn($id): bool => !is_int($id) || $id < 1))) {
                throw new \InvalidArgumentException('Invalid recommendation product/category references.');
            }
        }
        if ($strategy === 'pinned' && empty($config['product_ids']) || $strategy === 'category' && empty($config['category_ids'])) {
            throw new \InvalidArgumentException('Recommendation selection is required.');
        }
    }

    private function candidates(string $strategy, array $config, ?string $profileUuid, int $limit): array
    {
        $args = ['status' => 'publish', 'limit' => $limit, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'stock_status' => 'instock', 'exclude' => $config['exclude_ids'] ?? []];
        switch ($strategy) {
            case 'pinned':
                return array_values(array_diff($config['product_ids'], $args['exclude']));
            case 'category':
                $args['product_category_id'] = $config['category_ids'];
                break;
            case 'bestsellers':
                $wpdb = $this->database->db();
                $table = $this->database->table('recommendation_products');
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT product_id FROM {$table} WHERE published=1 ORDER BY total_sales DESC,product_id LIMIT %d", $limit)));
                return $ids ?: wc_get_products($args);
            case 'trending':
                $wpdb = $this->database->db();
                $table = $this->database->table('recommendation_order_products');
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT product_id FROM {$table} WHERE occurred_at>=%s AND units>0 GROUP BY product_id ORDER BY SUM(units) DESC,product_id LIMIT %d", gmdate('Y-m-d H:i:s', time() - ($config['days'] ?? 30) * 86400), $limit)));
                return $ids ?: wc_get_products($args);
            case 'recent_views':
                $profile = $this->database->get('profiles', $profileUuid);
                $wpdb = $this->database->db();
                $events = $this->database->table('events');
                $rows = $wpdb->get_col($wpdb->prepare("SELECT properties FROM {$events} WHERE profile_id=%d AND name='commerce.product.viewed' AND occurred_at>=%s ORDER BY occurred_at DESC,id DESC LIMIT 50", $profile['id'], gmdate('Y-m-d H:i:s', time() - 30 * 86400)));
                $ids = [];
                foreach ($rows as $properties) {
                    $id = (int) (Json::decode($properties)['product_id'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                return array_values(array_unique(array_diff($ids, $args['exclude'])));
            case 'purchase_history':
                $profile = $this->database->get('profiles', $profileUuid);
                if (!$profile || !$profile['user_id']) {
                    break;
                }
                $orders = wc_get_orders(['customer_id' => (int) $profile['user_id'], 'status' => ['processing', 'completed'], 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC']);
                $categories = [];
                $purchased = [];
                $items = 0;
                foreach ($orders as $order) {
                    foreach ($order->get_items('line_item') as $item) {
                        if (!$item instanceof \WC_Order_Item_Product) {
                            continue;
                        }
                        if (++$items > 50) {
                            break 2;
                        }
                        $product = $item->get_product();
                        if ($product) {
                            $purchased[] = $product->get_id();
                            $categories = array_merge($categories, $product->get_category_ids());
                        }
                    }
                }
                if ($categories) {
                    $args['product_category_id'] = array_values(array_unique($categories));
                    $args['exclude'] = array_values(array_unique(array_merge($args['exclude'], $purchased)));
                }
                break;
        }
        return wc_get_products($args);
    }
    private function enabled(): bool
    {
        return in_array('recommendations', get_option('wmos_settings', [])['enabled_modules'] ?? [], true);
    }
}
