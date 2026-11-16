<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Authenticated encryption (AES-256-GCM) for secrets stored in the database, keyed by
 * APP_KEY in .env ("base64:" + 32 random bytes). Output: base64(iv | tag | ciphertext).
 */
final class Encrypter
{
    private const CIPHER = 'aes-256-gcm';

    private function __construct(private readonly string $key)
    {
    }

    /**
     * Encrypter using APP_KEY.
     *
     * @throws RuntimeException when APP_KEY is missing or not 32 bytes
     */
    public static function fromConfig(): self
    {
        return self::fromKey((string) Config::get('app.key', ''));
    }

    /**
     * @throws RuntimeException when the key is missing or not 32 bytes
     */
    public static function fromKey(string $key): self
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (!is_string($raw) || strlen($raw) !== 32) {
            throw new RuntimeException('APP_KEY is missing or invalid. Set it in .env (see .env.example).');
        }

        return new self($raw);
    }

    public static function isConfigured(): bool
    {
        try {
            self::fromConfig();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * @throws RuntimeException when the value was tampered with or encrypted with another key
     */
    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('The encrypted value is malformed.');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('The encrypted value could not be decrypted (APP_KEY changed?).');
        }

        return $plain;
    }
}
