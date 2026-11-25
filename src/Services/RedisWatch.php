<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Keeps Redis running on A2 Hosting without anyone clicking a button, and writes any trouble to the application's
 * error log (logs/php_errors.log, shown on Developer > Error Logs).
 *
 * Called from config.php on every request but does real work at most once every five minutes. A2 starts Redis for an
 * account when ~/.redis.on exists (it checks about every five minutes), so each check:
 *   - sees whether Redis answers (socket or 127.0.0.1:6379) and logs when it comes back;
 *   - if not: clears a dead socket file left over from an old server, makes sure ~/.redis.on exists, and logs it;
 *   - if it is still not running well after A2's normal start delay, logs an error saying what to check.
 * The same message is logged at most once every six hours, so the log is not flooded.
 * Switch it off by saving a setting redis_auto = 0.
 */
final class RedisWatch
{
    public const EVERY = 300;        // seconds between checks
    public const GRACE = 900;        // how long A2 gets to start Redis after the flag is created
    public const REPEAT = 21600;     // repeat the same log line at most this often

    /**
     * @param array{homes?:string[],state?:string,tcp?:bool,socket?:string} $opt for tests
     * @return array{state:string}|null  null when it was not time to check (or switched off)
     */
    public static function tick($settings = null, ?int $now = null, array $opt = []): ?array
    {
        $now = $now ?? time();
        if ($settings !== null && (string)$settings->info('redis_auto') === '0') {
            return null;
        }
        $file = $opt['state'] ?? (self::logDir() . '/redis_watch.json');
        $st = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
        if (isset($st['checked']) && $now - (int)$st['checked'] < self::EVERY) {
            return null;
        }
        $st['checked'] = $now; // claim this slot first so simultaneous requests do not all run it

        $homes = $opt['homes'] ?? RedisCacheService::homeCandidates();
        $override = $opt['socket'] ?? (string)($settings ? ($settings->info('redis_socket') ?: '') : '');
        $socket = RedisCacheService::resolveSocketPath($homes, $override);
        $tcp = $opt['tcp'] ?? true;

        $result = self::check($st, $now, $homes, $socket, $tcp);
        @file_put_contents($file, json_encode($st), LOCK_EX);
        return $result;
    }

    private static function check(array &$st, int $now, array $homes, string $socket, bool $tcp): array
    {
        $up = $socket !== '' && file_exists($socket) && RedisCacheService::probeSocket($socket)['errno'] === 0;
        if (!$up && $tcp) {
            $fp = @fsockopen('127.0.0.1', 6379, $en, $es, 0.3);
            if ($fp) { fclose($fp); $up = true; }
        }
        if ($up) {
            if (!empty($st['down_since'])) {
                self::log($st, 'recovered', 'Redis recovered and is answering again after ' . max(1, intdiv($now - (int)$st['down_since'], 60)) . ' minute(s) down.', 0, $now);
            }
            $st = ['checked' => $now, 'logged' => $st['logged'] ?? []];
            return ['state' => 'up'];
        }

        $st['down_since'] = $st['down_since'] ?? $now;
        if (!extension_loaded('redis')) {
            self::log($st, 'ext', 'The PHP "redis" extension is not enabled on this server, so the app cannot talk to Redis even when it is running. Enable it in cPanel > Select PHP Version > Extensions.', self::REPEAT, $now);
        }
        if (RedisCacheService::clearStaleSocket($socket)) {
            self::log($st, 'stale', "Removed a dead Redis socket file ($socket) that nothing was listening on.", 0, $now);
        }
        $flag = RedisCacheService::touchStartFlag($homes);
        if (!$flag['created']) {
            self::log($st, 'noflag', 'Could not create the ~/.redis.on start flag (tried: ' . implode(', ', $flag['failed'] ?: ['no home folder found']) . '). Create it by hand: touch ~/.redis.on', self::REPEAT, $now);
            return ['state' => 'not_running'];
        }
        if (empty($st['flag_at'])) {
            $st['flag_at'] = $now;
            self::log($st, 'flag', 'Redis is not running. Created ' . implode(', ', $flag['created']) . '; A2 Hosting starts Redis within about five minutes.', 0, $now);
            return ['state' => 'starting'];
        }
        if ($now - (int)$st['flag_at'] < self::GRACE) {
            return ['state' => 'starting'];
        }
        self::log($st, 'stuck', 'Redis is still not running ' . intdiv($now - (int)$st['flag_at'], 60) . ' minutes after ' . implode(', ', $flag['created'])
            . ' was created, so A2 Hosting did not start it. Redis is provided only on A2 Turbo shared/reseller and managed plans: check this account\'s plan in the A2 customer portal or ask A2 support to enable Redis for it. Expected socket: ' . ($socket ?: 'unknown') . '.', self::REPEAT, $now);
        return ['state' => 'not_running'];
    }

    private static function log(array &$st, string $key, string $msg, int $every, int $now): void
    {
        $last = (int)($st['logged'][$key] ?? 0);
        if ($last > 0 && $now - $last < $every) {
            return;
        }
        $st['logged'][$key] = $now;
        error_log('[Redis] ' . $msg);
    }

    private static function logDir(): string
    {
        return \App\Support\ErrorLog::dir();
    }
}
