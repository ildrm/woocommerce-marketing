<?php

declare(strict_types=1);

namespace Wmos\Platform;

final class Compatibility
{
    public static function declare(): void
    {
        $manifest = WMOS_DIR . 'release-evidence.json';
        if (!is_file($manifest)) {
            return;
        }
        $evidence = json_decode((string)file_get_contents($manifest), true);
        if (!is_array($evidence) || ($evidence['version'] ?? '') !== WMOS_VERSION) {
            return;
        }
        $utility = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
        if (!class_exists($utility)) {
            return;
        }
        foreach (['custom_order_tables' => 'hpos','cart_checkout_blocks' => 'blocks'] as $feature => $key) {
            if (true === ($evidence['compatibility'][$key] ?? false)) {
                $utility::declare_compatibility($feature, WMOS_FILE, true);
            }
        }
    }
}
