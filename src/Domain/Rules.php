<?php

declare(strict_types=1);

namespace Wmos\Domain;

/** Safe three-valued rules, with an explicitly indexed SQL subset. */
final class Rules
{
    public const FIELDS = ['state', 'user_id', 'order_count', 'revenue_minor', 'currency', 'last_order_at', 'created_at', 'tags', 'attributes.locale', 'attributes.region', 'attributes.lifecycle', 'attributes.birthday_month', 'attributes.birthday_day', 'facts.product_ids', 'facts.category_ids', 'facts.coupon_codes', 'facts.cart_count', 'facts.cart_total_minor', 'facts.rfm', 'facts.segment_uuids', 'facts.open_count', 'facts.click_count', 'facts.referral_count', 'facts.loyalty_points', 'facts.consent'];
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'exists'];
    private const SQL_FIELDS = ['state', 'user_id', 'order_count', 'revenue_minor', 'currency', 'last_order_at', 'created_at'];

    public static function validate(array $ast): void
    {
        $count = 0;
        self::validateNode($ast, 0, $count);
    }

    private static function validateNode(array $node, int $depth, int &$count): void
    {
        if ($depth > 8 || ++$count > 100) {
            throw new \InvalidArgumentException('Rule complexity exceeds allowed limits.');
        }
        foreach (['all', 'any', 'not'] as $group) {
            if (array_key_exists($group, $node)) {
                if (count($node) !== 1 || !is_array($node[$group])) {
                    throw new \InvalidArgumentException('Invalid rule group.');
                }
                if ($group === 'not') {
                    self::validateNode($node[$group], $depth + 1, $count);
                } else {
                    if ($node[$group] === [] || !array_is_list($node[$group])) {
                        throw new \InvalidArgumentException('Rule groups require at least one child.');
                    }
                    foreach ($node[$group] as $child) {
                        if (!is_array($child)) {
                            throw new \InvalidArgumentException('Invalid rule child.');
                        }
                        self::validateNode($child, $depth + 1, $count);
                    }
                }
                return;
            }
        }
        if (array_diff(array_keys($node), ['field', 'operator', 'value']) || !in_array($node['field'] ?? null, self::FIELDS, true) || !in_array($node['operator'] ?? null, self::OPERATORS, true)) {
            throw new \InvalidArgumentException('Unknown rule field or operator.');
        }
        $op = $node['operator'];
        $value = $node['value'] ?? null;
        if ($op !== 'exists' && !array_key_exists('value', $node)) {
            throw new \InvalidArgumentException('Rule value is required.');
        }
        if ($op !== 'exists' && ($value === null || is_array($value) && $op !== 'in')) {
            throw new \InvalidArgumentException('Use exists for missing data; scalar comparisons require a value.');
        }
        if ($op === 'in' && (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 50)) {
            throw new \InvalidArgumentException('Membership rule needs 1..50 values.');
        }
        foreach (is_array($value) ? $value : [$value] as $part) {
            if (!is_scalar($part) && $part !== null || is_string($part) && strlen($part) > 191) {
                throw new \InvalidArgumentException('Rule values must be bounded scalars.');
            }
            if ($op !== 'exists' && in_array($node['field'], ['user_id', 'order_count', 'revenue_minor', 'facts.cart_count', 'facts.cart_total_minor', 'facts.open_count', 'facts.click_count', 'facts.referral_count', 'facts.loyalty_points', 'attributes.birthday_month', 'attributes.birthday_day'], true) && !is_int($part)) {
                throw new \InvalidArgumentException('Numeric criteria require integer values.');
            }
            if ($op !== 'exists' && in_array($node['field'], ['last_order_at', 'created_at'], true) && (!is_string($part) || strtotime($part) === false)) {
                throw new \InvalidArgumentException('Date criteria require valid timestamps.');
            }
        }
        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && (!is_int($value) && !is_string($value))) {
            throw new \InvalidArgumentException('Comparison needs an integer or timestamp/string.');
        }
    }

    public static function matches(array $ast, array $profile, array $facts = []): ?bool
    {
        self::validate($ast);
        foreach (['all', 'any'] as $group) {
            if (isset($ast[$group])) {
                $unknown = false;
                foreach ($ast[$group] as $child) {
                    $match = self::matches($child, $profile, $facts);
                    if ($group === 'all' && $match === false || $group === 'any' && $match === true) {
                        return $match;
                    }
                    $unknown = $unknown || $match === null;
                }
                return $unknown ? null : $group === 'all';
            }
        }
        if (isset($ast['not'])) {
            $match = self::matches($ast['not'], $profile, $facts);
            return $match === null ? null : !$match;
        }
        $field = $ast['field'];
        if (str_starts_with($field, 'attributes.')) {
            $attrs = $profile['attributes'] ?? [];
            if (is_string($attrs)) {
                $attrs = json_decode($attrs, true, 8, JSON_THROW_ON_ERROR);
            }
            $actual = $attrs[substr($field, 11)] ?? null;
        } elseif (str_starts_with($field, 'facts.')) {
            $actual = $facts[substr($field, 6)] ?? null;
        } else {
            $actual = $profile[$field] ?? null;
            if ($field === 'tags' && is_string($actual)) {
                $actual = json_decode($actual, true, 8, JSON_THROW_ON_ERROR);
            }
        }
        $op = $ast['operator'];
        if ($op === 'exists') {
            return $actual !== null;
        }
        if ($actual === null) {
            return null;
        }
        $expected = $ast['value'];
        if (in_array($field, ['last_order_at', 'created_at'], true)) {
            $actual = gmdate('Y-m-d H:i:s', strtotime($actual . ' UTC'));
            $expected = is_array($expected) ? array_map(static fn(string $date): string => gmdate('Y-m-d H:i:s', strtotime($date)), $expected) : gmdate('Y-m-d H:i:s', strtotime($expected));
        }
        if (in_array($field, ['user_id', 'order_count', 'revenue_minor', 'facts.cart_count', 'facts.cart_total_minor', 'facts.open_count', 'facts.click_count', 'facts.referral_count', 'facts.loyalty_points'], true)) {
            $actual = (int) $actual;
            if (!is_array($expected)) {
                $expected = (int) $expected;
            }
        }
        return match ($op) {
            'eq' => $actual === $expected,
            'neq' => $actual !== $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => in_array($actual, $expected, true),
            'contains' => is_array($actual) ? in_array($expected, $actual, true) : false,
            default => throw new \InvalidArgumentException('Unsupported rule operator.'),
        };
    }

    public static function compile(array $ast, string $alias = 'p'): array
    {
        self::validate($ast);
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid SQL alias.');
        }
        if (isset($ast['all']) || isset($ast['any'])) {
            $group = isset($ast['all']) ? 'all' : 'any';
            $parts = [];
            $args = [];
            foreach ($ast[$group] as $child) {
                $compiled = self::compile($child, $alias);
                $parts[] = '(' . $compiled['sql'] . ')';
                $args = array_merge($args, $compiled['args']);
            }
            return ['sql' => implode($group === 'all' ? ' AND ' : ' OR ', $parts), 'args' => $args];
        }
        if (isset($ast['not'])) {
            $compiled = self::compile($ast['not'], $alias);
            return ['sql' => 'NOT (' . $compiled['sql'] . ')', 'args' => $compiled['args']];
        }
        if (!in_array($ast['field'], self::SQL_FIELDS, true) || $ast['operator'] === 'contains') {
            throw new \DomainException('This criterion requires bounded asynchronous evaluation.');
        }
        $column = $alias . '.`' . $ast['field'] . '`';
        if ($ast['operator'] === 'exists') {
            return ['sql' => $column . ' IS NOT NULL', 'args' => []];
        }
        $value = $ast['value'];
        if (in_array($ast['field'], ['last_order_at', 'created_at'], true)) {
            $value = is_array($value) ? array_map(static fn(string $date): string => gmdate('Y-m-d H:i:s', strtotime($date)), $value) : gmdate('Y-m-d H:i:s', strtotime($value));
        }
        $numeric = in_array($ast['field'], ['user_id', 'order_count', 'revenue_minor'], true);
        $format = $numeric ? '%d' : '%s';
        if ($value === null) {
            return ['sql' => $column . ($ast['operator'] === 'neq' ? ' IS NOT NULL' : ' IS NULL'), 'args' => []];
        }
        if ($ast['operator'] === 'in') {
            return ['sql' => $column . ' IN (' . implode(',', array_fill(0, count($value), $format)) . ')', 'args' => $value];
        }
        $operator = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$ast['operator']];
        return ['sql' => $column . ' ' . $operator . ' ' . $format, 'args' => [$value]];
    }
}
