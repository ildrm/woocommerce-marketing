<?php

declare(strict_types=1);

namespace Wmos\Infrastructure\Providers;

final class WebhookVerifier
{
    public static function verify(string $type, string $raw, array $headers, array $secrets, array $config, string $canonicalUrl): array
    {
        if (strlen($raw) > 131072) {
            throw new \InvalidArgumentException('Webhook payload too large.');
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        foreach ($headers as &$value) {
            $value = is_array($value) ? (string) reset($value) : (string) $value;
        } unset($value);
        $secret = (string) ($secrets['webhook_secret'] ?? '');
        if ($type === 'twilio') {
            parse_str($raw, $params);
            if (($params['AccountSid'] ?? '') !== ($config['account_sid'] ?? '') || ($secrets['token'] ?? '') === '') {
                throw new \RuntimeException('Invalid webhook account.');
            }
            ksort($params, SORT_STRING);
            $signed = $canonicalUrl;
            foreach ($params as $key => $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Invalid callback parameters.');
                }
                $signed .= $key . $value;
            }
            $expected = base64_encode(hash_hmac('sha1', $signed, $secrets['token'], true));
            if (!hash_equals($expected, $headers['x-twilio-signature'] ?? '')) {
                throw new \RuntimeException('Invalid webhook signature.');
            }
            return ['key' => hash('sha256', ($params['MessageSid'] ?? '') . ':' . ($params['MessageStatus'] ?? '') . ':' . ($params['Body'] ?? '')), 'payload' => $params];
        }
        if ($secret === '') {
            throw new \RuntimeException('Webhook verification secret missing.');
        }
        if ($type === 'resend') {
            $stamp = $headers['svix-timestamp'] ?? '';
            $id = $headers['svix-id'] ?? '';
            if (!ctype_digit($stamp) || abs(time() - (int) $stamp) > 300 || $id === '') {
                throw new \RuntimeException('Webhook replay window exceeded.');
            }
            $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
            if ($key === false) {
                throw new \RuntimeException('Invalid webhook secret.');
            }
            $expected = base64_encode(hash_hmac('sha256', $id . '.' . $stamp . '.' . $raw, $key, true));
            $valid = false;
            foreach (explode(' ', $headers['svix-signature'] ?? '') as $signature) {
                $valid = $valid || hash_equals('v1,' . $expected, $signature);
            }
            if (!$valid) {
                throw new \RuntimeException('Invalid webhook signature.');
            }
            return ['key' => hash('sha256', $id), 'payload' => json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
        }
        if ($type === 'meta') {
            if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $headers['x-hub-signature-256'] ?? '')) {
                throw new \RuntimeException('Invalid webhook signature.');
            }
        } elseif ($type === 'telegram') {
            if (!hash_equals($secret, $headers['x-telegram-bot-api-secret-token'] ?? '')) {
                throw new \RuntimeException('Invalid webhook signature.');
            }
        } else {
            $stamp = $headers['x-wmos-timestamp'] ?? '';
            if (!ctype_digit($stamp) || abs(time() - (int) $stamp) > 300 || !hash_equals(hash_hmac('sha256', $stamp . '.' . $raw, $secret), $headers['x-wmos-signature'] ?? '')) {
                throw new \RuntimeException('Invalid webhook signature.');
            }
        }
        return ['key' => hash('sha256', $raw), 'payload' => json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
    }
}
