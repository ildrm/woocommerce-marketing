<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Domain\Money;
use Wmos\Infrastructure\Audit;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;

/** Current WooCommerce facts plus revision-idempotent deterministic reporting. */
final class Measurement
{
    public const MODELS = ['first_touch', 'last_touch', 'last_non_direct', 'linear', 'time_decay', 'position_based', 'custom'];
    private ?\Wmos\Infrastructure\Queue $queue = null;
    public function __construct(private Database $database, private Audit $audit)
    {
    }
    public function setQueue(\Wmos\Infrastructure\Queue $queue): void
    {
        $this->queue = $queue;
        $queue->register('experiment_conversion', function (array $job, array $payload): void {
            $this->markExperimentConversions((int) $payload['profile_id'], (int) $payload['cursor'], $payload['generation']);
        });
    }

    public function reconcile(\WC_Order $order): void
    {
        $currency = $order->get_currency();
        $existing = $this->database->find('conversions', 'order_id', $order->get_id());
        $exponent = $existing ? (int) $existing['exponent'] : (function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2);
        $total = Money::fromDecimal((string) $order->get_total(), $currency, $exponent);
        $tax = Money::fromDecimal((string) $order->get_total_tax(), $currency, $exponent);
        $shipping = Money::fromDecimal((string) $order->get_shipping_total(), $currency, $exponent);
        $refund = new Money(0, $currency, $exponent);
        $refundTax = new Money(0, $currency, $exponent);
        $refundShipping = new Money(0, $currency, $exponent);
        $refundObjects = $order->get_refunds();
        foreach ($refundObjects as $object) {
            $gross = Money::fromDecimal((string) $object->get_amount(), $currency, $exponent);
            $refund = $refund->add(new Money(abs($gross->minor), $currency, $exponent));
            $refundedTax = Money::fromDecimal((string) $object->get_total_tax(), $currency, $exponent);
            $refundTax = $refundTax->add(new Money(abs($refundedTax->minor), $currency, $exponent));
            $refundedShipping = Money::fromDecimal((string) $object->get_shipping_total(), $currency, $exponent);
            $refundShipping = $refundShipping->add(new Money(abs($refundedShipping->minor), $currency, $exponent));
        }
        $net = $total->add(new Money(-$tax->minor, $currency, $exponent))->add(new Money(-$shipping->minor, $currency, $exponent))->add(new Money(-$refund->minor, $currency, $exponent))->add($refundTax)->add($refundShipping);
        $paid = $order->get_date_paid();
        $eligible = $paid !== null && !in_array($order->get_status(), ['cancelled', 'failed', 'pending', 'on-hold'], true);
        $state = $eligible ? ($order->get_status() === 'refunded' ? 'refunded' : 'paid') : 'ineligible';
        $net = new Money($eligible ? max(0, $net->minor) : 0, $currency, $exponent);
        $created = $order->get_date_created();
        $paidAt = gmdate('Y-m-d H:i:s', ($paid ?? $created)?->getTimestamp() ?? 0);
        $digest = hash('sha256', Json::encode(['order' => $order->get_id(), 'state' => $state, 'minor' => $net->minor, 'currency' => $currency, 'exponent' => $exponent, 'paid' => $paidAt, 'refunds' => array_map(static fn($r): int => $r->get_id(), $refundObjects)]));
        $this->database->transaction(function () use ($order, $currency, $exponent, $net, $paidAt, $state, $digest): void {
            $wpdb = $this->database->db();
            $conversions = $this->database->table('conversions');
            $oldUuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$conversions} WHERE order_id=%d FOR UPDATE", $order->get_id()));
            $old = $oldUuid ? $this->database->get('conversions', $oldUuid) : null;
            if ($old && (int) $old['exponent'] !== $exponent) {
                throw new \Wmos\Infrastructure\ConflictException('Order monetary scale was pinned by a concurrent observer.');
            }
            if ($old && $old['source_digest'] === $digest) {
                return;
            }
            $profiles = $this->database->table('profiles');
            $profileId = $order->get_customer_id() ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$profiles} WHERE user_id=%d AND state='active'", $order->get_customer_id())) : null;
            $data = ['order_id' => $order->get_id(), 'profile_id' => $profileId ? (int) $profileId : null, 'state' => $state, 'net_minor' => $net->minor, 'currency' => $currency, 'exponent' => $exponent, 'paid_at' => $paidAt, 'source_digest' => $digest];
            $conversion = $old ? $this->database->update('conversions', $old['uuid'], $data, (int) $old['row_version']) : $this->database->insert('conversions', $data);
            $this->aggregateDelta($old, $conversion);
            $this->attribute($conversion);
            if ($profileId) {
                $this->profileMetrics((int) $profileId, $currency, $exponent);
                if ($this->queue) {
                    $this->queue->enqueue('experiment_conversion', ['profile_id' => (int) $profileId, 'cursor' => 0, 'generation' => $digest], 'experiment-conversion:' . $profileId . ':' . $digest . ':0');
                } else {
                    $this->markExperimentConversions((int) $profileId, 0, $digest);
                }
            }
            $this->audit->record($old ? 'conversion.corrected' : 'conversion.recorded', $conversion['uuid'], ['currency' => $currency, 'exponent' => $exponent]);
        });
    }

    public function track(array $row): void
    {
        $key = $row['event_key'] ?? hash('sha256', (string) ($row['uuid'] ?? Database::uuid()));
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            $key = hash('sha256', $key);
        }
        if ($this->database->find('touchpoints', 'event_key', $key)) {
            return;
        }
        $profile = isset($row['profile_uuid']) ? $this->database->get('profiles', $row['profile_uuid']) : null;
        if ($profile && $profile['state'] !== 'active') {
            return;
        }
        $this->database->insert('touchpoints', ['profile_id' => $profile['id'] ?? ($row['profile_id'] ?? null), 'session_hash' => $row['session_hash'] ?? null, 'link_id' => $row['link_id'] ?? null, 'definition_id' => $row['definition_id'] ?? null, 'channel' => $row['channel'] ?? 'direct', 'occurred_at' => $row['occurred_at'] ?? Database::now(), 'event_key' => $key]);
    }

    public function configureCustomModel(int $defaultWeight, array $channelWeights): void
    {
        if ($defaultWeight < 0 || $defaultWeight > 1000000 || count($channelWeights) > 20) {
            throw new \InvalidArgumentException('Invalid custom attribution weights.');
        }
        foreach ($channelWeights as $channel => $weight) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', (string) $channel) || !is_int($weight) || $weight < 0 || $weight > 1000000) {
                throw new \InvalidArgumentException('Invalid custom attribution channel weight.');
            }
        }
        $config = ['default_weight' => $defaultWeight, 'channel_weights' => $channelWeights];
        update_option('wmos_custom_attribution', $config, false);
        $this->audit->record('attribution.configured', null, ['digest' => hash('sha256', Json::encode($config))]);
    }

    public function reattribute(string $conversionUuid): void
    {
        $this->database->transaction(function () use ($conversionUuid): void {
            $wpdb = $this->database->db();
            $table = $this->database->table('conversions');
            $locked = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE uuid=%s FOR UPDATE", $conversionUuid));
            if (!$locked) {
                throw new \DomainException('Conversion not found.');
            }
            $this->attribute($this->database->get('conversions', $conversionUuid));
            $this->audit->record('attribution.rebuilt', $conversionUuid);
        });
    }

    public function recordCost(string $definitionUuid, int $minor, string $currency, int $exponent, string $operationKey, ?string $effectiveAt = null): array
    {
        $money = new Money($minor, $currency, $exponent);
        $definition = $this->database->get('definitions', $definitionUuid);
        if (!$definition || $definition['kind'] !== 'campaign' || $operationKey === '' || strlen($operationKey) > 191) {
            throw new \InvalidArgumentException('A campaign and stable cost operation key are required.');
        }
        $timestamp = $effectiveAt === null ? time() : strtotime($effectiveAt);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Invalid cost timestamp.');
        }
        $key = hash('sha256', $definitionUuid . ':' . $operationKey);
        $old = $this->database->find('costs', 'source_key', $key);
        if ($old) {
            if ((int) $old['amount_minor'] !== $money->minor || $old['currency'] !== $currency || (int) $old['exponent'] !== $exponent) {
                throw new \DomainException('Cost operation key was reused with a different amount.');
            }
            return $old;
        }
        $row = $this->database->insert('costs', ['definition_id' => $definition['id'], 'currency' => $currency, 'exponent' => $exponent, 'amount_minor' => $money->minor, 'source_key' => $key, 'effective_at' => gmdate('Y-m-d H:i:s', $timestamp)]);
        $this->audit->record('cost.recorded', $row['uuid'], ['definition_uuid' => $definitionUuid, 'currency' => $currency]);
        return $row;
    }

    private function attribute(array $conversion): void
    {
        $wpdb = $this->database->db();
        $touchpoints = $this->database->table('touchpoints');
        $points = empty($conversion['profile_id']) ? [] : $wpdb->get_results($wpdb->prepare("SELECT id,uuid,definition_id,channel,occurred_at FROM {$touchpoints} WHERE profile_id=%d AND occurred_at BETWEEN %s AND %s ORDER BY occurred_at,id LIMIT 1001", $conversion['profile_id'], gmdate('Y-m-d H:i:s', strtotime($conversion['paid_at'] . ' UTC') - 30 * DAY_IN_SECONDS), $conversion['paid_at']), ARRAY_A);
        foreach (self::MODELS as $model) {
            $custom = function_exists('get_option') ? get_option('wmos_custom_attribution', ['default_weight' => 1, 'channel_weights' => []]) : ['default_weight' => 1, 'channel_weights' => []];
            if (count($points) > 1000) {
                $weights = $this->aggregateWeights($conversion, $model, $custom);
                $allocations = (new Money((int) $conversion['net_minor'], $conversion['currency'], (int) $conversion['exponent']))->allocate($weights);
                $campaigns = [];
                foreach ($weights as $definitionId => $weight) {
                    $campaigns[(int) $definitionId] = ['weight' => $weight, 'minor' => $allocations[$definitionId]->minor];
                }
            } else {
                $weights = self::weights($points ?: [], $model, strtotime($conversion['paid_at'] . ' UTC'), $custom);
                $allocations = (new Money((int) $conversion['net_minor'], $conversion['currency'], (int) $conversion['exponent']))->allocate($weights);
                $campaigns = [];
                foreach ($weights as $pointKey => $weight) {
                    $definitionId = $pointKey === 'unattributed' ? 0 : (int) $points[(int) $pointKey]['definition_id'];
                    $campaigns[$definitionId]['weight'] = ($campaigns[$definitionId]['weight'] ?? 0) + $weight;
                    $campaigns[$definitionId]['minor'] = ($campaigns[$definitionId]['minor'] ?? 0) + $allocations[$pointKey]->minor;
                }
            }
            foreach ($campaigns as $definitionId => $credit) {
                $key = hash('sha256', $conversion['uuid'] . ':' . $model . ':' . $definitionId);
                $old = $this->database->find('credits', 'credit_key', $key);
                $data = ['conversion_id' => $conversion['id'], 'definition_id' => $definitionId ?: null, 'model' => $model, 'weight' => $credit['weight'], 'amount_minor' => $credit['minor'], 'currency' => $conversion['currency'], 'exponent' => $conversion['exponent'], 'source_digest' => $conversion['source_digest'], 'credit_key' => $key];
                $old ? $this->database->update('credits', $old['uuid'], $data, (int) $old['row_version']) : $this->database->insert('credits', $data);
            }
            $credits = $this->database->table('credits');
            $validIds = array_map('intval', array_keys($campaigns));
            $wpdb->query($wpdb->prepare("DELETE FROM {$credits} WHERE conversion_id=%d AND model=%s AND COALESCE(definition_id,0) NOT IN (" . implode(',', $validIds) . ')', $conversion['id'], $model));
        }
    }

    /** Oversized histories aggregate in SQL; no truncated window is presented as complete. */
    private function aggregateWeights(array $conversion, string $model, array $custom): array
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('touchpoints');
        $start = gmdate('Y-m-d H:i:s', strtotime($conversion['paid_at'] . ' UTC') - 30 * 86400);
        $where = $wpdb->prepare('profile_id=%d AND occurred_at BETWEEN %s AND %s', $conversion['profile_id'], $start, $conversion['paid_at']);
        if (in_array($model, ['first_touch', 'last_touch', 'last_non_direct'], true)) {
            $order = $model === 'first_touch' ? 'ASC' : 'DESC';
            $condition = $model === 'last_non_direct' ? " AND channel<>'direct'" : '';
            $row = $wpdb->get_row("SELECT COALESCE(definition_id,0) AS definition_id FROM {$table} WHERE {$where}{$condition} ORDER BY occurred_at {$order},id {$order} LIMIT 1", ARRAY_A);
            if (!$row && $model === 'last_non_direct') {
                $row = $wpdb->get_row("SELECT COALESCE(definition_id,0) AS definition_id FROM {$table} WHERE {$where} ORDER BY occurred_at DESC,id DESC LIMIT 1", ARRAY_A);
            }
            return [(int) ($row['definition_id'] ?? 0) => 1000000];
        }
        $expression = 'COUNT(*)';
        if ($model === 'time_decay') {
            $expression = $wpdb->prepare('SUM(POW(0.5,TIMESTAMPDIFF(SECOND,occurred_at,%s)/604800.0))', $conversion['paid_at']);
        }
        if ($model === 'custom') {
            $cases = [];
            $args = [];
            foreach ($custom['channel_weights'] ?? [] as $channel => $weight) {
                $cases[] = 'WHEN channel=%s THEN %d';
                $args[] = $channel;
                $args[] = $weight;
            }
            $args[] = $custom['default_weight'] ?? 1;
            $expression = $wpdb->prepare('SUM(CASE ' . implode(' ', $cases) . ' ELSE %d END)', ...$args);
            if ($cases === []) {
                $expression = 'COUNT(*)*' . (int) ($custom['default_weight'] ?? 1);
            }
        }
        $rows = $wpdb->get_results("SELECT COALESCE(definition_id,0) AS definition_id,{$expression} AS raw_weight FROM {$table} WHERE {$where} GROUP BY definition_id LIMIT 1001", ARRAY_A) ?: [];
        if (count($rows) > 1000) {
            throw new \DomainException('Attribution campaign dimensions exceed the configured safe budget.');
        }
        $raw = [];
        foreach ($rows as $row) {
            $raw[(int) $row['definition_id']] = (float) $row['raw_weight'];
        }
        if ($model === 'position_based') {
            $first = $wpdb->get_var("SELECT COALESCE(definition_id,0) FROM {$table} WHERE {$where} ORDER BY occurred_at,id LIMIT 1");
            $last = $wpdb->get_var("SELECT COALESCE(definition_id,0) FROM {$table} WHERE {$where} ORDER BY occurred_at DESC,id DESC LIMIT 1");
            $total = array_sum($raw);
            foreach ($raw as &$value) {
                $value = max(0, $value);
            } unset($value);
            --$raw[(int) $first];
            --$raw[(int) $last];
            foreach ($raw as &$value) {
                $value = $value * 0.2 / ($total - 2);
            } unset($value);
            $raw[(int) $first] += 0.4;
            $raw[(int) $last] += 0.4;
        }
        $total = array_sum($raw);
        if ($total <= 0) {
            return [0 => 1000000];
        }
        $quanta = [];
        $fractions = [];
        foreach ($raw as $key => $weight) {
            $exact = $weight / $total * 1000000;
            $quanta[$key] = (int) floor($exact);
            $fractions[$key] = $exact - $quanta[$key];
        }
        $keys = array_keys($raw);
        usort($keys, static fn($a, $b): int => ($fractions[$b] <=> $fractions[$a]) ?: ($a <=> $b));
        $remaining = 1000000 - array_sum($quanta);
        for ($i = 0; $i < $remaining; ++$i) {
            ++$quanta[$keys[$i]];
        }
        return $quanta;
    }

    /** One million exact integer quanta; shaping may use floating weights, money does not. */
    public static function weights(array $points, string $model, int $convertedAt, array $custom = ['default_weight' => 1, 'channel_weights' => []]): array
    {
        if (!in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException('Unknown attribution model.');
        }
        if ($points === []) {
            return ['unattributed' => 1000000];
        }
        $n = count($points);
        $raw = array_fill(0, $n, 0.0);
        switch ($model) {
            case 'first_touch':
                $raw[0] = 1;
                break;
            case 'last_touch':
                $raw[$n - 1] = 1;
                break;
            case 'last_non_direct':
                $last = $n - 1;
                for ($i = $n - 1; $i >= 0; --$i) {
                    if ($points[$i]['channel'] !== 'direct') {
                        $last = $i;
                        break;
                    }
                }
                $raw[$last] = 1;
                break;
            case 'linear':
                $raw = array_fill(0, $n, 1.0);
                break;
            case 'time_decay':
                foreach ($points as $i => $point) {
                    $raw[$i] = pow(0.5, max(0, $convertedAt - strtotime($point['occurred_at'] . ' UTC')) / (7 * 86400));
                }
                break;
            case 'position_based':
                if ($n === 1) {
                    $raw[0] = 1;
                } elseif ($n === 2) {
                    $raw = [0.5, 0.5];
                } else {
                    $raw = array_fill(0, $n, 0.2 / ($n - 2));
                    $raw[0] = $raw[$n - 1] = 0.4;
                }
                break;
            case 'custom':
                foreach ($points as $i => $point) {
                    $raw[$i] = (float) ($custom['channel_weights'][$point['channel']] ?? $custom['default_weight'] ?? 1);
                }
                if (array_sum($raw) <= 0) {
                    return ['unattributed' => 1000000];
                }
                break;
        }
        $sum = array_sum($raw);
        $weights = [];
        $remainders = [];
        foreach ($raw as $i => $weight) {
            $exact = $weight / $sum * 1000000;
            $weights[$i] = (int) floor($exact);
            $remainders[$i] = $exact - $weights[$i];
        }
        $missing = 1000000 - array_sum($weights);
        $keys = array_keys($raw);
        usort($keys, static fn(int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: strcmp((string) ($points[$a]['uuid'] ?? $a), (string) ($points[$b]['uuid'] ?? $b)));
        for ($i = 0; $i < $missing; ++$i) {
            ++$weights[$keys[$i]];
        }
        return $weights;
    }

    private function aggregateDelta(?array $old, array $new): void
    {
        foreach ([[$old, -1], [$new, 1]] as [$row, $sign]) {
            if (!$row || !in_array($row['state'], ['paid', 'refunded'], true)) {
                continue;
            }
            $key = hash('sha256', 'net_revenue:' . $row['currency'] . ':' . $row['exponent'] . ':' . substr($row['paid_at'], 0, 10));
            $wpdb = $this->database->db();
            $table = $this->database->table('aggregates');
            $uuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE bucket_key=%s FOR UPDATE", $key));
            $bucket = $uuid ? $this->database->get('aggregates', $uuid) : null;
            $delta = $sign * (int) $row['net_minor'];
            $count = $sign * ((int) $row['net_minor'] > 0 ? 1 : 0);
            if ($bucket) {
                $amount = (new Money((int) $bucket['value'], $row['currency'], (int) $row['exponent']))->add(new Money($delta, $row['currency'], (int) $row['exponent']));
                $this->database->update('aggregates', $bucket['uuid'], ['value' => $amount->minor, 'count' => (int) $bucket['count'] + $count], (int) $bucket['row_version']);
            } else {
                $this->database->insert('aggregates', ['bucket_key' => $key, 'metric' => 'net_revenue', 'definition_id' => null, 'currency' => $row['currency'], 'exponent' => $row['exponent'], 'day' => substr($row['paid_at'], 0, 10), 'value' => $delta, 'count' => $count]);
            }
        }
    }

    private function profileMetrics(int $profileId, string $currency, int $exponent): void
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('conversions');
        $profile = $this->database->find('profiles', 'id', $profileId);
        if (!$profile) {
            return;
        }
        if (!empty($profile['currency']) && $profile['currency'] !== '---' && $profile['currency'] !== $currency) {
            return;
        } // Never mix currencies in the CDP scalar projection.
        if (isset($profile['exponent']) && (int) $profile['exponent'] !== $exponent) {
            return;
        }
        $totals = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS orders,COALESCE(SUM(net_minor),0) AS revenue,MAX(paid_at) AS last_order FROM {$table} WHERE profile_id=%d AND currency=%s AND exponent=%d AND state='paid' AND net_minor>0", $profileId, $currency, $exponent), ARRAY_A);
        $revenue = Money::fromDecimal((string) $totals['revenue'], $currency, 0)->minor;
        $this->database->update('profiles', $profile['uuid'], ['order_count' => (int) $totals['orders'], 'revenue_minor' => $revenue, 'currency' => $currency, 'exponent' => $exponent, 'last_order_at' => $totals['last_order']], (int) $profile['row_version']);
    }

    private function markExperimentConversions(int $profileId, int $cursor = 0, string $generation = ''): void
    {
        $wpdb = $this->database->db();
        $table = $this->database->table('assignments');
        $conversions = $this->database->table('conversions');
        $rows = $this->database->list('assignments', ['profile_id' => $profileId], 100, $cursor);
        foreach ($rows as $assignment) {
            $cursor = (int) $assignment['id'];
            if ($assignment['exposed_at'] === null) {
                continue;
            }
            $version = $this->database->find('definition_versions', 'id', (int) $assignment['version_id']);
            if (!$version) {
                continue;
            }
            $body = Json::decode($version['body']);
            $days = min(365, max(1, (int) ($body['lookback_days'] ?? 30)));
            $end = gmdate('Y-m-d H:i:s', strtotime($assignment['exposed_at'] . ' UTC') + $days * 86400);
            $conversion = $wpdb->get_row($wpdb->prepare("SELECT id,paid_at FROM {$conversions} WHERE profile_id=%d AND state='paid' AND net_minor>0 AND paid_at BETWEEN %s AND %s ORDER BY paid_at,id LIMIT 1", $profileId, $assignment['exposed_at'], $end), ARRAY_A);
            $this->database->update('assignments', $assignment['uuid'], ['converted_at' => $conversion['paid_at'] ?? null, 'conversion_id' => $conversion['id'] ?? null], (int) $assignment['row_version']);
        }
        if (count($rows) === 100) {
            if (!$this->queue) {
                throw new \DomainException('Experiment reconciliation requires a configured queue for continuation.');
            }
            $this->queue->enqueue('experiment_conversion', ['profile_id' => $profileId, 'cursor' => $cursor, 'generation' => $generation], 'experiment-conversion:' . $profileId . ':' . $generation . ':' . $cursor);
        }
    }

    public function report(array $filters): array
    {
        $from = $filters['from'] ?? gmdate('Y-m-d', time() - 30 * 86400);
        $to = $filters['to'] ?? gmdate('Y-m-d');
        if (!is_string($from) || !is_string($to) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) || strtotime($from) === false || strtotime($to) === false || $from > $to || strtotime($to) - strtotime($from) > 366 * 86400) {
            throw new \InvalidArgumentException('Report date window must be valid and at most 366 days.');
        }
        $model = $filters['model'] ?? 'last_touch';
        if (!in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException('Unknown attribution model.');
        }
        $wpdb = $this->database->db();
        $aggregates = $this->database->table('aggregates');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT currency,exponent,SUM(value) AS net_revenue_minor,SUM(count) AS orders FROM {$aggregates} WHERE metric='net_revenue' AND day BETWEEN %s AND %s GROUP BY currency,exponent", $from, $to), ARRAY_A) ?: [];
        $costs = $this->database->table('costs');
        $credits = $this->database->table('credits');
        $conversions = $this->database->table('conversions');
        $costRows = $wpdb->get_results($wpdb->prepare("SELECT currency,exponent,SUM(amount_minor) AS cost_minor FROM {$costs} WHERE effective_at BETWEEN %s AND %s GROUP BY currency,exponent", $from . ' 00:00:00', $to . ' 23:59:59'), ARRAY_A) ?: [];
        $costMap = [];
        $currencyKeys = [];
        foreach ($rows as $row) {
            $currencyKeys[$row['currency'] . ':' . $row['exponent']] = true;
        }
        foreach ($costRows as $costRow) {
            $key = $costRow['currency'] . ':' . $costRow['exponent'];
            $costMap[$key] = $costRow['cost_minor'];
            if (!isset($currencyKeys[$key])) {
                $rows[] = ['currency' => $costRow['currency'], 'exponent' => $costRow['exponent'], 'net_revenue_minor' => '0', 'orders' => '0'];
            }
        }
        foreach ($rows as &$row) {
            $row['aov_minor'] = (int) $row['orders'] > 0 ? intdiv((int) $row['net_revenue_minor'], (int) $row['orders']) : null;
            $cost = $costMap[$row['currency'] . ':' . $row['exponent']] ?? null;
            $row['cost_minor'] = $cost === null ? null : (string) $cost;
            $row['roas'] = $cost !== null && (int) $cost > 0 ? (int) $row['net_revenue_minor'] / (int) $cost : null;
        }
        unset($row);
        $definitions = $this->database->table('definitions');
        $campaigns = $wpdb->get_results($wpdb->prepare("SELECT d.uuid AS definition_uuid,cg.currency,cg.exponent,cg.credited_minor,cg.cost_minor FROM (SELECT definition_id,currency,exponent,SUM(credited_minor) AS credited_minor,SUM(cost_minor) AS cost_minor FROM (SELECT c.definition_id,c.currency,c.exponent,SUM(c.amount_minor) AS credited_minor,NULL AS cost_minor FROM {$credits} c INNER JOIN {$conversions} v ON v.id=c.conversion_id WHERE c.model=%s AND v.paid_at BETWEEN %s AND %s GROUP BY c.definition_id,c.currency,c.exponent UNION ALL SELECT definition_id,currency,exponent,0 AS credited_minor,SUM(amount_minor) AS cost_minor FROM {$costs} WHERE effective_at BETWEEN %s AND %s GROUP BY definition_id,currency,exponent) combined GROUP BY definition_id,currency,exponent) cg LEFT JOIN {$definitions} d ON d.id=cg.definition_id ORDER BY cg.credited_minor DESC LIMIT 100", $model, $from . ' 00:00:00', $to . ' 23:59:59', $from . ' 00:00:00', $to . ' 23:59:59'), ARRAY_A) ?: [];
        foreach ($campaigns as &$campaign) {
            $cost = $campaign['cost_minor'];
            $campaign['attributed_roas'] = $cost !== null && (int) $cost > 0 ? (int) $campaign['credited_minor'] / (int) $cost : null;
        }
        unset($campaign);
        return ['from' => $from, 'to' => $to, 'timezone' => 'UTC', 'currencies' => $rows, 'campaigns' => $campaigns, 'model' => $model, 'lookback_days' => 30, 'definition' => 'Current paid-order item revenue after discounts and cumulative refunds; tax and shipping excluded. Refund corrections restate original paid UTC date. AOV divides positive-net paid orders. Currency groups are never combined. Attribution is correlation, not incremental causation.', 'as_of' => Database::now()];
    }
}
