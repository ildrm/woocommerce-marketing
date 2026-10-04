<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Domain\Rules;
use Wmos\Infrastructure\{Database, Audit};

/** No individual evidence is consulted until current personalization consent permits it. */
final class Personalization
{
    public function __construct(private Database $database, private Definitions $definitions, private Consent $consent, private Recommendations $recommendations, private Audit $audit)
    {
    }

    public function resolve(string $definitionUuid, ?string $profileUuid = null): array
    {
        if (!in_array('personalization', get_option('wmos_settings', [])['enabled_modules'] ?? [], true)) {
            return ['definition_uuid' => $definitionUuid, 'surface' => 'banner', 'personalized' => false, 'title' => '', 'html' => '', 'url' => null];
        }
        $definition = $this->database->get('definitions', $definitionUuid);
        if (!$definition || !in_array($definition['kind'], ['personalization', 'recommendation'], true) || $definition['state'] !== 'active' || !$definition['published_version_id']) {
            throw new \DomainException('Active published personalization definition is required.');
        }
        $body = $this->definitions->versionBody((int) $definition['published_version_id']);
        $allowed = $profileUuid !== null && $this->consent->allowed($profileUuid, 'personalization', 'personalization');
        $selected = $body['fallback'] ?? ['title' => '', 'html' => '', 'strategy' => 'latest', 'config' => []];
        if (!isset($body['rule']) && !in_array($body['strategy'] ?? 'latest', ['recent_views', 'purchase_history'], true)) {
            $selected = $body;
        }
        if ($allowed) {
            $profile = $this->database->get('profiles', $profileUuid);
            if (!isset($body['rule']) || Rules::matches($body['rule'], $profile) === true) {
                $selected = $body;
            }
        }
        $strategy = $selected['strategy'] ?? 'latest';
        $result = ['definition_uuid' => $definitionUuid, 'surface' => $body['surface'] ?? 'recommendations', 'personalized' => $allowed && $selected === $body, 'title' => $selected['title'] ?? '', 'html' => wp_kses_post($selected['html'] ?? ''), 'url' => isset($selected['url']) ? esc_url_raw($selected['url']) : null];
        if (($body['surface'] ?? 'recommendations') === 'recommendations') {
            $result['recommendations'] = $this->recommendations->recommend($strategy, $selected['config'] ?? [], $allowed ? $profileUuid : null);
        }
        return $result;
    }

    public function preview(string $definitionUuid, ?string $profileUuid = null): array
    {
        $result = $this->resolve($definitionUuid, $profileUuid);
        $this->audit->record('personalization.previewed', $definitionUuid, ['personalized' => $result['personalized']]);
        return $result;
    }

    /** Shared cached HTML callers must use a null profile. Private responses use explicit no-store. */
    public function render(string $definitionUuid): string
    {
        $result = $this->resolve($definitionUuid, null);
        $html = '<section class="wmos-personalization" aria-label="' . esc_attr($result['title'] ?: __('Product recommendations', 'woocommerce-marketing-os')) . '">';
        if ($result['title'] !== '') {
            $html .= '<h2>' . esc_html($result['title']) . '</h2>';
        }
        $html .= $result['html'];
        if (isset($result['recommendations'])) {
            $html .= '<ul class="wmos-recommendations">';
            foreach ($result['recommendations']['products'] as $product) {
                $html .= '<li><a href="' . esc_url($product['url']) . '">';
                if ($product['image_url']) {
                    $html .= '<img loading="lazy" src="' . esc_url($product['image_url']) . '" alt="' . esc_attr($product['image_alt']) . '">';
                }
                $html .= '<span>' . esc_html($product['name']) . '</span></a> <span>' . wp_kses_post($product['price_html']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        return $html . '</section>';
    }

    public static function validate(array $body): void
    {
        if (array_diff(array_keys($body), ['surface', 'title', 'html', 'url', 'rule', 'strategy', 'config', 'fallback']) || !in_array($body['surface'] ?? 'recommendations', ['recommendations', 'banner', 'landing', 'email'], true)) {
            throw new \InvalidArgumentException('Invalid personalization surface or fields.');
        }
        foreach (['title', 'html'] as $field) {
            if (isset($body[$field]) && (!is_string($body[$field]) || strlen($body[$field]) > 65536)) {
                throw new \InvalidArgumentException('Invalid personalization content.');
            }
        }
        if (isset($body['url']) && (!is_string($body['url']) || !filter_var($body['url'], FILTER_VALIDATE_URL) || !in_array(parse_url($body['url'], PHP_URL_SCHEME), ['https', 'http'], true))) {
            throw new \InvalidArgumentException('Invalid personalization link.');
        }
        if (isset($body['rule'])) {
            Rules::validate($body['rule']);
        }
        Recommendations::validate($body['strategy'] ?? 'latest', $body['config'] ?? []);
        if (isset($body['fallback'])) {
            if (!is_array($body['fallback']) || isset($body['fallback']['fallback']) || isset($body['fallback']['rule']) || array_diff(array_keys($body['fallback']), ['title', 'html', 'url', 'strategy', 'config'])) {
                throw new \InvalidArgumentException('Invalid public fallback.');
            }
            self::validate($body['fallback']);
            if (in_array($body['fallback']['strategy'] ?? 'latest', ['recent_views', 'purchase_history'], true)) {
                throw new \InvalidArgumentException('Public fallback cannot use personal evidence.');
            }
        }
    }
}
