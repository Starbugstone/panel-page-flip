<?php

declare(strict_types=1);

namespace App\Service;

final class AppDataEncryptionService
{
    private const PREFIX = 'enc:v1:';

    private string $key;

    public function __construct(string $appDataKey)
    {
        $this->key = $this->normaliseKey($appDataKey);
    }

    public function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->isEncrypted($value)) {
            return $value;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !$this->isEncrypted($value)) {
            // Legacy plaintext remains readable so the explicit migration
            // command can encrypt it. New writes are always encrypted.
            return $value;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted application data is malformed.');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt application data. Verify APP_DATA_KEY.');
        }

        return $plaintext;
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    private function normaliseKey(string $key): string
    {
        if (ctype_xdigit($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
            $decoded = hex2bin($key);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        throw new \InvalidArgumentException(
            'APP_DATA_KEY must be exactly 32 random bytes encoded as 64 hexadecimal characters or Base64.'
        );
    }
}
