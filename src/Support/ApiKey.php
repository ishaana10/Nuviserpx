<?php
declare(strict_types=1);

namespace App\Support;

/**
 * API keys are random 128-bit secrets, so a plain SHA-256 is the right storage: nothing to brute-force,
 * and the database never holds a usable key. The key is shown once, when it is created.
 */
final class ApiKey
{
    public const MASK = '…';

    /** @return array{key:string,hash:string,prefix:string,display:string} */
    public static function generate(): array
    {
        $key = 'erpx_' . bin2hex(random_bytes(16));
        return ['key' => $key, 'hash' => self::hash($key), 'prefix' => substr($key, 0, 9), 'display' => self::masked(substr($key, 0, 9))];
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** What is stored in the old plain-text column for a hashed key: unique, and useless as a credential. */
    public static function masked(string $prefix): string
    {
        return $prefix . self::MASK . bin2hex(random_bytes(4));
    }

    public static function isMasked(string $stored): bool
    {
        return str_contains($stored, self::MASK);
    }

    /** Text to show in lists and forms for a stored row. */
    public static function display(array $row): string
    {
        if (!empty($row['key_hash'])) {
            return (string)($row['key_prefix'] ?? '') . self::MASK . ' (shown once, when created)';
        }
        return (string)($row['api_key'] ?? '');
    }

    /**
     * The active key row for a presented key, or null. Keys created before hashing are matched on the old
     * column and given a hash the first time they are used.
     */
    public static function find(\mysqli $conn, string $presented): ?array
    {
        if ($presented === '' || strlen($presented) > 200) {
            return null;
        }
        $hash = self::hash($presented);
        $stmt = $conn->prepare("SELECT * FROM `api_keys` WHERE status = 1 AND (key_hash = ? OR (key_hash IS NULL AND api_key = ?)) LIMIT 1");
        $stmt->bind_param('ss', $hash, $presented);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }
        if (empty($row['key_hash'])) {
            $prefix = substr($presented, 0, 9);
            $up = $conn->prepare("UPDATE `api_keys` SET key_hash = ?, key_prefix = ? WHERE id = ? AND key_hash IS NULL");
            $id = (int)$row['id'];
            $up->bind_param('ssi', $hash, $prefix, $id);
            $up->execute();
        }
        return $row;
    }
}
