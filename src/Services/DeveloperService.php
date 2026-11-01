<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Platform-operator analytics for the Developer Dashboard.
 *
 * Unlike tenant-scoped services, this aggregates ACROSS all tenants — it is the
 * super-admin's view of the whole deployment. All methods are read-only.
 *
 * Sections:
 *   tenantPerformance()      — per-company KPIs (revenue, invoices, users, plan, API usage)
 *   applicationPerformance() — webhook, API and workflow health across the platform
 *   serverPerformance()      — PHP / MySQL / Redis / disk runtime metrics
 */
class DeveloperService
{
    public function __construct(private readonly mysqli $conn) {}

    /* --------------------------------------------------------- platform totals */

    /** @return array<string,int|float> */
    public function platformTotals(): array
    {
        return [
            'tenants'       => $this->scalar("SELECT COUNT(*) FROM `tenants` WHERE delete_flag = 0"),
            'active_tenants'=> $this->scalar("SELECT COUNT(*) FROM `tenants` WHERE delete_flag = 0 AND status = 1"),
            'users'         => $this->scalar("SELECT COUNT(*) FROM `users`"),
            'invoices'      => $this->scalar("SELECT COUNT(*) FROM `invoice_list`"),
            'revenue'       => (float)$this->scalar("SELECT COALESCE(SUM(total),0) FROM `invoice_list` WHERE status != 'cancelled'"),
            'outstanding'   => (float)$this->scalar("SELECT COALESCE(SUM(balance),0) FROM `invoice_list` WHERE status IN ('unpaid','partially_paid')"),
        ];
    }

    /* ------------------------------------------------------- tenant performance */

    /** @return array<int,array<string,mixed>> */
    public function tenantPerformance(): array
    {
        $rows = [];
        $sql = "SELECT t.id, t.tenant_code, t.name, t.status,
                       t.plan_id, t.plan_status, t.plan_expires_at,
                       pl.name AS plan_name, pl.max_users, pl.max_invoices, pl.max_api_calls,
                       (SELECT COUNT(*) FROM `users` u WHERE u.tenant_id = t.id) AS user_count,
                       (SELECT COUNT(*) FROM `invoice_list` i WHERE i.tenant_id = t.id) AS invoice_count,
                       (SELECT COALESCE(SUM(i.total),0) FROM `invoice_list` i WHERE i.tenant_id = t.id AND i.status != 'cancelled') AS revenue,
                       (SELECT COALESCE(SUM(i.balance),0) FROM `invoice_list` i WHERE i.tenant_id = t.id AND i.status IN ('unpaid','partially_paid')) AS outstanding,
                       (SELECT COUNT(*) FROM `api_request_logs` a WHERE a.tenant_id = t.id AND a.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS api_calls_30d,
                       (SELECT MAX(u.last_login) FROM `users` u WHERE u.tenant_id = t.id) AS last_active
                FROM `tenants` t
                LEFT JOIN `tenant_plans` pl ON t.plan_id = pl.id
                WHERE t.delete_flag = 0
                ORDER BY revenue DESC";
        $res = $this->conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['usage_pct'] = ($r['max_invoices'] > 0)
                    ? min(100, round(((int)$r['invoice_count'] / (int)$r['max_invoices']) * 100))
                    : 0;
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /* -------------------------------------------------- application performance */

    /** @return array<string,mixed> */
    public function applicationPerformance(): array
    {
        // Webhooks
        $wh = [
            'total'        => $this->scalar("SELECT COUNT(*) FROM `webhooks`"),
            'active'       => $this->scalar("SELECT COUNT(*) FROM `webhooks` WHERE status = 1"),
            'delivered_24h'=> $this->scalar("SELECT COUNT(*) FROM `webhook_deliveries` WHERE status = 'delivered' AND date_updated >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'failed_24h'   => $this->scalar("SELECT COUNT(*) FROM `webhook_deliveries` WHERE status = 'failed' AND date_updated >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'pending'      => $this->scalar("SELECT COUNT(*) FROM `webhook_deliveries` WHERE status = 'pending'"),
        ];
        $total_recent = $wh['delivered_24h'] + $wh['failed_24h'];
        $wh['success_rate'] = $total_recent > 0 ? round(($wh['delivered_24h'] / $total_recent) * 100, 1) : 100.0;

        // API
        $api = [
            'keys'         => $this->scalar("SELECT COUNT(*) FROM `api_keys` WHERE status = 1"),
            'calls_24h'    => $this->scalar("SELECT COUNT(*) FROM `api_request_logs` WHERE date_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'errors_24h'   => $this->scalar("SELECT COUNT(*) FROM `api_request_logs` WHERE status_code >= 400 AND date_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'avg_ms'       => round((float)$this->scalar("SELECT COALESCE(AVG(duration_ms),0) FROM `api_request_logs` WHERE date_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 1),
        ];

        // Workflows
        $wf = [
            'total'       => $this->scalar("SELECT COUNT(*) FROM `workflows`"),
            'active'      => $this->scalar("SELECT COUNT(*) FROM `workflows` WHERE status = 1"),
            'runs_24h'    => $this->scalar("SELECT COUNT(*) FROM `workflow_logs` WHERE matched = 1 AND date_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'failed_24h'  => $this->scalar("SELECT COUNT(*) FROM `workflow_logs` WHERE result = 'failed' AND date_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
        ];

        return ['webhooks' => $wh, 'api' => $api, 'workflows' => $wf];
    }

    /** API calls per day for the last N days (platform-wide). @return array{labels:string[],values:int[]} */
    public function apiCallsSeries(int $days = 14): array
    {
        $labels = [];
        $values = [];
        $map = [];
        $res = $this->conn->query(
            "SELECT DATE(date_created) AS d, COUNT(*) AS c
             FROM `api_request_logs`
             WHERE date_created >= DATE_SUB(CURDATE(), INTERVAL " . (int)$days . " DAY)
             GROUP BY DATE(date_created)"
        );
        if ($res) { while ($r = $res->fetch_assoc()) { $map[$r['d']] = (int)$r['c']; } }
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('M j', strtotime($d));
            $values[] = $map[$d] ?? 0;
        }
        return ['labels' => $labels, 'values' => $values];
    }

    /** Webhook delivery outcomes per day for the last N days. @return array{labels:string[],delivered:int[],failed:int[]} */
    public function webhookSeries(int $days = 14): array
    {
        $labels = $delivered = $failed = [];
        $dmap = $fmap = [];
        $res = $this->conn->query(
            "SELECT DATE(date_updated) AS d,
                    SUM(status='delivered') AS ok,
                    SUM(status='failed') AS bad
             FROM `webhook_deliveries`
             WHERE date_updated >= DATE_SUB(CURDATE(), INTERVAL " . (int)$days . " DAY)
             GROUP BY DATE(date_updated)"
        );
        if ($res) { while ($r = $res->fetch_assoc()) { $dmap[$r['d']] = (int)$r['ok']; $fmap[$r['d']] = (int)$r['bad']; } }
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('M j', strtotime($d));
            $delivered[] = $dmap[$d] ?? 0;
            $failed[] = $fmap[$d] ?? 0;
        }
        return ['labels' => $labels, 'delivered' => $delivered, 'failed' => $failed];
    }

    /* ------------------------------------------------------- server performance */

    /** @return array<string,mixed> */
    public function serverPerformance(): array
    {
        $mysql_version = '';
        $v = $this->conn->query("SELECT VERSION() AS v");
        if ($v) { $mysql_version = $v->fetch_assoc()['v'] ?? ''; }

        // DB size
        $db_size_mb = 0.0;
        $sz = $this->conn->query("SELECT ROUND(SUM(data_length + index_length)/1024/1024, 2) AS mb FROM information_schema.TABLES WHERE table_schema = DATABASE()");
        if ($sz) { $db_size_mb = (float)($sz->fetch_assoc()['mb'] ?? 0); }

        // Table count
        $table_count = $this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()");

        // Disk
        $disk_total = @disk_total_space(base_app) ?: 0;
        $disk_free  = @disk_free_space(base_app) ?: 0;
        $disk_used  = $disk_total - $disk_free;

        // Memory
        $mem_limit = ini_get('memory_limit');
        $mem_usage = memory_get_usage(true);
        $mem_peak  = memory_get_peak_usage(true);

        // Uploads dir size (best-effort, capped)
        $uploads_mb = $this->dirSizeMb(base_app . 'uploads', 20000);

        return [
            'php_version'   => PHP_VERSION,
            'php_sapi'      => PHP_SAPI,
            'mysql_version' => $mysql_version,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? php_uname('s'),
            'os'            => php_uname('s') . ' ' . php_uname('r'),
            'db_size_mb'    => $db_size_mb,
            'table_count'   => $table_count,
            'disk_total'    => $disk_total,
            'disk_free'     => $disk_free,
            'disk_used'     => $disk_used,
            'disk_pct'      => $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 1) : 0,
            'mem_limit'     => $mem_limit,
            'mem_usage'     => $mem_usage,
            'mem_peak'      => $mem_peak,
            'uploads_mb'    => $uploads_mb,
            'load_avg'      => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'opcache'       => function_exists('opcache_get_status') ? (bool)@opcache_get_status(false) : false,
            'extensions'    => [
                'curl'    => extension_loaded('curl'),
                'gd'      => extension_loaded('gd'),
                'mbstring'=> extension_loaded('mbstring'),
                'redis'   => extension_loaded('redis'),
                'opcache' => extension_loaded('Zend OPcache'),
            ],
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    private function scalar(string $sql): int|float|string
    {
        $res = $this->conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row();
        return $row ? $row[0] : 0;
    }

    private function dirSizeMb(string $dir, int $maxFiles = 20000): float
    {
        if (!is_dir($dir)) return 0.0;
        $bytes = 0; $count = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) { $bytes += $f->getSize(); }
                if (++$count > $maxFiles) break;
            }
        } catch (\Throwable $e) {
            return 0.0;
        }
        return round($bytes / 1024 / 1024, 2);
    }
}
