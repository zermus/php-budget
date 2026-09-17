<?php

declare(strict_types=1);

namespace App;

/**
 * Encryption at rest for secrets stored in the database (the SMTP password).
 *
 * AES-256-GCM with the key from config.php's 'app_key', so a leaked database
 * dump or backup no longer contains the relay password; the key lives with
 * the database credentials, outside the database. Stored form:
 * "enc:v1:" + base64(iv . tag . ciphertext).
 *
 * Without a valid key (or the openssl extension) values are stored as they
 * were before 0.6, and decrypt() passes legacy plaintext straight through,
 * so nothing breaks while an install is between the two.
 */
final class Secrets
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function available(): bool
    {
        return self::key() !== null;
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    /** Encrypt for storage; returns the plaintext unchanged when no key is configured. */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            return $plaintext;
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt a secret.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * The plaintext of a stored value. Legacy plaintext passes through; null
     * when an encrypted value can't be opened (missing or different key).
     */
    public static function decrypt(?string $stored): ?string
    {
        if (!self::isEncrypted($stored)) {
            return $stored;
        }

        $key = self::key();
        $raw = base64_decode(substr((string) $stored, strlen(self::PREFIX)), true);
        if ($key === null || $raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES)
        );

        return $plaintext === false ? null : $plaintext;
    }

    /** A fresh value for config.php's 'app_key'. */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private static function key(): ?string
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }

        $value = (string) App::config('app_key', '');
        if (str_starts_with($value, 'base64:')) {
            $value = substr($value, strlen('base64:'));
        }
        $key = base64_decode($value, true);

        return ($key !== false && strlen($key) === 32) ? $key : null;
    }
}
