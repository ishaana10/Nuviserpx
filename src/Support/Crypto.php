<?php

namespace App\Support;

/**
 * At-rest encryption for stored secrets (tenant SMTP passwords).
 * AES-256-GCM. The key comes from APP_KEY in .env; without one it falls back
 * to a key derived from the database credentials, which keeps secrets
 * unreadable in DB dumps but means setting APP_KEY later re-encrypts on the
 * next save. Values are stored as "enc:v1:<base64(iv.tag.cipher)>".
 */
class Crypto
{
    private const PREFIX = 'enc:v1:';

    /** The key used for new values: APP_KEY, or (older installs only) one derived from the database credentials. */
    private static function key(): string
    {
        $appKey = getenv('APP_KEY') ?: '';
        if ($appKey !== '') {
            return hash('sha256', $appKey, true);
        }
        return self::legacyKey();
    }

    private static function legacyKey(): string
    {
        return hash('sha256', 'nuviserpx|' . (getenv('DB_PASSWORD') ?: '') . '|' . (getenv('DB_NAME') ?: ''), true);
    }

    /** True when a real APP_KEY is configured. Production should always have one. */
    public static function hasAppKey(): bool
    {
        return (getenv('APP_KEY') ?: '') !== '';
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Decrypts stored values; a legacy plain-text value is returned as-is. */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false && self::hasAppKey()) {
            // Saved before APP_KEY was set: still readable, and re-encrypted under APP_KEY the next time it is saved.
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::legacyKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        }
        return $plain === false ? '' : $plain;
    }

    public static function isEncrypted(string $stored): bool
    {
        return strpos($stored, self::PREFIX) === 0;
    }
}
