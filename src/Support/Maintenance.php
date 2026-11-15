<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maintenance mode. While the flag file exists every web request gets a 503 "back shortly" page, so a deployment or
 * restore never runs against live traffic. bin/deploy.sh switches it on and off; the health check stays reachable.
 */
final class Maintenance
{
    public static function flagPath(): string
    {
        return (defined('base_app') ? base_app : dirname(__DIR__, 2) . '/') . 'maintenance.flag';
    }

    public static function isOn(): bool
    {
        return is_file(self::flagPath());
    }

    public static function on(): bool
    {
        return @file_put_contents(self::flagPath(), gmdate('c') . "\n") !== false;
    }

    public static function off(): void
    {
        @unlink(self::flagPath());
    }

    /** A request that must still be answered during maintenance (the health probe, and listed bypass addresses). */
    public static function allows(string $path, string $ip, string $allowList = ''): bool
    {
        if (preg_match('~(^|/)health\.php$~', $path)) {
            return true;
        }
        $ips = array_filter(array_map('trim', explode(',', $allowList)));
        return $ip !== '' && in_array($ip, $ips, true);
    }

    /** Answer the request with the maintenance page and stop. Call only when isOn() and not allowed. */
    public static function respond(bool $json = false): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 300');
            header('Cache-Control: no-store');
        }
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'maintenance', 'message' => 'The system is being updated. Please try again shortly.']);
        } else {
            echo "<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Back shortly</title></head>"
                . "<body style='font-family:sans-serif;text-align:center;padding:12vh 1rem;color:#334155'><h2>We'll be right back</h2>"
                . "<p>The system is being updated. Please try again in a few minutes.</p></body></html>";
        }
        exit;
    }

    /** Gate for entry points. */
    public static function guard(bool $json = false): void
    {
        if (php_sapi_name() === 'cli' || !self::isOn()) {
            return;
        }
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!self::allows($path, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)getenv('MAINTENANCE_ALLOW_IPS'))) {
            self::respond($json);
        }
    }
}
