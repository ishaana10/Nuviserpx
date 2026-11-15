<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Keeps big list pages fast: show the newest rows by default, with an obvious way to see everything.
 * The pages render every row into the browser, so an old, busy company would otherwise send tens of
 * thousands of table rows on every visit. Nothing is hidden silently - the banner says how many are shown.
 */
final class ListWindow
{
    public const DEFAULT_CAP = 2000;

    /** Rows to show, or null when the user asked for everything (?all=1). */
    public static function cap(?array $get = null): ?int
    {
        $get ??= $_GET;
        return !empty($get['all']) ? null : self::DEFAULT_CAP;
    }

    /** SQL suffix for the list query (empty when showing everything). Always an integer, never user input. */
    public static function limitSql(?array $get = null): string
    {
        $cap = self::cap($get);
        return $cap === null ? '' : ' LIMIT ' . (int)$cap;
    }

    /** Banner shown above the table, or '' when every row is already on screen. */
    public static function notice(int $shown, int $total, string $allUrl): string
    {
        if ($total <= $shown) {
            return '';
        }
        return '<div class="alert alert-info py-2 mb-2">Showing the newest ' . number_format($shown) . ' of ' . number_format($total)
            . '. <a href="' . htmlspecialchars($allUrl, ENT_QUOTES) . '">Show all ' . number_format($total) . '</a> (the page may be slow).</div>';
    }

    /** The banner for a capped list page; '' when everything is shown. */
    public static function banner(\mysqli $conn, string $table, int $tenantId, string $page, ?array $get = null): string
    {
        $cap = self::cap($get);
        if ($cap === null) {
            return '';
        }
        $total = self::total($conn, $table, $tenantId);
        return self::notice(min($cap, $total), $total, './?page=' . rawurlencode($page) . '&all=1');
    }

    /** Count of a tenant's rows in a list table (table name is checked against a fixed list). */
    public static function total(\mysqli $conn, string $table, int $tenantId): int
    {
        if (!in_array($table, ['invoice_list', 'sales_order_list', 'payment_list'], true)) {
            throw new \InvalidArgumentException("Not a capped list: {$table}");
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ?");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    }
}
