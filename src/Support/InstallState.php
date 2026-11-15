<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether an install that has no lock file is really an installed system.
 *
 * Why: the lock used to be tracked in git, so some servers have it only because it came with the code. Once it
 * is no longer shipped, a normal update would delete it on those servers and send everyone to the install
 * wizard. If the database named in .env already holds a system (a users table with rows), we recreate the lock
 * instead. A fresh clone has no such database, so it still gets the wizard.
 *
 * Plain mysqli, no autoloader: this runs at the very top of initialize.php.
 */
final class InstallState
{
    /** @return array<string,string> */
    public static function parseEnv(string $contents): array
    {
        $env = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim(trim($v), "\"'");
        }
        return $env;
    }

    /** True when the database in $envFile already contains an installed system. Never throws. */
    public static function databaseHasSystem(string $envFile): bool
    {
        if (!is_file($envFile)) {
            return false;
        }
        $env = self::parseEnv((string)file_get_contents($envFile));
        if (empty($env['DB_NAME']) || !isset($env['DB_USERNAME'])) {
            return false;
        }
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $host = $env['DB_SERVER'] ?? 'localhost';
            $port = 3306;
            if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
                [$host, $port] = [$m[1], (int)$m[2]];
            }
            $c = @new \mysqli($host, $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '', $env['DB_NAME'], $port);
            if ($c->connect_error) {
                return false;
            }
            $r = @$c->query('SELECT 1 FROM `users` LIMIT 1');
            $has = $r instanceof \mysqli_result && $r->num_rows > 0;
            $c->close();
            return $has;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Recreate the lock for an existing system. Returns true when the system is installed. */
    public static function adoptExisting(string $lockFile, string $envFile): bool
    {
        if (!self::databaseHasSystem($envFile)) {
            return false;
        }
        @file_put_contents($lockFile, date('Y-m-d H:i:s') . " - Existing installation detected; lock recreated.\n");
        return true;
    }
}
