<?php

declare(strict_types=1);

namespace Wmos\Domain;

/** Validates finite DAGs before a version can execute. */
final class Graph
{
    public const TYPES = ['trigger', 'condition', 'filter', 'branch', 'delay', 'wait', 'action', 'goal', 'exit', 'split', 'join', 'experiment', 'webhook', 'subworkflow'];
    public static function validate(array $body, array $actionRegistry = []): void
    {
        $nodes = $body['nodes'] ?? [];
        $edges = $body['edges'] ?? [];
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) < 2 || count($nodes) > 200 || !is_array($edges) || !array_is_list($edges) || count($edges) > 400) {
            throw new \InvalidArgumentException('Automation requires 2..200 nodes and at most 400 edges.');
        }
        $map = [];
        $roots = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $node['id'] ?? '') || isset($map[$node['id']]) || !in_array($node['type'] ?? '', self::TYPES, true) || !is_array($node['config'] ?? [])) {
                throw new \InvalidArgumentException('Invalid or duplicate automation node.');
            }
            $map[$node['id']] = $node;
            $config = $node['config'] ?? [];
            $type = $node['type'];
            if (strlen(json_encode($config, JSON_THROW_ON_ERROR)) > 16384) {
                throw new \InvalidArgumentException('Node configuration exceeds allowed size.');
            }
            $allowed = match ($type) {
                'trigger' => ['event'], 'condition', 'filter', 'goal' => ['rule'], 'branch' => ['cases'], 'delay' => ['seconds', 'days', 'at', 'timezone'],
                'wait' => ['event', 'timeout'], 'exit' => ['reason'], 'split' => [], 'join' => ['split'],
                'experiment', 'subworkflow' => ['definition_uuid', 'version_id'],
                default => ['action', 'provider_uuid', 'channel', 'purpose', 'subject', 'html', 'text', 'body', 'content', 'tag', 'operation', 'promotion_uuid', 'program_uuid', 'points', 'amount_minor', 'url', 'title', 'template_id', 'version_id'],
            };
            if (array_diff(array_keys($config), $allowed)) {
                throw new \InvalidArgumentException('Unknown node configuration fields.');
            }
            foreach ($config as $field => $value) {
                if (str_ends_with($field, '_uuid') && (!is_string($value) || !preg_match('/^[a-f0-9-]{36}$/Di', $value))) {
                    throw new \InvalidArgumentException('Invalid node reference UUID.');
                }
                if (in_array($field, ['points', 'amount_minor', 'version_id'], true) && (!is_int($value) || $value < 0)) {
                    throw new \InvalidArgumentException('Invalid action amount.');
                }
                if (in_array($field, ['subject', 'html', 'text', 'body', 'tag', 'title', 'template_id', 'purpose', 'channel'], true) && (!is_string($value) || strlen($value) > 8192)) {
                    throw new \InvalidArgumentException('Invalid action text.');
                }
            }
            if ($type === 'trigger') {
                $roots[] = $node['id'];
            }
            if (in_array($type, ['condition', 'filter', 'goal'], true)) {
                Rules::validate($config['rule'] ?? []);
            }
            if ($type === 'delay') {
                if (isset($config['seconds'])) {
                    if (!is_int($config['seconds']) || $config['seconds'] < 1 || $config['seconds'] > 31536000 || count($config) !== 1) {
                        throw new \InvalidArgumentException('Elapsed delay must be 1..31536000 seconds.');
                    }
                } elseif (!is_int($config['days'] ?? null) || $config['days'] < 0 || $config['days'] > 365 || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $config['at'] ?? '') || !in_array($config['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true)) {
                    throw new \InvalidArgumentException('Calendar delay requires days, local time and IANA timezone.');
                }
            }
            if ($type === 'wait' && (!preg_match('/^[a-z][a-z0-9_.]{1,99}$/D', $config['event'] ?? '') || !is_int($config['timeout'] ?? null) || $config['timeout'] < 1 || $config['timeout'] > 31536000)) {
                throw new \InvalidArgumentException('Wait requires an event and bounded timeout.');
            }
            if ($type === 'action' && !in_array($config['action'] ?? '', $actionRegistry ?: ['message', 'tag', 'coupon', 'points', 'webhook', 'review'], true)) {
                throw new \InvalidArgumentException('Action is not registered.');
            }
            if ($type === 'action') {
                if (isset($config['operation']) && (($config['action'] ?? '') !== 'tag' || !in_array($config['operation'], ['add', 'remove'], true))) {
                    throw new \InvalidArgumentException('Tag operation must be add or remove.');
                }
                $requiredFields = match ($config['action']) {
                    'message' => ['provider_uuid', 'channel'], 'tag' => ['tag'], 'coupon' => ['promotion_uuid'], 'points' => ['program_uuid', 'points'], 'webhook' => ['url'], 'review' => ['title'], default => []
                };
                foreach ($requiredFields as $field) {
                    if (!isset($config[$field]) || $config[$field] === '') {
                        throw new \InvalidArgumentException('Action is missing required configuration.');
                    }
                }
                if (($config['action'] ?? '') === 'message' && isset($config['content'])) {
                    self::validateContent($config['content']);
                }
            }
            if ($type === 'webhook' || $type === 'action' && ($config['action'] ?? '') === 'webhook') {
                $url = parse_url($config['url'] ?? '');
                if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
                    throw new \InvalidArgumentException('Webhook requires a public HTTPS destination without credentials.');
                }
            }
            if ($type === 'branch') {
                if (!is_array($config['cases'] ?? null) || $config['cases'] === [] || count($config['cases']) > 20) {
                    throw new \InvalidArgumentException('Branch requires bounded cases.');
                }
                foreach ($config['cases'] as $case) {
                    if (!is_string($case['outcome'] ?? null)) {
                        throw new \InvalidArgumentException('Branch outcome is required.');
                    }
                    Rules::validate($case['rule'] ?? []);
                }
            }
            if (in_array($type, ['experiment', 'subworkflow'], true) && !preg_match('/^[a-f0-9-]{36}$/Di', $config['definition_uuid'] ?? '')) {
                throw new \InvalidArgumentException('Referenced definition UUID is required.');
            }
        }
        if (count($roots) !== 1) {
            throw new \InvalidArgumentException('Automation requires exactly one trigger.');
        }
        $adjacency = [];
        $outcomes = [];
        foreach ($edges as $edge) {
            if (!isset($map[$edge['from'] ?? ''], $map[$edge['to'] ?? '']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $edge['outcome'] ?? 'success')) {
                throw new \InvalidArgumentException('Invalid automation edge.');
            }
            if ($map[$edge['to']]['type'] === 'trigger' || $map[$edge['from']]['type'] === 'exit') {
                throw new \InvalidArgumentException('Invalid trigger/exit connection.');
            }
            $key = $edge['from'] . ':' . ($edge['outcome'] ?? 'success');
            if (isset($outcomes[$key]) && $map[$edge['from']]['type'] !== 'split') {
                throw new \InvalidArgumentException('Duplicate outcome; use an explicit split.');
            }
            $outcomes[$key] = true;
            $adjacency[$edge['from']][] = $edge['to'];
        }
        foreach ($map as $key => $node) {
            if ($node['type'] !== 'exit' && empty($adjacency[$key])) {
                throw new \InvalidArgumentException('Every non-exit needs a successor.');
            }
            $required = match ($node['type']) {
                'condition', 'goal' => ['true', 'false', 'unknown'],
                'filter' => ['true', 'false', 'unknown'],
                'wait' => ['event', 'timeout'],
                'branch' => array_merge(['default'], array_column($node['config']['cases'], 'outcome')),
                default => [],
            };
            foreach ($required as $outcome) {
                if (!isset($outcomes[$key . ':' . $outcome])) {
                    throw new \InvalidArgumentException('Node is missing a required outcome: ' . $outcome);
                }
            }
            if ($node['type'] === 'split' && count($adjacency[$key]) < 2) {
                throw new \InvalidArgumentException('Split requires multiple branches.');
            }
            if ($node['type'] === 'split' && count(array_unique($adjacency[$key])) !== count($adjacency[$key])) {
                throw new \InvalidArgumentException('Split branches require distinct destinations.');
            }
            if ($node['type'] === 'join' && (!isset($map[$node['config']['split'] ?? '']) || $map[$node['config']['split']]['type'] !== 'split')) {
                throw new \InvalidArgumentException('Join must reference its split.');
            }
        }
        $visited = [];
        $active = [];
        $visit = function (string $key) use (&$visit, &$visited, &$active, $adjacency): void {
            if (isset($active[$key])) {
                throw new \InvalidArgumentException('Automation loops are not permitted.');
            }
            if (isset($visited[$key])) {
                return;
            }
            $active[$key] = true;
            foreach ($adjacency[$key] ?? [] as $next) {
                $visit($next);
            }
            unset($active[$key]);
            $visited[$key] = true;
        };
        $visit($roots[0]);
        if (count($visited) !== count($map)) {
            throw new \InvalidArgumentException('Automation contains unreachable nodes.');
        }
        $joinedSplits = [];
        foreach ($map as $joinKey => $join) {
            if ($join['type'] !== 'join') {
                continue;
            }
            $splitKey = $join['config']['split'];
            if (isset($joinedSplits[$splitKey])) {
                throw new \InvalidArgumentException('A split can have only one synchronization join.');
            }
            $joinedSplits[$splitKey] = true;
            $memo = [];
            $reaches = function (string $key) use (&$reaches, &$memo, $joinKey, $adjacency): bool {
                if ($key === $joinKey) {
                    return true;
                }
                if (array_key_exists($key, $memo)) {
                    return $memo[$key];
                }
                if (empty($adjacency[$key])) {
                    return $memo[$key] = false;
                }
                foreach ($adjacency[$key] as $next) {
                    if (!$reaches($next)) {
                        return $memo[$key] = false;
                    }
                }
                return $memo[$key] = true;
            };
            foreach ($adjacency[$splitKey] as $branch) {
                if (!$reaches($branch)) {
                    throw new \InvalidArgumentException('Every branch must reach its declared join before exiting.');
                }
            }
            $region = [];
            $collect = function (string $key) use (&$collect, &$region, $joinKey, $adjacency): void {
                if ($key === $joinKey || isset($region[$key])) {
                    return;
                }
                $region[$key] = true;
                foreach ($adjacency[$key] ?? [] as $next) {
                    $collect($next);
                }
            };
            foreach ($adjacency[$splitKey] as $branch) {
                $collect($branch);
            }
            foreach ($edges as $edge) {
                if ($edge['to'] === $joinKey && $edge['from'] !== $splitKey && !isset($region[$edge['from']])) {
                    throw new \InvalidArgumentException('Join cannot receive a path outside its declared split.');
                }
            }
        }
    }

    public static function validateContent(array $content): void
    {
        if ($content === [] || array_diff(array_keys($content), ['subject', 'html', 'text', 'body', 'template', 'template_id', 'language', 'components', 'title', 'data', 'variables', 'media']) || strlen(json_encode($content, JSON_THROW_ON_ERROR)) > 16384) {
            throw new \InvalidArgumentException('Invalid bounded message content.');
        }
        foreach ($content as $key => $value) {
            if (!in_array($key, ['components', 'data', 'variables', 'media'], true) && (!is_string($value) || strlen($value) > 8192)) {
                throw new \InvalidArgumentException('Invalid message text field.');
            }
            if (in_array($key, ['data', 'variables'], true)) {
                if (!is_array($value) || count($value) > 50) {
                    throw new \InvalidArgumentException('Invalid message variable mapping.');
                }
                foreach ($value as $name => $part) {
                    if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', (string) $name) || !is_scalar($part) || strlen((string) $part) > 1024) {
                        throw new \InvalidArgumentException('Message variables must be bounded scalars.');
                    }
                }
            }
            if ($key === 'components') {
                if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
                    throw new \InvalidArgumentException('Invalid template components.');
                }
                foreach ($value as $component) {
                    if (!is_array($component) || array_diff(array_keys($component), ['type', 'parameters', 'sub_type', 'index']) || !in_array($component['type'] ?? '', ['body', 'header', 'button'], true) || !is_array($component['parameters'] ?? []) || count($component['parameters'] ?? []) > 10) {
                        throw new \InvalidArgumentException('Invalid typed template component.');
                    }
                    foreach ($component['parameters'] ?? [] as $parameter) {
                        if (!is_array($parameter) || array_diff(array_keys($parameter), ['type', 'text', 'image', 'video', 'document']) || !in_array($parameter['type'] ?? '', ['text', 'image', 'video', 'document'], true)) {
                            throw new \InvalidArgumentException('Invalid template parameter.');
                        }
                        if (($parameter['type'] ?? '') === 'text' && (!is_string($parameter['text'] ?? null) || strlen($parameter['text']) > 1024)) {
                            throw new \InvalidArgumentException('Invalid template parameter text.');
                        }
                        foreach (['image', 'video', 'document'] as $media) {
                            if (isset($parameter[$media]) && (!is_array($parameter[$media]) || array_diff(array_keys($parameter[$media]), ['link']) || !is_string($parameter[$media]['link'] ?? null) || !filter_var($parameter[$media]['link'], FILTER_VALIDATE_URL) || parse_url($parameter[$media]['link'], PHP_URL_SCHEME) !== 'https')) {
                                throw new \InvalidArgumentException('Template media requires an HTTPS link.');
                            }
                        }
                    }
                }
            }
            if ($key === 'media' && (!is_array($value) || count($value) > 10 || array_filter($value, static fn($url): bool => !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'))) {
                throw new \InvalidArgumentException('Message media requires bounded HTTPS URLs.');
            }
        }
    }
}
