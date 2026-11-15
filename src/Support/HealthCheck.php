<?php

declare(strict_types=1);

namespace App\Support;

/** Turns the facts gathered by health.php into one verdict. Pure, so every rule can be tested. */
final class HealthCheck
{
    /**
     * @param array<string,mixed> $f db_ok, pending_migrations, disk_free_pct, backup_age_hours (null = not configured/none),
     *                               backup_required, app_key_set, is_production, uploads_writable, logs_writable
     * @return array{status:string,problems:string[]}
     */
    public static function evaluate(array $f): array
    {
        $problems = [];
        if (empty($f['db_ok'])) {
            $problems[] = 'database unreachable';
        }
        if ((int)($f['pending_migrations'] ?? 0) > 0) {
            $problems[] = (int)$f['pending_migrations'] . ' migration(s) not applied';
        }
        if (isset($f['disk_free_pct']) && $f['disk_free_pct'] !== null && $f['disk_free_pct'] < 10) {
            $problems[] = 'disk almost full (' . $f['disk_free_pct'] . '% free)';
        }
        if (!empty($f['backup_required'])) {
            if (!isset($f['backup_age_hours']) || $f['backup_age_hours'] === null) {
                $problems[] = 'no database backup found';
            } elseif ($f['backup_age_hours'] > 36) {
                $problems[] = 'last backup is ' . $f['backup_age_hours'] . ' hours old';
            }
        }
        if (!empty($f['is_production']) && empty($f['app_key_set'])) {
            $problems[] = 'APP_KEY is not set';
        }
        if (isset($f['uploads_writable']) && !$f['uploads_writable']) {
            $problems[] = 'uploads folder is not writable';
        }
        if (isset($f['logs_writable']) && !$f['logs_writable']) {
            $problems[] = 'logs folder is not writable';
        }
        return ['status' => $problems ? 'degraded' : 'ok', 'problems' => $problems];
    }

    /** Names of migration files not yet recorded as applied. @param string[] $files @param string[] $applied @return string[] */
    public static function pending(array $files, array $applied): array
    {
        $files = array_map('basename', $files);
        sort($files);
        return array_values(array_diff($files, $applied));
    }
}
