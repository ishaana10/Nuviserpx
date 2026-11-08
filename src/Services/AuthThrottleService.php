<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Slows down password guessing on sign-in and password reset.
 *
 * Failed sign-ins, password-reset code requests and wrong reset codes are recorded. Within a 15 minute window a
 * request is refused when any of three counts has reached its limit:
 *   - the same username from the same address (the usual attacker, and the person who forgot their password),
 *   - the same username from anywhere (a spread-out attack on one account, with a higher limit so a stranger
 *     cannot easily lock someone out),
 *   - the same address against any username (one machine trying many accounts).
 * A refusal says how long to wait. Only the connecting address is used, never forwarded headers a client can set.
 */
class AuthThrottleService
{
    public const WINDOW = 900;
    public const LIMITS = [
        'login'       => ['pair' => 5, 'user' => 20, 'ip' => 30],
        'otp_request' => ['pair' => 3, 'user' => 5,  'ip' => 10],
        'otp_verify'  => ['pair' => 5, 'user' => 10, 'ip' => 20],
    ];

    public function __construct(private readonly mysqli $conn) {}

    /**
     * Pure: seconds to wait given the times (unix) of earlier failures, or 0 when under the limit.
     * Blocked until enough old failures have aged out of the window to drop the count below the limit.
     *
     * @param int[] $times
     */
    public static function retryAfter(array $times, int $now, int $limit, int $window = self::WINDOW): int
    {
        $recent = array_values(array_filter($times, fn($t) => $t > $now - $window && $t <= $now + 5));
        if ($limit < 1 || count($recent) < $limit) {
            return 0;
        }
        sort($recent);
        // The failure that must age out is the (count - limit + 1)th oldest.
        $pivot = $recent[count($recent) - $limit];
        return max(1, $pivot + $window - $now);
    }

    public static function identifier(string $username): string
    {
        return substr(mb_strtolower(trim($username)), 0, 190);
    }

    public static function clientIp(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** "15 minutes", "1 minute", "40 seconds" */
    public static function waitText(int $seconds): string
    {
        if ($seconds >= 60) {
            $m = (int)ceil($seconds / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's');
        }
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    /** Seconds the caller must wait before trying again, 0 if allowed. If the table is unavailable, allow. */
    public function check(string $scope, string $username, string $ip = ''): int
    {
        $lim = self::LIMITS[$scope] ?? null;
        if ($lim === null) {
            return 0;
        }
        $id = self::identifier($username);
        $ip = $ip !== '' ? $ip : self::clientIp();
        $now = $this->now();
        $worst = 0;
        foreach ([
            ['pair', 'identifier = ? AND ip = ?', [$id, $ip]],
            ['user', 'identifier = ?', [$id]],
            ['ip',   'ip = ?', [$ip]],
        ] as [$key, $where, $args]) {
            $q = $this->conn->prepare("SELECT UNIX_TIMESTAMP(date_created) t FROM auth_attempts
                WHERE scope = ? AND {$where} AND date_created > DATE_SUB(NOW(), INTERVAL " . self::WINDOW . " SECOND)");
            if (!$q) {
                return 0;
            }
            $q->bind_param(str_repeat('s', 1 + count($args)), $scope, ...$args);
            $q->execute();
            $times = array_map('intval', array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 't'));
            $worst = max($worst, self::retryAfter($times, $now, $lim[$key]));
        }
        return $worst;
    }

    /** Record one failure (or one reset-code request). */
    public function record(string $scope, string $username, string $ip = ''): void
    {
        $id = self::identifier($username);
        $ip = $ip !== '' ? $ip : self::clientIp();
        $s = $this->conn->prepare("INSERT INTO auth_attempts (scope, identifier, ip) VALUES (?,?,?)");
        if ($s) {
            $s->bind_param('sss', $scope, $id, $ip);
            $s->execute();
        }
        if (random_int(1, 50) === 1) {
            $this->conn->query("DELETE FROM auth_attempts WHERE date_created < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    }

    /** A success wipes the failures for that username and address. */
    public function clear(string $scope, string $username, string $ip = ''): void
    {
        $id = self::identifier($username);
        $ip = $ip !== '' ? $ip : self::clientIp();
        $s = $this->conn->prepare("DELETE FROM auth_attempts WHERE scope = ? AND identifier = ? AND ip = ?");
        if ($s) {
            $s->bind_param('sss', $scope, $id, $ip);
            $s->execute();
        }
    }

    private function now(): int
    {
        $r = $this->conn->query("SELECT UNIX_TIMESTAMP() t");
        return (int)($r ? ($r->fetch_assoc()['t'] ?? time()) : time());
    }
}
