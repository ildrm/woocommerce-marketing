<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Audit};
use Wmos\Domain\{Money, Rules};

final class Promotions
{
    public function __construct(private Database $database, private Contacts $contacts, private Audit $audit)
    {
    }

    public function issue(string $definitionUuid, string $profileUuid, string $operationKey, ?int $policyVersionId = null): array
    {
        if (!get_option('wmos_active', false) || !in_array('promotions', (array)(get_option('wmos_settings', [])['enabled_modules'] ?? []), true)) {
            throw new \RuntimeException('Promotions module is disabled.');
        }
        $definition = $this->database->get('definitions', $definitionUuid) ?? throw new \RuntimeException('Promotion not found.');
        if ($definition['kind'] !== 'promotion' || $definition['state'] !== 'active' || !$definition['published_version_id']) {
            throw new \RuntimeException('Published active promotion required.');
        }
        $version = $this->database->find('definition_versions', 'id', $policyVersionId ?? (int) $definition['published_version_id']);
        if (!$version || (int)$version['definition_id'] !== (int)$definition['id']) {
            throw new \RuntimeException('Pinned promotion policy is unavailable.');
        }
        $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
        $type = $policy['discount_type'] ?? $policy['type'] ?? '';
        if (!in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true) || ($policy['execution'] ?? 'native') !== 'native') {
            throw new \RuntimeException('This offer requires a supported promotion strategy; native coupons cannot execute it.');
        }
        $profile = $this->contacts->raw($profileUuid);
        if ($profile['state'] !== 'active') {
            throw new \RuntimeException('Contact is unavailable.');
        }
        if (!empty($policy['eligibility']) || !empty($policy['rule'])) {
            $facts = $profile;
            $facts['attributes'] = json_decode($profile['attributes'], true, 32, JSON_THROW_ON_ERROR);
            $facts['tags'] = json_decode($profile['tags'], true, 32, JSON_THROW_ON_ERROR);
            if (Rules::matches($policy['eligibility'] ?? $policy['rule'], $facts) !== true) {
                throw new \RuntimeException('Contact does not satisfy promotion eligibility.');
            }
        }
        $key = hash('sha256', 'coupon:' . $definitionUuid . ':' . $profileUuid . ':' . $operationKey);
        $code = 'wmos-' . substr($key, 0, 32);
        $db = $this->database->db();
        // An advisory lock serializes the operation without pretending Woo writes share our UnitOfWork.
        $lock = 'wmos-coupon-' . substr($key, 0, 48);
        if ((int) $db->get_var($db->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
            throw new \RuntimeException('Coupon issuance is already running.');
        }
        try {
            $coupon = new \WC_Coupon($code);
            if ($coupon->get_id()) {
                if ($coupon->get_meta('_wmos_operation') !== $key || $coupon->get_meta('_wmos_profile_uuid') !== $profileUuid) {
                    throw new \RuntimeException('Coupon ownership conflict.');
                }
                return ['coupon_id' => (string) $coupon->get_id(), 'code' => $coupon->get_code(), 'state' => 'issued'];
            }
            if ($type === 'percent') {
                $bps = (int) ($policy['percent_bps'] ?? (($policy['amount'] ?? 0) * 100));
                if ($bps < 1 || $bps > 10000) {
                    throw new \InvalidArgumentException('Percentage must be positive and at most 100%.');
                }
                $amount = intdiv($bps, 100) . '.' . str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT);
            } else {
                $minor = (int) ($policy['amount_minor'] ?? $policy['amount'] ?? 0);
                $exponent = (int) ($policy['exponent'] ?? 2);
                if ($minor < 1 || ($policy['currency'] ?? '') !== get_woocommerce_currency() || $exponent !== (int)wc_get_price_decimals()) {
                    throw new \InvalidArgumentException('Coupon currency must match the store and amount must be positive.');
                }
                new Money($minor, $policy['currency'], $exponent);
                $amount = self::decimal($minor, $exponent);
            }
            $coupon->set_code($code);
            $coupon->set_discount_type($type);
            $coupon->set_amount($amount);
            $coupon->set_description('Marketing promotion ' . $definitionUuid);
            $coupon->set_individual_use((bool) ($policy['individual_use'] ?? false));
            $coupon->set_usage_limit((int) ($policy['usage_limit'] ?? 1));
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_email_restrictions([$this->contacts->destination($profile, 'email')]);
            $coupon->set_product_ids(array_map('intval', $policy['product_ids'] ?? []));
            $coupon->set_product_categories(array_map('intval', $policy['category_ids'] ?? []));
            $coupon->set_free_shipping((bool) ($policy['free_shipping'] ?? false));
            $expires = isset($policy['ends_at']) ? strtotime($policy['ends_at']) : time() + (int) ($policy['expires_in_days'] ?? 30) * 86400;
            if (!$expires || $expires <= time() || (isset($policy['starts_at']) && strtotime($policy['starts_at']) > time())) {
                throw new \RuntimeException('Promotion is outside its time window.');
            }
            $coupon->set_date_expires($expires);
            if (!empty($policy['minimum_minor'])) {
                $coupon->set_minimum_amount(self::decimal((int) $policy['minimum_minor'], (int) ($policy['exponent'] ?? 2)));
            }
            $coupon->update_meta_data('_wmos_operation', $key);
            $coupon->update_meta_data('_wmos_profile_uuid', $profileUuid);
            $coupon->update_meta_data('_wmos_definition_uuid', $definitionUuid);
            $coupon->update_meta_data('_wmos_version_id', $version['id']);
            $coupon->save();
            $this->audit->record('promotion.issued', $definitionUuid, ['coupon_id' => $coupon->get_id(), 'profile_uuid' => $profileUuid]);
            return ['coupon_id' => (string) $coupon->get_id(), 'code' => $coupon->get_code(), 'state' => 'issued'];
        } finally {
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public function valid(bool $valid, \WC_Coupon $coupon): bool
    {
        $definitionUuid = $coupon->get_meta('_wmos_definition_uuid');
        if (!$definitionUuid) {
            return $valid;
        }
        if (!get_option('wmos_active', false) || !in_array('promotions', (array)(get_option('wmos_settings', [])['enabled_modules'] ?? []), true)) {
            return false;
        }
        $definition = $this->database->get('definitions', (string) $definitionUuid);
        $profile = $this->database->get('profiles', (string) $coupon->get_meta('_wmos_profile_uuid'));
        if (!$valid || !$definition || $definition['state'] !== 'active' || !$profile || $profile['state'] !== 'active') {
            return false;
        }
        $version = $this->database->find('definition_versions', 'id', (int) $coupon->get_meta('_wmos_version_id'));
        if (!$version) {
            return false;
        }
        $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
        if (empty($policy['eligibility']) && empty($policy['rule'])) {
            return true;
        }
        $profile['attributes'] = json_decode($profile['attributes'], true, 32, JSON_THROW_ON_ERROR);
        $profile['tags'] = json_decode($profile['tags'], true, 32, JSON_THROW_ON_ERROR);
        return Rules::matches($policy['eligibility'] ?? $policy['rule'], $profile) === true;
    }

    private static function decimal(int $minor, int $exponent): string
    {
        if ($exponent === 0) {
            return (string) $minor;
        }
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }
}
