<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

/** Authenticated encryption; the merchant's master key never lives in the database. */
final class Secrets
{
    private ?string $key;

    public function __construct(?string $encodedKey = null)
    {
        $encodedKey ??= defined('WMOS_ENCRYPTION_KEY') ? (string) constant('WMOS_ENCRYPTION_KEY') : (getenv('WMOS_ENCRYPTION_KEY') ?: null);
        $decoded = $encodedKey === null ? false : base64_decode($encodedKey, true);
        $this->key = is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
    }

    public function available(): bool
    {
        return $this->key !== null && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    public function encrypt(string $plaintext, string $scope): string
    {
        $this->requireKey();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $scope, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext, string $scope): string
    {
        $this->requireKey();
        if (!str_starts_with($ciphertext, 'v1:')) {
            throw new \RuntimeException('Unsupported encrypted record.');
        }
        $bytes = base64_decode(substr($ciphertext, 3), true);
        if (!is_string($bytes) || strlen($bytes) < 40) {
            throw new \RuntimeException('Invalid encrypted record.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes, 24), $scope, substr($bytes, 0, 24), $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Encrypted record authentication failed.');
        }
        return $plain;
    }

    public function hash(string $value, string $scope = 'identity'): string
    {
        $this->requireKey();
        return hash_hmac('sha256', $scope . "\0" . $value, $this->key, true);
    }

    private function requireKey(): void
    {
        if (!$this->available()) {
            throw new \RuntimeException('Configure a base64-encoded 32-byte WMOS_ENCRYPTION_KEY and PHP sodium before storing personal data or credentials.');
        }
    }
}
