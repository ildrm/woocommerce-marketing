<?php

declare(strict_types=1);

namespace Wmos\Infrastructure\Providers;

use Wmos\Contracts\{Provider, ProviderOutcome};

/** Narrow API adapters use WP HTTP; there is no storefront or checkout network call. */
final class HttpProvider implements Provider
{
    public function __construct(private string $type)
    {
    }

    public function capabilities(): array
    {
        return match ($this->type) {
            'resend' => ['channels' => ['email'], 'idempotency_seconds' => 86400, 'templates' => false, 'receipts' => true],
            'twilio' => ['channels' => ['sms'], 'idempotency_seconds' => 0, 'templates' => false, 'receipts' => true],
            'meta' => ['channels' => ['whatsapp'], 'idempotency_seconds' => 0, 'templates' => true, 'receipts' => true],
            'telegram' => ['channels' => ['telegram'], 'idempotency_seconds' => 0, 'templates' => false, 'receipts' => false],
            'onesignal' => ['channels' => ['push'], 'idempotency_seconds' => 0, 'templates' => false, 'receipts' => false],
            'relay' => ['channels' => ['social', 'ads', 'webhook'], 'idempotency_seconds' => 0, 'templates' => false, 'receipts' => true],
            default => throw new \InvalidArgumentException('Unknown provider adapter.'),
        };
    }

    public function submit(string $channel, string $destination, array $content, array $configuration, array $secrets, string $operationKey): ProviderOutcome
    {
        if (!in_array($channel, $this->capabilities()['channels'], true)) {
            return new ProviderOutcome('rejected', null, 'unsupported_channel');
        }
        $token = $secrets['token'] ?? '';
        if ($token === '') {
            return new ProviderOutcome('rejected', null, 'credentials_missing');
        }
        $headers = ['Content-Type' => 'application/json'];
        $json = true;
        switch ($this->type) {
            case 'resend':
                $url = 'https://api.resend.com/emails';
                $headers['Authorization'] = 'Bearer ' . $token;
                $headers['Idempotency-Key'] = $operationKey;
                $body = ['from' => $configuration['from'], 'to' => [$destination], 'subject' => $content['subject'], 'html' => $content['html'] ?? '', 'text' => $content['text'] ?? '', 'headers' => $content['headers'] ?? []];
                break;
            case 'twilio':
                $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $configuration['account_sid'] . '/Messages.json';
                $headers['Authorization'] = 'Basic ' . base64_encode($configuration['account_sid'] . ':' . $token);
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $body = ['To' => $destination, 'From' => $configuration['from'], 'Body' => $content['text']];
                if (!empty($configuration['callback_url'])) {
                    $body['StatusCallback'] = $configuration['callback_url'];
                }
                $json = false;
                break;
            case 'meta':
                if (empty($content['template']) || empty($content['language']) || !in_array($content['template'], $configuration['approved_templates'] ?? [], true)) {
                    return new ProviderOutcome('rejected', null, 'template_not_approved');
                }
                $url = 'https://graph.facebook.com/' . $configuration['api_version'] . '/' . $configuration['phone_number_id'] . '/messages';
                $headers['Authorization'] = 'Bearer ' . $token;
                $body = ['messaging_product' => 'whatsapp', 'to' => ltrim($destination, '+'), 'type' => 'template', 'template' => ['name' => $content['template'], 'language' => ['code' => $content['language']], 'components' => $content['components'] ?? []]];
                break;
            case 'telegram':
                $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
                $body = ['chat_id' => $destination, 'text' => $content['text'], 'link_preview_options' => ['is_disabled' => true]];
                break;
            case 'onesignal':
                $url = 'https://api.onesignal.com/notifications';
                $headers['Authorization'] = 'Key ' . $token;
                $body = ['app_id' => $configuration['app_id'], 'include_subscription_ids' => [$destination], 'contents' => ['en' => $content['text']], 'headings' => ['en' => $content['title'] ?? '']];
                break;
            case 'relay':
                $url = $configuration['endpoint'];
                self::approvedRelay($url);
                $body = ['schema_version' => 1, 'operation_id' => $operationKey, 'operation' => $channel, 'destination' => $destination, 'account' => $configuration['account'] ?? '', 'content' => $content];
                $encoded = json_encode($body, JSON_THROW_ON_ERROR);
                $headers['X-Wmos-Timestamp'] = (string) time();
                $headers['X-Wmos-Signature'] = hash_hmac('sha256', $headers['X-Wmos-Timestamp'] . '.' . $encoded, $token);
                break;
            default:
                return new ProviderOutcome('rejected', null, 'unsupported_provider');
        }
        $response = wp_safe_remote_post($url, ['headers' => $headers, 'body' => $json ? json_encode($body, JSON_THROW_ON_ERROR) : http_build_query($body, '', '&'), 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536]);
        if (is_wp_error($response)) {
            return new ProviderOutcome('ambiguous', null, 'transport_outcome_unknown');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($this->type === 'resend' && $status === 409 && ($data['name'] ?? '') === 'concurrent_idempotent_requests') {
            return new ProviderOutcome('retryable', null, 'idempotent_request_in_progress', 30);
        }
        if ($status === 429) {
            return new ProviderOutcome('retryable', null, 'rate_limited', max(1, min(86400, (int) wp_remote_retrieve_header($response, 'retry-after'))));
        }
        if ($status >= 500 || $status === 408) {
            return new ProviderOutcome('ambiguous', null, 'provider_outcome_unknown');
        }
        if ($status < 200 || $status >= 300) {
            return new ProviderOutcome('rejected', null, in_array($status, [401, 403], true) ? 'authentication_rejected' : 'message_rejected');
        }
        $reference = match ($this->type) {
            'resend', 'onesignal' => $data['id'] ?? null,
            'twilio' => $data['sid'] ?? null,
            'meta' => $data['messages'][0]['id'] ?? null,
            'telegram' => !empty($data['ok']) ? (string) ($data['result']['message_id'] ?? '') : null,
            'relay' => !empty($data['accepted']) ? ($data['reference'] ?? null) : null,
            default => null,
        };
        if (!is_string($reference) || $reference === '' || strlen($reference) > 191) {
            return new ProviderOutcome('ambiguous', null, 'acceptance_receipt_missing');
        }
        return new ProviderOutcome('accepted', $reference);
    }

    public static function approvedRelay(string $url): void
    {
        $parts = parse_url($url);
        $hosts = defined('WMOS_RELAY_HOSTS') ? (array) constant('WMOS_RELAY_HOSTS') : [];
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), array_map('strtolower', $hosts), true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443) || !wp_http_validate_url($url)) {
            throw new \InvalidArgumentException('Relay requires an approved public HTTPS hostname in WMOS_RELAY_HOSTS.');
        }
    }
}
