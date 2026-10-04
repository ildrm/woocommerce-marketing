<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Json};
use Wmos\Domain\Money;

/** Batch reads only plugin projections. Missing or capped evidence remains unknown. */
final class Facts
{
    public function __construct(private Database $database)
    {
    }
    public function load(array $profiles): array
    {
        if (!$profiles) {
            return [];
        }
        if (count($profiles) > 100) {
            throw new \InvalidArgumentException('At most one hundred profiles per fact batch.');
        }
        $db = $this->database->db();
        $ids = array_map(static fn(array $p): int=>(int)$p['id'], $profiles);
        $holders = implode(',', array_fill(0, count($ids), '%d'));
        $result = [];
        $uuidById = [];
        foreach ($profiles as $profile) {
            $uuidById[(int)$profile['id']] = $profile['uuid'];
            $result[$profile['uuid']] = [];
        }
        $profilesTable = $this->database->table('profiles');
        $consents = $this->database->table('consents');
        $suppression = $this->database->table('suppressions');
        $identities = $this->database->table('identities');
        $permitted = $db->get_col($db->prepare("SELECT p.id FROM {$profilesTable} p WHERE p.id IN ({$holders}) AND p.state='active' AND (SELECT c.status FROM {$consents} c WHERE c.profile_id=p.id AND c.purpose='profiling' AND c.channel='profiling' ORDER BY c.id DESC LIMIT 1)='granted' AND NOT EXISTS(SELECT 1 FROM {$suppression} s WHERE s.channel IN ('profiling','*') AND s.purpose IN ('profiling','*') AND (s.profile_id=p.id OR s.identity_hash=p.email_hash OR EXISTS(SELECT 1 FROM {$identities} i WHERE i.profile_id=p.id AND i.value_hash=s.identity_hash)))", ...$ids));
        $allowed = array_fill_keys(array_map('intval', $permitted), true);
        if (!$allowed) {
            return $result;
        }
        $allowedIds = array_keys($allowed);
        $allowedHolders = implode(',', array_fill(0, count($allowedIds), '%d'));
        foreach ($allowedIds as $id) {
            $result[$uuidById[$id]] = ['product_ids' => [],'category_ids' => [],'coupon_codes' => [],'open_count' => 0,'click_count' => 0,'referral_count' => 0,'cart_count' => 0,'cart_total_minor' => 0,'segment_uuids' => [],'consent' => []];
        }
        foreach ($profiles as $profile) {
            if (!isset($allowed[(int)$profile['id']])) {
                continue;
            }
            $thresholds = apply_filters('wmos_rfm_monetary_thresholds', null, $profile['currency'], $profile['exponent'] ?? null);
            if (is_array($thresholds) && array_is_list($thresholds) && count($thresholds) === 4 && ($profile['exponent'] ?? null) !== null && preg_match('/^[A-Z]{3}$/D', (string)$profile['currency']) && count(array_filter($thresholds, static fn($v): bool=>is_int($v) && $v >= 0)) === 4 && $thresholds === array_values(array_unique($thresholds))) {
                $sorted = $thresholds;
                sort($sorted);
                if ($sorted === $thresholds) {
                    $days = $profile['last_order_at'] ? max(0, intdiv(time() - (int)strtotime($profile['last_order_at'] . ' UTC'), 86400)) : null;
                    $recency = $days === null ? 0 : ($days <= 7 ? 5 : ($days <= 30 ? 4 : ($days <= 90 ? 3 : ($days <= 180 ? 2 : 1))));
                    $frequency = (int)$profile['order_count'];
                    $frequency = $frequency === 0 ? 0 : self::score($frequency, [1,2,5,10]);
                    $monetary = self::score((int)$profile['revenue_minor'], $thresholds);
                    $result[$profile['uuid']]['rfm'] = 'R' . $recency . '-F' . $frequency . '-M' . $monetary;
                }
            }
        }
        $consentRows = $db->get_results($db->prepare("SELECT c.profile_id,c.purpose,c.channel FROM {$consents} c INNER JOIN {$profilesTable} p ON p.id=c.profile_id WHERE c.profile_id IN ({$allowedHolders}) AND c.status='granted' AND NOT EXISTS(SELECT 1 FROM {$consents} newer WHERE newer.profile_id=c.profile_id AND newer.purpose=c.purpose AND newer.channel=c.channel AND newer.id>c.id) AND NOT EXISTS(SELECT 1 FROM {$suppression} s WHERE s.channel IN (c.channel,'*') AND s.purpose IN (c.purpose,'*') AND (s.profile_id=p.id OR s.identity_hash=p.email_hash OR EXISTS(SELECT 1 FROM {$identities} i WHERE i.profile_id=p.id AND i.value_hash=s.identity_hash))) ORDER BY c.profile_id LIMIT 10001", ...$allowedIds), ARRAY_A) ?: [];
        if (count($consentRows) > 10000) {
            foreach ($allowedIds as $id) {
                unset($result[$uuidById[$id]]['consent']);
            }
        } else {
            foreach ($consentRows as $row) {
                $result[$uuidById[(int)$row['profile_id']]]['consent'][] = $row['purpose'] . ':' . $row['channel'];
            }
        }
        $events = $this->database->table('events');
        $counts = $db->get_results($db->prepare("SELECT profile_id,SUM(name='message.opened') AS open_count,SUM(name IN ('message.clicked','link.clicked')) AS click_count FROM {$events} WHERE profile_id IN ({$allowedHolders}) AND occurred_at>=%s GROUP BY profile_id", ...array_merge($allowedIds, [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])), ARRAY_A) ?: [];
        foreach ($counts as $row) {
            $uuid = $uuidById[(int)$row['profile_id']];
            $result[$uuid]['open_count'] = (int)$row['open_count'];
            $result[$uuid]['click_count'] = (int)$row['click_count'];
        }
        $purchases = $db->get_results($db->prepare("SELECT profile_id,properties FROM {$events} WHERE profile_id IN ({$allowedHolders}) AND name='order.paid' AND occurred_at>=%s ORDER BY id DESC LIMIT 5001", ...array_merge($allowedIds, [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])), ARRAY_A) ?: [];
        if (count($purchases) > 5000) {
            foreach ($allowedIds as $id) {
                unset($result[$uuidById[$id]]['product_ids'], $result[$uuidById[$id]]['category_ids'], $result[$uuidById[$id]]['coupon_codes']);
            }
        } else {
            foreach ($purchases as $row) {
                $uuid = $uuidById[(int)$row['profile_id']];
                $properties = Json::decode($row['properties']);
                foreach (['product_ids','category_ids','coupon_codes'] as $field) {
                    if (($properties['items_truncated'] ?? false) || !array_key_exists($field, $properties)) {
                        unset($result[$uuid][$field]);
                    } elseif (isset($result[$uuid][$field])) {
                        $result[$uuid][$field] = array_values(array_unique(array_merge($result[$uuid][$field], $properties[$field])));
                    }
                }
            }
        }
        $ledger = $this->database->table('program_accounts');
        foreach ($db->get_results($db->prepare("SELECT profile_id,MAX(balance) AS points,COUNT(*) AS accounts FROM {$ledger} WHERE profile_id IN ({$allowedHolders}) GROUP BY profile_id", ...$allowedIds), ARRAY_A) ?: [] as $row) {
            if ((int)$row['accounts'] === 1) {
                $result[$uuidById[(int)$row['profile_id']]]['loyalty_points'] = (int)$row['points'];
            }
        }
        $referrals = $this->database->table('referrals');
        foreach ($db->get_results($db->prepare("SELECT referrer_id,COUNT(*) AS count FROM {$referrals} WHERE referrer_id IN ({$allowedHolders}) AND state IN ('qualified','rewarded') GROUP BY referrer_id", ...$allowedIds), ARRAY_A) ?: [] as $row) {
            $result[$uuidById[(int)$row['referrer_id']]]['referral_count'] = (int)$row['count'];
        }
        $members = $this->database->table('memberships');
        $definitions = $this->database->table('definitions');
        $membershipRows = $db->get_results($db->prepare("SELECT m.profile_id,d.uuid FROM {$members} m INNER JOIN {$definitions} d ON d.id=m.definition_id AND d.generation=m.generation AND d.materialized_version_id=d.published_version_id WHERE m.profile_id IN ({$allowedHolders}) AND d.state IN ('active','rebuilding') ORDER BY m.profile_id LIMIT 10001", ...$allowedIds), ARRAY_A) ?: [];
        if (count($membershipRows) > 10000) {
            foreach ($allowedIds as $id) {
                unset($result[$uuidById[$id]]['segment_uuids']);
            }
        } else {
            foreach ($membershipRows as $row) {
                $result[$uuidById[(int)$row['profile_id']]]['segment_uuids'][] = $row['uuid'];
            }
        }
        $carts = $this->database->table('carts');
        $cartRows = $db->get_results($db->prepare("SELECT profile_id,contents,total_minor,currency,exponent FROM {$carts} WHERE profile_id IN ({$allowedHolders}) AND state IN ('active','abandoned') ORDER BY id DESC LIMIT 1001", ...$allowedIds), ARRAY_A) ?: [];
        $cartTotals = [];
        if (count($cartRows) > 1000) {
            foreach ($allowedIds as $id) {
                unset($result[$uuidById[$id]]['cart_count'], $result[$uuidById[$id]]['cart_total_minor']);
            }
        } else {
            foreach ($cartRows as $row) {
                $uuid = $uuidById[(int)$row['profile_id']];
                foreach (Json::decode($row['contents']) as $item) {
                    $result[$uuid]['cart_count'] += (int)($item['quantity'] ?? 0);
                }
                if (!isset($result[$uuid]['cart_total_minor'])) {
                    continue;
                }
                if ($row['total_minor'] === null || $row['currency'] === null || $row['exponent'] === null) {
                    unset($result[$uuid]['cart_total_minor']);
                    continue;
                }
                try {
                    $amount = new Money((int)$row['total_minor'], (string)$row['currency'], (int)$row['exponent']);
                    $cartTotals[$uuid] = isset($cartTotals[$uuid]) ? $cartTotals[$uuid]->add($amount) : $amount;
                    $result[$uuid]['cart_total_minor'] = $cartTotals[$uuid]->minor;
                } catch (\InvalidArgumentException | \OverflowException) {
                    // Unscaled, mixed currency or overflowing totals cannot satisfy money rules.
                    unset($result[$uuid]['cart_total_minor']);
                }
            }
        }
        return $result;
    }

    private static function score(int $value, array $thresholds): int
    {
        $score = 1;
        foreach ($thresholds as $threshold) {
            if ($value > $threshold) {
                ++$score;
            }
        } return $score;
    }
}
