<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Queue, Audit};
use Wmos\Domain\Money;

/** Immutable incentive effects; cached account balance is updated in the same transaction. */
final class Programs
{
    public function __construct(private Database $database, private Queue $queue, private Audit $audit)
    {
        $queue->register('programs.expire', function (): void {
            $this->expire();
        });
        $queue->register('programs.redemptions', function (array $job, array $payload): void {
            $order = wc_get_order((int)$payload['order_id']);
            if ($order) {
                $this->reconcileRedemptions($order, (int)$payload['after']);
            }
        });
        $queue->register('programs.order_reconcile', function (array $job, array $payload): void {
            $order = wc_get_order((int)$payload['order_id']);
            if ($order) {
                $this->reconcileOrder($order, (int)($payload['after'] ?? 0));
            }
        });
        $queue->register('programs.referrals', function (array $job, array $payload): void {
            $order = wc_get_order((int)$payload['order_id']);
            $program = $this->database->get('definitions', $payload['program_uuid']);
            $profile = $this->database->get('profiles', $payload['profile_uuid']);
            if ($order && $program && $profile) {
                $this->reconcileReferrals($profile, $program, $order, $this->orderNet($order, (int)$payload['exponent']), (int)$payload['after']);
            }
        });
        $queue->register('programs.commissions', function (array $job, array $payload): void {
            $order = wc_get_order((int)$payload['order_id']);
            $program = $this->database->get('definitions', $payload['program_uuid']);
            if ($order && $program) {
                $this->reconcileCommissions($program, $order, $this->orderNet($order, (int)$payload['exponent']), (int)$payload['after']);
            }
        });
        $queue->register('programs.order', function (array $job, array $payload): void {
            $order = wc_get_order((int) $payload['order_id']);
            if ($order) {
                $this->reconcileOrder($order);
            }
        });
    }

    public function balance(string $profileUuid, string $programUuid): array
    {
        [$profile, $program] = $this->references($profileUuid, $programUuid);
        $account = $this->account($profile['id'], $program['id'], false);
        return ['balance' => (string) ($account['balance'] ?? 0), 'held' => (string) ($account['held'] ?? 0), 'available' => (string) max(0, (int) ($account['balance'] ?? 0) - (int) ($account['held'] ?? 0))];
    }

    public function ledger(string $profileUuid, string $programUuid, int $limit = 25, int $after = 0): array
    {
        [$profile, $program] = $this->references($profileUuid, $programUuid);
        return array_map([$this, 'safeLedger'], $this->database->list('ledger', ['profile_id' => $profile['id'], 'program_id' => $program['id']], $limit, $after));
    }

    public function earn(string $profileUuid, string $programUuid, int $points, string $operationKey, ?int $orderId = null, ?int $policyVersionId = null): array
    {
        if ($points < 1) {
            throw new \InvalidArgumentException('Earn points must be positive.');
        }
        [$profile, $program, $policy, $version] = $this->references($profileUuid, $programUuid);
        if ($policyVersionId !== null) {
            $version = $this->database->find('definition_versions', 'id', $policyVersionId) ?? throw new \RuntimeException('Pinned program version unavailable.');
            if ((int)$version['definition_id'] !== (int)$program['id']) {
                throw new \RuntimeException('Pinned policy belongs to another program.');
            }$policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
        }
        return $this->database->transaction(function () use ($profile, $program, $policy, $version, $points, $operationKey, $orderId): array {
            $this->lockProfile($profile['id']);
            $row = $this->post($profile['id'], $program['id'], $points, 'earn', $operationKey, $orderId, null, 'Program earn', (int)$version['id']);
            if (!$this->database->find('loyalty_lots', 'ledger_id', (int) $row['id'])) {
                $days = (int) ($policy['earn_expiry_days'] ?? 365);
                $this->database->insert('loyalty_lots', ['ledger_id' => $row['id'], 'profile_id' => $profile['id'], 'program_id' => $program['id'], 'remaining' => $points, 'expires_at' => $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null]);
            }
            return $this->safeLedger($row);
        });
    }

    public function adjust(string $profileUuid, string $programUuid, int $points, string $reason, string $operationKey): array
    {
        if ($points === 0 || trim($reason) === '' || strlen($reason) > 191) {
            throw new \InvalidArgumentException('Adjustment requires nonzero points and a reason.');
        }
        [$profile, $program, , $version] = $this->references($profileUuid, $programUuid);
        return $this->database->transaction(function () use ($profile, $program, $version, $points, $reason, $operationKey): array {
            $this->lockProfile($profile['id']);
            $existing = $this->database->find('ledger', 'operation_key', hash('sha256', $operationKey));
            $account = $this->account($profile['id'], $program['id'], true);
            if (!$existing && $points < 0 && (int)$account['balance'] - (int)$account['held'] < -$points) {
                throw new \RuntimeException('Adjustment cannot consume reserved or unavailable points.');
            }
            $row = $this->post($profile['id'], $program['id'], $points, 'adjust', $operationKey, null, null, $reason, (int)$version['id']);
            if ($existing) {
                return $this->safeLedger($row);
            }
            if ($points < 0) {
                $this->consumeAvailableLots($row, -$points);
            }
            if ($points > 0 && !$this->database->find('loyalty_lots', 'ledger_id', (int) $row['id'])) {
                $this->database->insert('loyalty_lots', ['ledger_id' => $row['id'], 'profile_id' => $profile['id'], 'program_id' => $program['id'], 'remaining' => $points, 'expires_at' => null]);
            }
            $this->audit->record('loyalty.adjusted', $profile['uuid'], ['program' => $program['uuid'], 'points' => $points, 'reason' => $reason]);
            return $this->safeLedger($row);
        });
    }

    public function reserve(string $profileUuid, string $programUuid, int $points, string $operationKey, int $ttl = 1800, ?int $orderId = null): array
    {
        if ($points < 1 || $ttl < 60 || $ttl > 86400) {
            throw new \InvalidArgumentException('Invalid points reservation.');
        }
        [$profile, $program] = $this->references($profileUuid, $programUuid);
        return $this->database->transaction(function () use ($profile, $program, $points, $operationKey, $ttl, $orderId): array {
            $this->lockProfile($profile['id']);
            $account = $this->account($profile['id'], $program['id'], true);
            $key = hash('sha256', 'hold:' . $operationKey);
            $old = $this->database->find('loyalty_holds', 'operation_key', $key);
            if ($old) {
                if ((int) $old['profile_id'] !== (int) $profile['id'] || (int) $old['program_id'] !== (int) $program['id'] || (int) $old['points'] !== $points) {
                    throw new \RuntimeException('Reservation key conflict.');
                }
                return $this->safeHold($old);
            }
            if ((int) $account['balance'] - (int) $account['held'] < $points) {
                throw new \RuntimeException('Insufficient available points.');
            }
            $hold = $this->database->insert('loyalty_holds', ['profile_id' => $profile['id'], 'program_id' => $program['id'], 'points' => $points, 'state' => 'reserved', 'operation_key' => $key, 'order_id' => $orderId, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl)]);
            $db = $this->database->db();
            $lots = $db->get_results($db->prepare('SELECT id,uuid,remaining,expires_at FROM ' . $this->database->table('loyalty_lots') . ' WHERE profile_id=%d AND program_id=%d AND remaining>0 AND (expires_at IS NULL OR expires_at>%s) ORDER BY expires_at IS NULL,expires_at,id LIMIT 1000 FOR UPDATE', $profile['id'], $program['id'], Database::now()), ARRAY_A);
            $left = $points;
            foreach ($lots as $lot) {
                $held = (int) $db->get_var($db->prepare('SELECT COALESCE(SUM(a.points),0) FROM ' . $this->database->table('loyalty_allocations') . ' a INNER JOIN ' . $this->database->table('loyalty_holds') . ' h ON h.id=a.hold_id WHERE a.lot_id=%d AND h.state=%s', $lot['id'], 'reserved'));
                $take = min($left, (int) $lot['remaining'] - $held);
                if ($take < 1) {
                    continue;
                }
                $this->database->insert('loyalty_allocations', ['lot_id' => $lot['id'], 'hold_id' => $hold['id'], 'ledger_id' => null, 'points' => $take, 'operation_key' => hash('sha256', 'allocate:' . $hold['uuid'] . ':' . $lot['uuid'])]);
                $left -= $take;
                if ($left === 0) {
                    break;
                }
            }
            if ($left !== 0) {
                throw new \RuntimeException('Eligible point lots cannot satisfy this reservation.');
            }
            $this->database->update('program_accounts', $account['uuid'], ['held' => (int) $account['held'] + $points], (int) $account['row_version']);
            return $this->safeHold($hold);
        });
    }

    public function redeem(string $holdUuid, string $operationKey): array
    {
        $before = $this->database->get('loyalty_holds', $holdUuid) ?? throw new \RuntimeException('Reservation not found.');
        $money = null;
        if ($before['order_id']) {
            $order = wc_get_order((int)$before['order_id']);
            if (!$order || !$order->get_date_paid() || $order->has_status(['cancelled','failed','refunded'])) {
                throw new \RuntimeException('An order-bound redemption requires a paid canonical order.');
            }$program = $this->database->find('definitions', 'id', (int)$before['program_id']);
            $version = $this->database->find('definition_versions', 'id', (int)$program['published_version_id']);
            $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
            $base = 0;
            foreach ($order->get_items('line_item') as $item) {
                if (!$item instanceof \WC_Order_Item_Product) {
                    continue;
                }$base += Money::fromDecimal((string)$item->get_total(), $order->get_currency(), (int)($policy['exponent'] ?? 2))->minor;
            }$money = ['amount_minor' => $base,'currency' => $order->get_currency(),'exponent' => (int)($policy['exponent'] ?? 2),'policy_version_id' => (int)$version['id']];
        }
        return $this->database->transaction(function () use ($holdUuid, $operationKey, $money): array {
            $hold = $this->database->get('loyalty_holds', $holdUuid) ?? throw new \RuntimeException('Reservation not found.');
            $this->lockProfile($hold['profile_id']);
            $hold = $this->database->get('loyalty_holds', $holdUuid);
            $existing = $this->database->find('ledger', 'operation_key', hash('sha256', 'redeem:' . $holdUuid));
            if ($hold['state'] === 'redeemed' && $existing) {
                return $this->safeLedger($existing);
            }
            if ($hold['state'] !== 'reserved' || $hold['expires_at'] <= Database::now()) {
                throw new \RuntimeException('Reservation is no longer spendable.');
            }
            $row = $this->post($hold['profile_id'], $hold['program_id'], -(int) $hold['points'], 'redeem', 'redeem:' . $holdUuid, $hold['order_id'] ? (int) $hold['order_id'] : null, null, 'Reserved redemption', $money['policy_version_id'] ?? null, $money);
            $account = $this->account($hold['profile_id'], $hold['program_id'], true);
            $this->database->update('program_accounts', $account['uuid'], ['held' => (int) $account['held'] - (int) $hold['points']], (int) $account['row_version']);
            foreach ($this->database->list('loyalty_allocations', ['hold_id' => $hold['id']], 1000) as $allocation) {
                $lot = $this->database->find('loyalty_lots', 'id', (int) $allocation['lot_id']);
                if (!$lot || (int) $lot['remaining'] < (int) $allocation['points']) {
                    throw new \RuntimeException('Point lot integrity failure.');
                }
                $this->database->update('loyalty_lots', $lot['uuid'], ['remaining' => (int) $lot['remaining'] - (int) $allocation['points']]);
                $this->database->insert('loyalty_allocations', ['lot_id' => $lot['id'], 'hold_id' => null, 'ledger_id' => $row['id'], 'points' => $allocation['points'], 'operation_key' => hash('sha256', 'redeem-allocation:' . $allocation['uuid'])]);
            }
            $this->database->update('loyalty_holds', $holdUuid, ['state' => 'redeemed']);
            $this->audit->record('loyalty.redeemed', $holdUuid, ['operation' => hash('sha256', $operationKey)]);
            return $this->safeLedger($row);
        });
    }

    public function release(string $holdUuid, string $operationKey): array
    {
        return $this->database->transaction(function () use ($holdUuid): array {
            $hold = $this->database->get('loyalty_holds', $holdUuid) ?? throw new \RuntimeException('Reservation not found.');
            $this->lockProfile($hold['profile_id'], true);
            $hold = $this->database->get('loyalty_holds', $holdUuid);
            if ($hold['state'] === 'released') {
                return $this->safeHold($hold);
            }
            if ($hold['state'] !== 'reserved') {
                throw new \RuntimeException('Only reserved points can be released.');
            }
            $account = $this->account($hold['profile_id'], $hold['program_id'], true);
            $this->database->update('program_accounts', $account['uuid'], ['held' => (int) $account['held'] - (int) $hold['points']], (int) $account['row_version']);
            return $this->safeHold($this->database->update('loyalty_holds', $holdUuid, ['state' => 'released']));
        });
    }

    public function expire(): array
    {
        $db = $this->database->db();
        $now = Database::now();
        $count = 0;
        $holds = $db->get_col($db->prepare('SELECT uuid FROM ' . $this->database->table('loyalty_holds') . ' WHERE state=%s AND expires_at<=%s ORDER BY expires_at,id LIMIT 100', 'reserved', $now));
        foreach ($holds as $uuid) {
            $this->release($uuid, 'expiry:' . $uuid);
            ++$count;
        }
        $lots = $db->get_results($db->prepare('SELECT id,uuid,profile_id,program_id FROM ' . $this->database->table('loyalty_lots') . ' WHERE remaining>0 AND expires_at<=%s ORDER BY expires_at,id LIMIT 100', $now), ARRAY_A);
        foreach ($lots as $candidate) {
            $this->database->transaction(function () use ($candidate, &$count): void {
                $this->lockProfile($candidate['profile_id'], true);
                $lot = $this->database->get('loyalty_lots', $candidate['uuid']);
                $db = $this->database->db();
                $held = (int) $db->get_var($db->prepare('SELECT COALESCE(SUM(a.points),0) FROM ' . $this->database->table('loyalty_allocations') . ' a INNER JOIN ' . $this->database->table('loyalty_holds') . ' h ON h.id=a.hold_id WHERE a.lot_id=%d AND h.state=%s', $lot['id'], 'reserved'));
                $expire = max(0, (int) $lot['remaining'] - $held);
                if ($expire === 0) {
                    return;
                }
                $this->post($lot['profile_id'], $lot['program_id'], -$expire, 'expire', 'expire:' . $lot['uuid'] . ':' . $lot['remaining'] . ':' . $lot['row_version'], null, (int) $lot['ledger_id'], 'Point lot expiry');
                $this->database->update('loyalty_lots', $lot['uuid'], ['remaining' => $held]);
                ++$count;
            });
        }
        return ['processed' => $count];
    }

    public function createReferral(string $programUuid, string $referrerUuid): array
    {
        [$profile, $program, , $version] = $this->references($referrerUuid, $programUuid);
        return $this->safeReferral($this->database->insert('referrals', ['program_id' => $program['id'], 'policy_version_id' => $version['id'], 'referrer_id' => $profile['id'], 'referee_id' => null, 'code' => bin2hex(random_bytes(16)), 'state' => 'created']));
    }

    public function claimReferral(string $code, string $refereeUuid): array
    {
        return $this->database->transaction(function () use ($code, $refereeUuid): array {
            $referral = $this->database->find('referrals', 'code', $code) ?? throw new \RuntimeException('Referral not found.');
            $profile = $this->database->get('profiles', $refereeUuid) ?? throw new \RuntimeException('Contact not found.');
            $this->lockProfile($profile['id']);
            if ($profile['state'] !== 'active' || (int) $referral['referrer_id'] === (int) $profile['id']) {
                throw new \RuntimeException('Self-referral or unavailable contact.');
            }
            if ($referral['referee_id']) {
                if ((int) $referral['referee_id'] !== (int) $profile['id']) {
                    throw new \RuntimeException('Referral already claimed.');
                }
                return $this->safeReferral($referral);
            }
            if ($referral['state'] !== 'created') {
                throw new \RuntimeException('Referral no longer claimable.');
            }
            $key = hash('sha256', $referral['program_id'] . ':' . $profile['id']);
            $other = $this->database->find('referrals', 'qualified_key', $key);
            if ($other) {
                throw new \RuntimeException('Referee has already claimed this program.');
            }
            return $this->safeReferral($this->database->update('referrals', $referral['uuid'], ['referee_id' => $profile['id'], 'qualified_key' => $key, 'state' => 'qualified'], (int) $referral['row_version']));
        });
    }

    public function referrals(int $limit = 25, int $after = 0): array
    {
        return array_map([$this, 'safeReferral'], $this->database->list('referrals', [], $limit, $after));
    }
    public function commissions(int $limit = 25, int $after = 0): array
    {
        return array_map([$this, 'safeCommission'], $this->database->list('commissions', [], $limit, $after));
    }

    public function recordCommission(string $programUuid, string $affiliateUuid, int $orderId, int $baseMinor, string $currency, string $operationKey): array
    {
        [$affiliate, $program, $policy, $version] = $this->references($affiliateUuid, $programUuid);
        if ($baseMinor < 0 || $currency !== ($policy['currency'] ?? '')) {
            throw new \InvalidArgumentException('Commission base/currency mismatch.');
        }
        $order = wc_get_order($orderId);
        if (!$order || !$order->get_date_paid() || $order->has_status(['cancelled','failed','refunded']) || $order->get_currency() !== $currency || ((int)$affiliate['user_id'] > 0 && (int)$affiliate['user_id'] === (int)$order->get_customer_id())) {
            throw new \RuntimeException('A paid canonical order and independent affiliate are required.');
        }
        $canonicalBase = 0;
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            } $canonicalBase += Money::fromDecimal((string)$item->get_total(), $currency, (int)($policy['exponent'] ?? 2))->minor;
        }
        if ($baseMinor !== $canonicalBase) {
            throw new \RuntimeException('Commission base must equal canonical post-discount merchandise.');
        }
        $rate = (int) ($policy['rate_bps'] ?? 1000);
        $amount = self::checkedAdd(self::proportion($baseMinor, $rate, 10000, true), (int)($policy['fixed_minor'] ?? 0));
        return $this->database->transaction(function () use ($affiliate, $program, $version, $orderId, $baseMinor, $currency, $operationKey, $policy, $amount): array {
            $this->lockProfile($affiliate['id']);
            $key = hash('sha256', 'commission:' . $operationKey);
            $old = $this->database->find('commissions', 'operation_key', $key);
            if ($old) {
                if ((int) $old['order_id'] !== $orderId || (int) $old['affiliate_id'] !== (int) $affiliate['id'] || (int) $old['base_minor'] !== $baseMinor) {
                    throw new \RuntimeException('Commission key conflict.');
                }
                return $this->safeCommission($old);
            }
            $row = $this->database->insert('commissions', ['program_id' => $program['id'], 'affiliate_id' => $affiliate['id'], 'order_id' => $orderId, 'base_minor' => $baseMinor, 'amount_minor' => $amount, 'currency' => $currency, 'exponent' => (int) ($policy['exponent'] ?? 2), 'policy_version_id' => $version['id'], 'state' => 'held', 'operation_key' => $key, 'hold_until' => gmdate('Y-m-d H:i:s', time() + (int) ($policy['hold_days'] ?? 30) * 86400)]);
            return $this->safeCommission($row);
        });
    }

    public function approveCommission(string $uuid): array
    {
        $row = $this->database->get('commissions', $uuid) ?? throw new \RuntimeException('Commission not found.');
        if ($row['state'] === 'approved') {
            return $this->safeCommission($row);
        }
        if ($row['state'] !== 'held' || $row['hold_until'] > Database::now() || (int) $row['amount_minor'] <= 0) {
            throw new \RuntimeException('Commission is held, reversed or ineligible.');
        }
        $order = wc_get_order((int) $row['order_id']);
        if (!$order || !$order->get_date_paid() || $order->has_status(['cancelled', 'failed', 'refunded'])) {
            throw new \RuntimeException('Canonical order is not payout eligible.');
        }
        foreach ($order->get_refunds() as $refund) {
            if (!$refund->get_items('line_item') && (float)$refund->get_amount() > 0) {
                throw new \RuntimeException('Unallocated refund requires commission review.');
            }
        }
        $program = $this->database->find('definitions', 'id', (int)$row['program_id']) ?? throw new \RuntimeException('Commission program unavailable.');
        $this->reconcileCommissions($program, $order, $this->orderNet($order, (int)$row['exponent']), max(0, (int)$row['id'] - 1));
        $netAmount = (int)$row['amount_minor'] + (int)$this->database->db()->get_var($this->database->db()->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM ' . $this->database->table('commissions') . ' WHERE reverses_id=%d', $row['id']));
        if ($netAmount <= 0) {
            throw new \RuntimeException('Commission has no remaining approved amount.');
        }
        $this->audit->record('commission.approved', $uuid);
        return $this->safeCommission($this->database->update('commissions', $uuid, ['state' => 'approved'], (int) $row['row_version']));
    }

    /** Records confirmed external payment only; export is not a payment. */
    public function markPayout(array $commissionUuids, string $externalReference, string $operationKey): array
    {
        if (!$commissionUuids || count($commissionUuids) > 100 || strlen($externalReference) < 3 || strlen($externalReference) > 191) {
            throw new \InvalidArgumentException('Confirmed payout reference and 1..100 commissions required.');
        }
        if (count(array_unique($commissionUuids)) !== count($commissionUuids)) {
            throw new \InvalidArgumentException('Duplicate payout item.');
        }
        sort($commissionUuids, SORT_STRING);
        return $this->database->transaction(function () use ($commissionUuids, $externalReference, $operationKey): array {
            $digest = hash('sha256', $operationKey . ':' . $externalReference . ':' . implode(',', $commissionUuids));
            $payout = substr($digest, 0, 8) . '-' . substr($digest, 8, 4) . '-' . substr($digest, 12, 4) . '-' . substr($digest, 16, 4) . '-' . substr($digest, 20, 12);
            $currency = null;
            $total = 0;
            $beneficiary = null;
            $exponent = null;
            foreach ($commissionUuids as $uuid) {
                $row = $this->database->get('commissions', $uuid) ?? throw new \RuntimeException('Commission not found.');
                $this->lockProfile($row['affiliate_id'], true);
                if ($beneficiary !== null && ($beneficiary !== (int)$row['affiliate_id'] || $exponent !== (int)$row['exponent'])) {
                    throw new \RuntimeException('A payout must share one beneficiary and currency exponent.');
                }
                $beneficiary = (int)$row['affiliate_id'];
                $exponent = (int)$row['exponent'];
                $effective = (int)$row['amount_minor'] + (int)$this->database->db()->get_var($this->database->db()->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM ' . $this->database->table('commissions') . ' WHERE reverses_id=%d AND payout_uuid IS NULL', $row['id']));
                if ($row['state'] === 'paid' && $row['payout_uuid'] === $payout) {
                    $effective = (int)$row['amount_minor'] + (int)$this->database->db()->get_var($this->database->db()->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM ' . $this->database->table('commissions') . ' WHERE reverses_id=%d AND payout_uuid=%s', $row['id'], $payout));
                    $total = self::checkedAdd($total, $effective);
                    $currency = $row['currency'];
                    continue;
                }
                if ($row['state'] !== 'approved' || ($currency !== null && $currency !== $row['currency'])) {
                    throw new \RuntimeException('Payout contains ineligible or mixed currency commissions.');
                }
                $currency = $row['currency'];
                if ($effective <= 0) {
                    throw new \RuntimeException('Commission has no payable balance.');
                }
                foreach ($this->database->list('commissions', ['reverses_id' => $row['id']], 1000) as $adjustment) {
                    if (!$adjustment['payout_uuid']) {
                        $this->database->update('commissions', $adjustment['uuid'], ['payout_uuid' => $payout,'paid_at' => Database::now()]);
                    }
                }
                $this->database->update('commissions', $uuid, ['state' => 'paid', 'payout_uuid' => $payout, 'paid_at' => Database::now()], (int) $row['row_version']);
                $total = self::checkedAdd($total, $effective);
            }
            $this->audit->record('payout.confirmed', null, ['payout' => $payout, 'reference_hash' => hash('sha256', $externalReference), 'count' => count($commissionUuids)]);
            return ['payout_reference' => $payout, 'amount_minor' => (string) $total, 'currency' => $currency, 'state' => 'paid'];
        });
    }

    public function reconcileOrder(\WC_Order $order, int $after = 0): void
    {
        $customer = (int) $order->get_customer_id();
        if (!$order->get_date_paid()) {
            return;
        }
        $profile = $customer > 0 ? $this->database->find('profiles', 'user_id', $customer) : null;
        if ($after === 0) {
            $this->reconcileRedemptions($order);
        }
        $currency = $order->get_currency();
        $page = $this->database->list('definitions', ['kind' => 'program'], 100, $after);
        foreach ($page as $program) {
            if (!$program['published_version_id']) {
                continue;
            }
            $version = $this->database->find('definition_versions', 'id', (int) $program['published_version_id']);
            $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
            $sameCurrency = ($policy['currency'] ?? '') === $currency;
            $exponent = (int) ($policy['exponent'] ?? 2);
            $base = 0;
            $refund = 0;
            foreach ($order->get_items('line_item') as $item) {
                if (!$item instanceof \WC_Order_Item_Product) {
                    continue;
                } $base += Money::fromDecimal((string) $item->get_total(), $currency, $exponent)->minor;
            }
            foreach ($order->get_refunds() as $refundOrder) {
                foreach ($refundOrder->get_items('line_item') as $item) {
                    if (!$item instanceof \WC_Order_Item_Product) {
                        continue;
                    } $refund += abs(Money::fromDecimal((string) $item->get_total(), $currency, $exponent)->minor);
                }
            }
            $net = $order->has_status(['cancelled', 'failed', 'refunded']) ? 0 : max(0, $base - $refund);
            $key = 'order-earn:' . $program['uuid'] . ':' . $order->get_id();
            $original = $this->database->find('ledger', 'operation_key', hash('sha256', $key));
            if ($original || ($profile && $sameCurrency && $policy['type'] === 'loyalty')) {
                $pinned = $original && $original['policy_version_id'] ? $this->database->find('definition_versions', 'id', (int) $original['policy_version_id']) : $version;
                $earnPolicy = json_decode($pinned['body'], true, 32, JSON_THROW_ON_ERROR);
                $earnExponent = (int)($earnPolicy['exponent'] ?? 2);
                $earnNet = $this->orderNet($order, $earnExponent);
                $points = self::proportion($earnNet, (int)($earnPolicy['points_per_major'] ?? 1), 10 ** $earnExponent);
                if (!$original && $points > 0 && $profile['state'] === 'active' && $program['state'] === 'active' && in_array('programs', (array)(get_option('wmos_settings', [])['enabled_modules'] ?? []), true)) {
                    $this->earn($profile['uuid'], $program['uuid'], $points, $key, (int) $order->get_id());
                } elseif ($original) {
                    $this->reconcileEarn($original, $points, $this->sourceDigest($order));
                }
            }
            $this->reconcileReferrals($profile, $program, $order, $net);
            $this->reconcileCommissions($program, $order, $net);
        }
        if (count($page) === 100) {
            $cursor = (int)$page[array_key_last($page)]['id'];
            $this->queue->enqueue('programs.order_reconcile', ['order_id' => $order->get_id(),'after' => $cursor], 'program-page:' . $order->get_id() . ':' . hash('sha256', $this->sourceDigest($order)) . ':' . $cursor);
        }
    }

    private function effectHead(int $originalId, string $table): int
    {
        $db = $this->database->db();
        return (int)$db->get_var($db->prepare('SELECT COALESCE(MAX(id),0) FROM ' . $this->database->table($table) . ' WHERE reverses_id=%d', $originalId));
    }
    private function sourceDigest(\WC_Order $order): string
    {
        $refunds = [];
        foreach ($order->get_refunds() as $refund) {
            $items = [];
            foreach ($refund->get_items('line_item') as $item) {
                if ($item instanceof \WC_Order_Item_Product) {
                    $items[(int)$item->get_id()] = [(string)$item->get_total(),(string)$item->get_quantity()];
                }
            }ksort($items);
            $refunds[(int)$refund->get_id()] = [(string)$refund->get_amount(),$items];
        }ksort($refunds);
        return hash('sha256', json_encode([(int)$order->get_id(),$order->get_status(),$order->get_currency(),(string)$order->get_total(),$refunds], JSON_THROW_ON_ERROR));
    }

    private function orderNet(\WC_Order $order, int $exponent): int
    {
        if ($order->has_status(['cancelled','failed','refunded'])) {
            return 0;
        }$base = 0;
        $refund = 0;
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $base = self::checkedAdd($base, Money::fromDecimal((string)$item->get_total(), $order->get_currency(), $exponent)->minor);
            }
        }
        foreach ($order->get_refunds() as $refundOrder) {
            foreach ($refundOrder->get_items('line_item') as $item) {
                if ($item instanceof \WC_Order_Item_Product) {
                    $refund = self::checkedAdd($refund, abs(Money::fromDecimal((string)$item->get_total(), $order->get_currency(), $exponent)->minor));
                }
            }
        }
        return max(0, $base - $refund);
    }

    private function reconcileRedemptions(\WC_Order $order, int $after = 0): void
    {
        $page = $this->database->list('ledger', ['source_order_id' => $order->get_id(),'kind' => 'redeem'], 100, $after);
        foreach ($page as $original) {
            if ((int)$original['amount_minor'] < 1) {
                continue;
            }$refund = 0;
            foreach ($order->get_refunds() as $refundOrder) {
                foreach ($refundOrder->get_items('line_item') as $item) {
                    if (!$item instanceof \WC_Order_Item_Product) {
                        continue;
                    }$refund += abs(Money::fromDecimal((string)$item->get_total(), $original['currency'], (int)$original['exponent'])->minor);
                }
            }
            $spent = -(int)$original['points'];
            $target = $order->has_status(['cancelled','failed','refunded']) ? $spent : self::proportion(min($refund, (int)$original['amount_minor']), $spent, (int)$original['amount_minor'], true);
            $sourceDigest = $this->sourceDigest($order);
            $this->database->transaction(function () use ($original, $target, $spent, $sourceDigest): void {
                $this->lockProfile($original['profile_id'], true);
                $db = $this->database->db();
                $current = (int)$db->get_var($db->prepare('SELECT COALESCE(SUM(points),0) FROM ' . $this->database->table('ledger') . ' WHERE reverses_id=%d', $original['id']));
                $delta = $target - $current;
                if (!$delta) {
                    return;
                }
                $entry = $this->post($original['profile_id'], $original['program_id'], $delta, 'refund', 'redemption-refund:' . $original['uuid'] . ':' . $target . ':' . $sourceDigest . ':' . $this->effectHead((int)$original['id'], 'ledger'), (int)$original['source_order_id'], (int)$original['id'], 'Cumulative proportional redemption return', (int)$original['policy_version_id']);
                $allocations = $this->database->list('loyalty_allocations', ['ledger_id' => $original['id']], 1000);
                $targets = [];
                $sum = 0;
                foreach ($allocations as $allocation) {
                    $part = self::proportion((int)$allocation['points'], $target, $spent);
                    $targets[$allocation['lot_id']] = ($targets[$allocation['lot_id']] ?? 0) + $part;
                    $sum += $part;
                }
                foreach ($allocations as $allocation) {
                    if ($sum >= $target) {
                        break;
                    }++$targets[$allocation['lot_id']];
                    ++$sum;
                }
                foreach ($targets as $lotId => $desired) {
                    $returned = -(int)$db->get_var($db->prepare('SELECT COALESCE(SUM(a.points),0) FROM ' . $this->database->table('loyalty_allocations') . ' a INNER JOIN ' . $this->database->table('ledger') . ' l ON l.id=a.ledger_id WHERE a.lot_id=%d AND l.reverses_id=%d', $lotId, $original['id']));
                    $change = $desired - $returned;
                    if (!$change) {
                        continue;
                    }
                    $lot = $this->database->find('loyalty_lots', 'id', (int)$lotId) ?? throw new \RuntimeException('Redemption allocation provenance unavailable.');
                    $this->database->insert('loyalty_allocations', ['lot_id' => $lotId,'ledger_id' => $entry['id'],'hold_id' => null,'points' => -$change,'operation_key' => hash('sha256', 'redemption-return:' . $entry['uuid'] . ':' . $lot['uuid'])]);
                    $this->reconcileLot($lot, $sourceDigest);
                }
            });
        }
        if (count($page) === 100) {
            $cursor = (int)$page[array_key_last($page)]['id'];
            $this->queue->enqueue('programs.redemptions', ['order_id' => $order->get_id(),'after' => $cursor], 'redemption-page:' . $order->get_id() . ':' . $this->sourceDigest($order) . ':' . $cursor);
        }
    }

    private function reconcileEarn(array $original, int $target, string $sourceDigest): void
    {
        $this->database->transaction(function () use ($original, $target, $sourceDigest): void {
            $this->lockProfile($original['profile_id'], true);
            $db = $this->database->db();
            $current = (int) $original['points'] + (int) $db->get_var($db->prepare('SELECT COALESCE(SUM(points),0) FROM ' . $this->database->table('ledger') . " WHERE reverses_id=%d AND kind='reverse'", $original['id']));
            $delta = $target - $current;
            if ($delta === 0) {
                return;
            }
            $this->post($original['profile_id'], $original['program_id'], $delta, 'reverse', 'earn-correction:' . $original['uuid'] . ':' . $target . ':' . $sourceDigest . ':' . $this->effectHead((int)$original['id'], 'ledger'), (int) $original['source_order_id'], (int) $original['id'], 'Canonical cumulative refund correction', (int) $original['policy_version_id']);
            $lot = $this->database->find('loyalty_lots', 'ledger_id', (int) $original['id']);
            if ($lot && $delta < 0) {
                $ids = $db->get_col($db->prepare('SELECT DISTINCT h.uuid FROM ' . $this->database->table('loyalty_holds') . ' h INNER JOIN ' . $this->database->table('loyalty_allocations') . ' a ON a.hold_id=h.id WHERE a.lot_id=%d AND h.state=%s', $lot['id'], 'reserved'));
                foreach ($ids as $holdUuid) {
                    $this->release($holdUuid, 'refund-release:' . $original['uuid'] . ':' . $holdUuid);
                }
            }
            if ($lot) {
                $this->reconcileLot($lot, $sourceDigest);
            }
        });
    }

    /** Expiry and spent points are distinct: expired points never become refundable debt or new spendable lots. */
    private function reconcileLot(array $lot, string $sourceDigest): void
    {
        $db = $this->database->db();
        $original = $this->database->find('ledger', 'id', (int)$lot['ledger_id']) ?? throw new \RuntimeException('Point provenance unavailable.');
        $budget = (int)$original['points'] + (int)$db->get_var($db->prepare("SELECT COALESCE(SUM(points),0) FROM " . $this->database->table('ledger') . " WHERE reverses_id=%d AND kind='reverse'", $original['id']));
        $consumed = (int)$db->get_var($db->prepare('SELECT COALESCE(SUM(points),0) FROM ' . $this->database->table('loyalty_allocations') . ' WHERE lot_id=%d AND ledger_id IS NOT NULL', $lot['id']));
        $expired = -(int)$db->get_var($db->prepare("SELECT COALESCE(SUM(points),0) FROM " . $this->database->table('ledger') . " WHERE reverses_id=%d AND kind='expire'", $original['id']));
        $released = (int)$db->get_var($db->prepare("SELECT COALESCE(SUM(points),0) FROM " . $this->database->table('ledger') . " WHERE reverses_id=%d AND kind='expiry_refund'", $original['id']));
        $desiredRelease = max(0, $expired - max(0, $budget - $consumed));
        $delta = $desiredRelease - $released;
        if ($delta) {
            $this->post($original['profile_id'], $original['program_id'], $delta, 'expiry_refund', 'expiry-reconcile:' . $lot['uuid'] . ':' . $budget . ':' . $consumed . ':' . $expired . ':' . $released . ':' . $sourceDigest . ':' . $this->effectHead((int)$original['id'], 'ledger'), (int)$original['source_order_id'], (int)$original['id'], 'Expired points do not create refund debt', $original['policy_version_id'] ? (int)$original['policy_version_id'] : null);
        }
        $remaining = max(0, $budget - $consumed - ($expired - $desiredRelease));
        $this->database->update('loyalty_lots', $lot['uuid'], ['remaining' => $remaining]);
    }

    private function reconcileReferrals(?array $profile, array $program, \WC_Order $order, int $net, int $after = 0): void
    {
        $filters = ['program_id' => $program['id']] + ($profile ? ['referee_id' => $profile['id']] : ['order_id' => $order->get_id()]);
        $page = $this->database->list('referrals', $filters, 100, $after);
        foreach ($page as $referral) {
            $profile = $this->database->find('profiles', 'id', (int)$referral['referee_id']);
            if (!$profile) {
                continue;
            }
            if ($referral['order_id'] && (int) $referral['order_id'] !== (int) $order->get_id()) {
                continue;
            }
            $version = $this->database->find('definition_versions', 'id', (int) $referral['policy_version_id']);
            $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
            $net = $this->orderNet($order, (int)($policy['exponent'] ?? 2));
            $eligible = $net >= max(1, (int) ($policy['minimum_minor'] ?? 0)) && time() >= $order->get_date_paid()->getTimestamp() + (int) ($policy['hold_days'] ?? 30) * 86400;
            if (!$eligible && $net > 0 && $referral['state'] === 'qualified') {
                $due = max(1, $order->get_date_paid()->getTimestamp() + (int)($policy['hold_days'] ?? 30) * 86400 - time());
                $this->queue->enqueue('programs.order', ['order_id' => $order->get_id()], 'referral-maturity:' . $referral['uuid'] . ':' . $order->get_id(), $due);
            }
            if ($eligible && $referral['state'] === 'qualified' && $profile['state'] === 'active' && $program['state'] === 'active' && in_array('programs', (array)(get_option('wmos_settings', [])['enabled_modules'] ?? []), true)) {
                $referrer = $this->database->find('profiles', 'id', (int) $referral['referrer_id']);
                if (!$referrer || $referrer['state'] !== 'active') {
                    continue;
                }
                $first = wc_get_orders(['customer_id' => (int)$order->get_customer_id(),'limit' => 1,'orderby' => 'date','order' => 'ASC','status' => ['processing','completed'],'return' => 'ids']);
                if (!$first || (int)$first[0] !== (int)$order->get_id()) {
                    continue;
                }
                $this->database->transaction(function () use ($referral, $referrer, $profile, $program, $policy, $order): void {
                    $ids = [(int) $referrer['id'], (int) $profile['id']];
                    sort($ids);
                    foreach ($ids as $id) {
                        $this->lockProfile($id);
                    }
                    foreach ([[$referrer, 'referrer_points'], [$profile, 'referee_points']] as [$beneficiary, $field]) {
                        $points = (int) ($policy[$field] ?? 0);
                        if ($points > 0) {
                            $this->earn($beneficiary['uuid'], $program['uuid'], $points, 'referral-reward:' . $referral['uuid'] . ':' . $field, (int) $order->get_id(), (int)$referral['policy_version_id']);
                        }
                    }
                    $this->database->update('referrals', $referral['uuid'], ['state' => 'rewarded', 'order_id' => $order->get_id()]);
                });
            } elseif (!$eligible && $referral['state'] === 'rewarded' && $net === 0) {
                foreach (['referrer_points', 'referee_points'] as $field) {
                    $entry = $this->database->find('ledger', 'operation_key', hash('sha256', 'referral-reward:' . $referral['uuid'] . ':' . $field));
                    if ($entry) {
                        $this->reconcileEarn($entry, 0, $this->sourceDigest($order));
                    }
                }
                $this->database->update('referrals', $referral['uuid'], ['state' => 'reversed']);
            }
        }
        if (count($page) === 100) {
            $cursor = (int)$page[array_key_last($page)]['id'];
            $version = $this->database->find('definition_versions', 'id', (int)$program['published_version_id']);
            $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
            $this->queue->enqueue('programs.referrals', ['order_id' => $order->get_id(),'program_uuid' => $program['uuid'],'profile_uuid' => $profile['uuid'],'exponent' => (int)($policy['exponent'] ?? 2),'after' => $cursor], 'referral-page:' . $order->get_id() . ':' . $program['uuid'] . ':' . $this->sourceDigest($order) . ':' . $cursor);
        }
    }

    private function reconcileCommissions(array $program, \WC_Order $order, int $net, int $after = 0): void
    {
        $page = $this->database->list('commissions', ['program_id' => $program['id'],'order_id' => $order->get_id()], 100, $after);
        foreach ($page as $commission) {
            if ($commission['currency'] !== $order->get_currency()) {
                continue;
            }$net = $this->orderNet($order, (int)$commission['exponent']);
            if ($commission['reverses_id']) {
                continue;
            }
            $sourceDigest = $this->sourceDigest($order);
            $this->database->transaction(function () use ($commission, $net, $sourceDigest): void {
                $this->lockProfile($commission['affiliate_id'], true);
                $db = $this->database->db();
                $current = (int) $commission['amount_minor'] + (int) $db->get_var($db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM ' . $this->database->table('commissions') . ' WHERE reverses_id=%d', $commission['id']));
                $target = self::proportion(min($net, (int) $commission['base_minor']), (int) $commission['amount_minor'], max(1, (int) $commission['base_minor']), true);
                if ($target === $current) {
                    return;
                }
                $key = hash('sha256', 'commission-correction:' . $commission['uuid'] . ':' . $target . ':' . $sourceDigest . ':' . $this->effectHead((int)$commission['id'], 'commissions'));
                if (!$this->database->find('commissions', 'operation_key', $key)) {
                    $this->database->insert('commissions', ['program_id' => $commission['program_id'], 'affiliate_id' => $commission['affiliate_id'], 'order_id' => $commission['order_id'], 'base_minor' => 0, 'amount_minor' => $target - $current, 'currency' => $commission['currency'], 'exponent' => $commission['exponent'], 'policy_version_id' => $commission['policy_version_id'], 'state' => 'adjustment', 'operation_key' => $key, 'reverses_id' => $commission['id']]);
                }
            });
        }
        if (count($page) === 100) {
            $cursor = (int)$page[array_key_last($page)]['id'];
            $this->queue->enqueue('programs.commissions', ['order_id' => $order->get_id(),'program_uuid' => $program['uuid'],'exponent' => (int)$page[0]['exponent'],'after' => $cursor], 'commission-page:' . $order->get_id() . ':' . $program['uuid'] . ':' . $this->sourceDigest($order) . ':' . $cursor);
        }
    }

    private function consumeAvailableLots(array $entry, int $points): void
    {
        $db = $this->database->db();
        $left = $points;
        foreach ($this->database->list('loyalty_lots', ['profile_id' => $entry['profile_id'],'program_id' => $entry['program_id']], 1000) as $lot) {
            $held = (int)$db->get_var($db->prepare('SELECT COALESCE(SUM(a.points),0) FROM ' . $this->database->table('loyalty_allocations') . ' a INNER JOIN ' . $this->database->table('loyalty_holds') . ' h ON h.id=a.hold_id WHERE a.lot_id=%d AND h.state=%s', $lot['id'], 'reserved'));
            $take = min($left, max(0, (int)$lot['remaining'] - $held));
            if (!$take) {
                continue;
            }
            $this->database->insert('loyalty_allocations', ['lot_id' => $lot['id'],'ledger_id' => $entry['id'],'hold_id' => null,'points' => $take,'operation_key' => hash('sha256', 'adjust-lot:' . $entry['uuid'] . ':' . $lot['uuid'])]);
            $this->database->update('loyalty_lots', $lot['uuid'], ['remaining' => (int)$lot['remaining'] - $take]);
            $left -= $take;
            if (!$left) {
                return;
            }
        }
        throw new \RuntimeException('Point lot integrity prevents this adjustment.');
    }

    private function references(string $profileUuid, string $programUuid): array
    {
        if (!get_option('wmos_active', false) || !in_array('programs', (array)(get_option('wmos_settings', [])['enabled_modules'] ?? []), true)) {
            throw new \RuntimeException('Programs module is disabled.');
        }
        $profile = $this->database->get('profiles', $profileUuid) ?? throw new \RuntimeException('Contact not found.');
        $program = $this->database->get('definitions', $programUuid) ?? throw new \RuntimeException('Program not found.');
        if ($profile['state'] !== 'active' || $program['kind'] !== 'program' || $program['state'] !== 'active' || !$program['published_version_id']) {
            throw new \RuntimeException('Active contact and published program required.');
        }
        $version = $this->database->find('definition_versions', 'id', (int) $program['published_version_id']);
        $policy = json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR);
        if (empty($policy['financial_retention_days'])) {
            throw new \RuntimeException('Configure a financial retention policy before running programs.');
        }
        return [$profile, $program, $policy, $version];
    }

    private function post(int|string $profileId, int|string $programId, int $points, string $kind, string $key, ?int $orderId = null, ?int $reversesId = null, ?string $reason = null, ?int $policyVersion = null, ?array $money = null): array
    {
        $hash = hash('sha256', $key);
        $old = $this->database->find('ledger', 'operation_key', $hash);
        if ($old) {
            if ((int) $old['profile_id'] !== (int) $profileId || (int) $old['program_id'] !== (int) $programId || (int) $old['points'] !== $points || $old['kind'] !== $kind) {
                throw new \RuntimeException('Ledger operation key conflict.');
            } return $old;
        }
        $account = $this->account($profileId, $programId, true);
        $balance = self::checkedAdd((int)$account['balance'], $points);
        $row = $this->database->insert('ledger', ['profile_id' => $profileId, 'program_id' => $programId, 'kind' => $kind, 'points' => $points, 'operation_key' => $hash, 'source_order_id' => $orderId, 'reverses_id' => $reversesId, 'reason' => $reason, 'policy_version_id' => $policyVersion] + ($money ? array_intersect_key($money, array_flip(['amount_minor','currency','exponent'])) : []));
        $this->database->update('program_accounts', $account['uuid'], ['balance' => $balance], (int) $account['row_version']);
        return $row;
    }

    private function account(int|string $profileId, int|string $programId, bool $create): ?array
    {
        $db = $this->database->db();
        $row = $db->get_row($db->prepare('SELECT id,uuid,balance,held,row_version FROM ' . $this->database->table('program_accounts') . ' WHERE profile_id=%d AND program_id=%d' . ($create ? ' FOR UPDATE' : ''), $profileId, $programId), ARRAY_A);
        return $row ?: ($create ? $this->database->insert('program_accounts', ['profile_id' => $profileId, 'program_id' => $programId, 'balance' => 0, 'held' => 0]) : null);
    }

    private function lockProfile(int|string $id, bool $financialCorrection = false): void
    {
        $row = $this->database->db()->get_row($this->database->db()->prepare('SELECT state FROM ' . $this->database->table('profiles') . ' WHERE id=%d FOR UPDATE', $id), ARRAY_A);
        if (!$row || (!$financialCorrection && $row['state'] !== 'active')) {
            throw new \RuntimeException('Contact is not active.');
        }
    }

    private static function checkedAdd(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new \OverflowException('Financial integer overflow.');
        }
        return $a + $b;
    }

    /** Exact multiply/divide without an overflowing intermediate product. */
    public static function proportion(int $base, int $numerator, int $denominator, bool $round = false): int
    {
        if ($base < 0 || $numerator < 0 || $denominator < 1) {
            throw new \InvalidArgumentException('Invalid proportional arithmetic.');
        }
        $whole = intdiv($base, $denominator);
        $remainder = $base % $denominator;
        if ($numerator && $whole > intdiv(PHP_INT_MAX, $numerator)) {
            throw new \OverflowException('Proportional arithmetic overflow.');
        }
        $quotient = 0;
        $mod = 0;
        foreach (str_split(decbin($numerator)) as $bit) {
            if ($quotient > intdiv(PHP_INT_MAX, 2)) {
                throw new \OverflowException('Proportional arithmetic overflow.');
            }
            $quotient *= 2;
            if ($mod >= $denominator - $mod) {
                $mod -= $denominator - $mod;
                ++$quotient;
            } else {
                $mod *= 2;
            }
            if ($bit === '1') {
                if ($mod >= $denominator - $remainder) {
                    $mod -= $denominator - $remainder;
                    ++$quotient;
                } else {
                    $mod += $remainder;
                }
            }
        }
        if ($round && $mod >= intdiv($denominator, 2) + ($denominator % 2)) {
            ++$quotient;
        }
        $result = $whole * $numerator;
        if ($quotient > PHP_INT_MAX - $result) {
            throw new \OverflowException('Proportional arithmetic overflow.');
        }
        return $result + $quotient;
    }

    private function safeLedger(array $row): array
    {
        return array_intersect_key($row, array_flip(['uuid', 'kind', 'points', 'amount_minor', 'currency', 'reason', 'created_at']));
    }
    private function safeHold(array $row): array
    {
        return array_intersect_key($row, array_flip(['uuid', 'points', 'state', 'expires_at']));
    }
    private function safeReferral(array $row): array
    {
        return array_intersect_key($row, array_flip(['uuid', 'code', 'state', 'created_at']));
    }
    private function safeCommission(array $row): array
    {
        return array_intersect_key($row, array_flip(['uuid', 'order_id', 'amount_minor', 'currency', 'exponent', 'state', 'hold_until', 'paid_at', 'created_at']));
    }
}
