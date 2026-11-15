<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The logo the customer and employee portals show: the company's own logo when it has set one, otherwise the
 * NuvisERPX logo. (Never a broken-image placeholder.)
 */
final class PortalBranding
{
    public const DEFAULT_LOGO = 'dist/img/nuviserpx-logo.svg';

    /**
     * Pure: the first of the candidate logo paths that points at a real image file, else the default.
     * @param array<int,?string> $candidates  paths relative to the app root, most specific first
     */
    public static function pick(array $candidates, callable $exists): string
    {
        foreach ($candidates as $c) {
            $path = ltrim(explode('?', trim((string)$c))[0], '/');
            if ($path !== '' && strpos($path, '..') === false && $exists($path)) {
                return (string)$c !== '' ? ltrim((string)$c, '/') : $path;
            }
        }
        return self::DEFAULT_LOGO;
    }

    /**
     * The logo URL for a company (the signed-in customer's or employee's). With no company given (sign-in and
     * activation pages) it is the logo of the only company, or the default when there are several.
     */
    public static function logoUrl(\mysqli $conn, ?int $tenantId = null): string
    {
        $candidates = [];
        try {
            if ($tenantId === null) {
                $r = $conn->query("SELECT id FROM tenants LIMIT 2");
                $ids = $r ? array_column($r->fetch_all(MYSQLI_ASSOC), 'id') : [];
                $tenantId = count($ids) === 1 ? (int)$ids[0] : 0;
            }
            if ($tenantId > 0) {
                $q = $conn->prepare("SELECT logo FROM tenants WHERE id = ?");
                if ($q) {
                    $q->bind_param('i', $tenantId);
                    $q->execute();
                    $row = $q->get_result()->fetch_assoc();
                    $candidates[] = $row['logo'] ?? null;
                }
                $q2 = $conn->prepare("SELECT logo_path FROM invoice_templates WHERE tenant_id = ? LIMIT 1");
                if ($q2) {
                    $q2->bind_param('i', $tenantId);
                    $q2->execute();
                    $row2 = $q2->get_result()->fetch_assoc();
                    $candidates[] = $row2['logo_path'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            $candidates = [];
        }
        $rel = self::pick($candidates, fn (string $p): bool => defined('base_app') && is_file(base_app . $p));
        return base_url . $rel;
    }
}
