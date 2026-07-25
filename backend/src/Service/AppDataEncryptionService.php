<?php

namespace App\Service;

class AppDataEncryptionService
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
        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        if (ctype_xdigit($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
            return hex2bin($key);
        }

        return hash('sha256', $key, true);
    }
}
