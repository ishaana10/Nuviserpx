<?php

declare(strict_types=1);

namespace App\Support;

/** The pure rules behind bin/backup.php, bin/restore-check.php and bin/restore.php (kept apart so they can be tested without a database). */
final class BackupPlan
{
    /** @return array{host:string,port:int} "localhost", "db.example.com:3307" and "127.0.0.1" are all accepted. */
    public static function hostPort(string $server): array
    {
        $server = trim($server);
        if (preg_match('~^(.+):(\d{1,5})$~', $server, $m) && strpos($m[1], ':') === false) {
            return ['host' => $m[1], 'port' => (int)$m[2]];
        }
        return ['host' => $server === '' ? 'localhost' : $server, 'port' => 3306];
    }

    /** Contents of a MySQL client options file, so the password never appears on a command line. */
    public static function clientOptionsFile(string $user, string $password, string $server): string
    {
        $q = fn(string $v) => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $v) . '"';
        $hp = self::hostPort($server);
        return "[client]\nuser=" . $q($user) . "\npassword=" . $q($password) . "\nhost=" . $q($hp['host']) . "\nport=" . $hp['port'] . "\n";
    }

    public static function fileName(string $kind, int $now): string
    {
        $ext = ['db' => 'sql.gz', 'files' => 'tar.gz'][$kind] ?? 'bin';
        return 'nuviserpx-' . $kind . '-' . gmdate('Ymd-His', $now) . '.' . $ext;
    }

    /** Whether a name was made by this tool (only those are ever deleted by retention). */
    public static function isBackupName(string $name): bool
    {
        return preg_match('~^nuviserpx-(db|files)-\d{8}-\d{6}\.(sql\.gz|tar\.gz)(\.enc)?(\.sha256)?$~', $name) === 1;
    }

    /**
     * Which backup files to delete: older than $keepDays, but never the newest $keepMin of each kind.
     *
     * @param array<string,int> $files name => modified time (unix)
     * @return string[]
     */
    public static function expired(array $files, int $now, int $keepDays, int $keepMin = 3): array
    {
        $groups = [];
        foreach ($files as $name => $mtime) {
            if (!self::isBackupName($name)) {
                continue;
            }
            $base = preg_replace('~\.sha256$~', '', $name);
            $kind = strpos($base, '-db-') !== false ? 'db' : 'files';
            $groups[$kind][$base] = max($groups[$kind][$base] ?? 0, (int)$mtime);
        }
        $delete = [];
        foreach ($groups as $set) {
            arsort($set);
            $i = 0;
            foreach ($set as $base => $mtime) {
                $i++;
                if ($i > $keepMin && $mtime < $now - $keepDays * 86400) {
                    $delete[] = $base;
                    $delete[] = $base . '.sha256';
                }
            }
        }
        return array_values(array_intersect($delete, array_keys($files)));
    }

    /** Hours since the newest database backup, or null when there is none. */
    public static function ageHours(?int $lastBackupTime, int $now): ?float
    {
        return $lastBackupTime === null ? null : round(max(0, $now - $lastBackupTime) / 3600, 1);
    }

    /** "name_restorecheck" for the scratch database; refuses to ever name the live one. */
    public static function scratchDb(string $live): string
    {
        $name = substr(preg_replace('~[^A-Za-z0-9_]~', '', $live), 0, 50) . '_restorecheck';
        return $name === $live ? $name . '_x' : $name;
    }
}
