<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Customisable dashboards: a catalogue of widgets, the data behind each one,
 * and per-user dashboards (name + ordered widget layout) stored as JSON.
 *
 * A layout is a list of placements:
 *   [{ "uid": "w3k9", "widget": "kpi_revenue", "size": "sm", "config": {"title": "…"} }, …]
 */
class DashboardService
{
    public const SIZES = ['sm', 'md', 'lg', 'xl', 'full'];

    public const RANGES = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30'    => 'Last 30 days',
        'qtd'        => 'Quarter to date',
        'ytd'        => 'Year to date',
        'last_year'  => 'Last year',
        'last_12m'   => 'Last 12 months',
    ];

    private const MAX_DASHBOARDS = 12;
    private const MAX_WIDGETS = 40;

    /** @var callable(string):bool */
    private $canAccess;

    public function __construct(
        private readonly ?mysqli $conn,
        private readonly int $tenantId,
        private readonly int $userId,
        ?callable $canAccess = null
    ) {
        $this->canAccess = $canAccess ?? static fn (string $perm): bool => true;
    }

    /* ==================================================================
     * Widget catalogue
     * ================================================================== */

    /**
     * Every widget the system knows about. `perm` is the role permission needed
     * to see it (null = everyone). `sizes` lists the widths a user may pick.
     */
    public static function definitions(): array
    {
        $kpi = ['sm', 'md', 'lg'];
        $chart = ['md', 'lg', 'xl', 'full'];
        $list = ['md', 'lg', 'xl', 'full'];

        return [
            // Finance
            'kpi_revenue'          => ['title' => 'Revenue invoiced',      'category' => 'Finance',   'type' => 'kpi',   'icon' => 'fas fa-file-invoice-dollar', 'tone' => 'blue',   'perm' => 'invoices_view', 'size' => 'sm', 'sizes' => $kpi,   'ranged' => true,  'description' => 'Total of issued invoices in the period, compared with the previous period.'],
            'kpi_receipts'         => ['title' => 'Cash received',         'category' => 'Finance',   'type' => 'kpi',   'icon' => 'fas fa-hand-holding-usd',    'tone' => 'green',  'perm' => 'payments_view', 'size' => 'sm', 'sizes' => $kpi,   'ranged' => true,  'description' => 'Posted customer receipts in the period.'],
            'kpi_receivables'      => ['title' => 'Outstanding receivables','category' => 'Finance',  'type' => 'kpi',   'icon' => 'fas fa-balance-scale',       'tone' => 'amber',  'perm' => 'invoices_view', 'size' => 'sm', 'sizes' => $kpi,   'ranged' => false, 'description' => 'Unpaid invoice balances today, with the overdue share.'],
            'kpi_journals'         => ['title' => 'Journal entries',       'category' => 'Finance',   'type' => 'kpi',   'icon' => 'fas fa-book',                'tone' => 'indigo', 'perm' => 'journals_view', 'size' => 'sm', 'sizes' => $kpi,   'ranged' => true,  'description' => 'Journals dated in the period.'],
            'chart_financial'      => ['title' => 'Debits vs credits',     'category' => 'Finance',   'type' => 'chart', 'icon' => 'fas fa-chart-bar',           'tone' => 'blue',   'perm' => 'journals_view', 'size' => 'xl', 'sizes' => $chart, 'ranged' => true,  'description' => 'Monthly journal movement split by debit and credit groups.', 'charts' => ['bar', 'line', 'area']],
            'chart_cashflow'       => ['title' => 'Invoiced vs received',  'category' => 'Finance',   'type' => 'chart', 'icon' => 'fas fa-chart-area',          'tone' => 'green',  'perm' => 'invoices_view', 'size' => 'lg', 'sizes' => $chart, 'ranged' => true,  'description' => 'Monthly invoiced revenue against cash collected.', 'charts' => ['area', 'line', 'bar']],
            'chart_invoice_status' => ['title' => 'Invoices by status',    'category' => 'Finance',   'type' => 'chart', 'icon' => 'fas fa-chart-pie',           'tone' => 'amber',  'perm' => 'invoices_view', 'size' => 'md', 'sizes' => $chart, 'ranged' => true,  'description' => 'Value of invoices in each status.', 'charts' => ['donut', 'bar']],
            'list_overdue'         => ['title' => 'Overdue invoices',      'category' => 'Finance',   'type' => 'list',  'icon' => 'fas fa-exclamation-circle',  'tone' => 'red',    'perm' => 'invoices_view', 'size' => 'lg', 'sizes' => $list,  'ranged' => false, 'description' => 'Invoices past their due date, oldest first.'],
            'list_recent_invoices' => ['title' => 'Recent invoices',       'category' => 'Finance',   'type' => 'list',  'icon' => 'fas fa-receipt',             'tone' => 'blue',   'perm' => 'invoices_view', 'size' => 'lg', 'sizes' => $list,  'ranged' => false, 'description' => 'The latest invoices raised.'],
            'list_top_customers'   => ['title' => 'Top customers',         'category' => 'Finance',   'type' => 'list',  'icon' => 'fas fa-crown',               'tone' => 'amber',  'perm' => 'invoices_view', 'size' => 'md', 'sizes' => $list,  'ranged' => true,  'description' => 'Customers ranked by invoiced value in the period.'],
            'list_journals'        => ['title' => 'Recent journals',       'category' => 'Finance',   'type' => 'list',  'icon' => 'fas fa-stream',              'tone' => 'indigo', 'perm' => 'journals_view', 'size' => 'lg', 'sizes' => $list,  'ranged' => false, 'description' => 'Latest journal vouchers and their totals.'],
            'list_accounts'        => ['title' => 'Chart of accounts',     'category' => 'Finance',   'type' => 'list',  'icon' => 'fas fa-list-alt',            'tone' => 'indigo', 'perm' => 'journals_view', 'size' => 'lg', 'sizes' => $list,  'ranged' => false, 'description' => 'A quick look at active accounts and their groups.'],

            // People
            'kpi_employees'        => ['title' => 'Active staff',          'category' => 'People',    'type' => 'kpi',   'icon' => 'fas fa-users',               'tone' => 'cyan',   'perm' => 'employees_view', 'size' => 'sm', 'sizes' => $kpi,  'ranged' => true,  'description' => 'Active employees, with new joiners in the period.'],
            'kpi_payroll'          => ['title' => 'Net payroll',           'category' => 'People',    'type' => 'kpi',   'icon' => 'fas fa-money-check-alt',     'tone' => 'green',  'perm' => 'payroll_view',   'size' => 'sm', 'sizes' => $kpi,  'ranged' => true,  'description' => 'Net salaries paid in the period, compared with the previous period.'],
            'kpi_pending_leaves'   => ['title' => 'Leave awaiting review', 'category' => 'People',    'type' => 'kpi',   'icon' => 'fas fa-umbrella-beach',      'tone' => 'amber',  'perm' => 'employees_view', 'size' => 'sm', 'sizes' => $kpi,  'ranged' => false, 'description' => 'Leave requests still pending approval.'],
            'chart_payroll_dept'   => ['title' => 'Salary cost by department', 'category' => 'People','type' => 'chart', 'icon' => 'fas fa-chart-pie',           'tone' => 'cyan',   'perm' => 'payroll_view',   'size' => 'md', 'sizes' => $chart,'ranged' => false, 'description' => 'Monthly base salary of active staff per department.', 'charts' => ['donut', 'bar']],
            'chart_payroll_trend'  => ['title' => 'Payroll trend',         'category' => 'People',    'type' => 'chart', 'icon' => 'fas fa-chart-line',          'tone' => 'green',  'perm' => 'payroll_view',   'size' => 'lg', 'sizes' => $chart,'ranged' => true,  'description' => 'Gross pay, net pay and FNPF by month.', 'charts' => ['line', 'area', 'bar']],
            'chart_attendance'     => ['title' => 'Attendance',            'category' => 'People',    'type' => 'chart', 'icon' => 'fas fa-user-check',          'tone' => 'green',  'perm' => 'employees_view', 'size' => 'md', 'sizes' => $chart,'ranged' => true,  'description' => 'Present, late, half-day and absent records in the period.', 'charts' => ['donut', 'bar']],
            'list_pending_leaves'  => ['title' => 'Leave requests',        'category' => 'People',    'type' => 'list',  'icon' => 'fas fa-calendar-check',      'tone' => 'amber',  'perm' => 'employees_view', 'size' => 'lg', 'sizes' => $list, 'ranged' => false, 'description' => 'Pending leave requests to review.'],

            // Sales & stock
            'kpi_pipeline'         => ['title' => 'Open pipeline',         'category' => 'Sales & stock', 'type' => 'kpi', 'icon' => 'fas fa-funnel-dollar',     'tone' => 'indigo', 'perm' => 'sales_orders_view', 'size' => 'sm', 'sizes' => $kpi, 'ranged' => false, 'description' => 'Value of open CRM opportunities and the probability-weighted total.'],
            'kpi_low_stock'        => ['title' => 'Low stock items',       'category' => 'Sales & stock', 'type' => 'kpi', 'icon' => 'fas fa-boxes',             'tone' => 'red',    'perm' => 'products_view',     'size' => 'sm', 'sizes' => $kpi, 'ranged' => false, 'description' => 'Products at or below their reorder level.'],
            'chart_pipeline'       => ['title' => 'Pipeline by stage',     'category' => 'Sales & stock', 'type' => 'chart', 'icon' => 'fas fa-filter',           'tone' => 'indigo', 'perm' => 'sales_orders_view', 'size' => 'md', 'sizes' => $chart, 'ranged' => false, 'description' => 'Opportunity value in each CRM stage.', 'charts' => ['bar', 'donut']],
            'list_low_stock'       => ['title' => 'Reorder list',          'category' => 'Sales & stock', 'type' => 'list', 'icon' => 'fas fa-truck-loading',     'tone' => 'red',    'perm' => 'products_view',     'size' => 'md', 'sizes' => $list, 'ranged' => false, 'description' => 'Products that need restocking, lowest cover first.'],
            'kpi_open_tickets'     => ['title' => 'Open support tickets',  'category' => 'Sales & stock', 'type' => 'kpi', 'icon' => 'fas fa-life-ring',         'tone' => 'red',    'perm' => null,                'size' => 'sm', 'sizes' => $kpi, 'ranged' => false, 'description' => 'Tickets not yet resolved, with the urgent count.'],

            // General
            'quick_actions'        => ['title' => 'Quick actions',         'category' => 'General',   'type' => 'actions', 'icon' => 'fas fa-bolt',              'tone' => 'blue',   'perm' => null, 'size' => 'md', 'sizes' => ['sm', 'md', 'lg', 'full'], 'ranged' => false, 'description' => 'Shortcuts to the forms you use most.'],
            'note'                 => ['title' => 'Notes',                 'category' => 'General',   'type' => 'note',    'icon' => 'fas fa-sticky-note',       'tone' => 'amber',  'perm' => null, 'size' => 'md', 'sizes' => ['sm', 'md', 'lg', 'xl', 'full'], 'ranged' => false, 'description' => 'A private note pinned to this dashboard.'],
        ];
    }

    /** Catalogue entries this user may add, keyed by widget id. */
    public function catalog(): array
    {
        $out = [];
        foreach (self::definitions() as $id => $def) {
            if ($this->allowed($def)) {
                $def['id'] = $id;
                $out[$id] = $def;
            }
        }
        return $out;
    }

    private function allowed(array $def): bool
    {
        return $def['perm'] === null || (bool)($this->canAccess)($def['perm']);
    }

    /** The layout a new user starts with — the old executive dashboard, now as widgets. */
    public function defaultLayout(): array
    {
        $ids = [
            'kpi_revenue', 'kpi_receivables', 'kpi_payroll', 'kpi_pending_leaves',
            'chart_financial', 'chart_payroll_dept',
            'chart_cashflow', 'list_overdue',
            'chart_attendance', 'list_accounts', 'quick_actions',
        ];
        $defs = $this->catalog();
        $layout = [];
        foreach ($ids as $i => $id) {
            if (isset($defs[$id])) {
                $layout[] = ['uid' => 'd' . $i, 'widget' => $id, 'size' => $defs[$id]['size'], 'config' => new \stdClass()];
            }
        }
        if (count($layout) <= 1) {
            // Roles without data permissions still get something useful.
            $layout = [
                ['uid' => 'd0', 'widget' => 'quick_actions', 'size' => 'md', 'config' => new \stdClass()],
                ['uid' => 'd1', 'widget' => 'note', 'size' => 'md', 'config' => new \stdClass()],
            ];
        }
        return $layout;
    }

    /**
     * Validate a layout sent by the browser: drop unknown/forbidden widgets,
     * clamp sizes, and keep only the config keys widgets actually use.
     */
    public function sanitizeLayout($layout): array
    {
        if (!is_array($layout)) {
            return [];
        }
        $defs = $this->catalog();
        $seen = [];
        $clean = [];
        foreach ($layout as $item) {
            if (!is_array($item) || count($clean) >= self::MAX_WIDGETS) {
                continue;
            }
            $widget = (string)($item['widget'] ?? '');
            if (!isset($defs[$widget])) {
                continue;
            }
            $def = $defs[$widget];
            $uid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($item['uid'] ?? ''));
            if ($uid === '' || isset($seen[$uid])) {
                $uid = 'w' . bin2hex(random_bytes(4));
            }
            $seen[$uid] = true;

            $size = (string)($item['size'] ?? '');
            if (!in_array($size, $def['sizes'], true)) {
                $size = $def['size'];
            }

            $cfgIn = is_array($item['config'] ?? null) ? $item['config'] : [];
            $cfg = [];
            if (isset($cfgIn['title']) && is_string($cfgIn['title']) && trim($cfgIn['title']) !== '') {
                $cfg['title'] = mb_substr(trim($cfgIn['title']), 0, 60);
            }
            if (isset($cfgIn['chart'], $def['charts']) && in_array($cfgIn['chart'], $def['charts'], true)) {
                $cfg['chart'] = $cfgIn['chart'];
            }
            if ($def['type'] === 'note' && isset($cfgIn['text']) && is_string($cfgIn['text'])) {
                $cfg['text'] = mb_substr($cfgIn['text'], 0, 2000);
            }
            if (isset($cfgIn['color']) && is_string($cfgIn['color']) && in_array($cfgIn['color'], ['blue', 'green', 'amber', 'red', 'indigo', 'cyan'], true)) {
                $cfg['color'] = $cfgIn['color'];
            }

            $clean[] = ['uid' => $uid, 'widget' => $widget, 'size' => $size, 'config' => $cfg ?: new \stdClass()];
        }
        return $clean;
    }

    /* ==================================================================
     * Dashboards (per user, per tenant)
     * ================================================================== */

    public function ensureTable(): void
    {
        $this->conn?->query("CREATE TABLE IF NOT EXISTS `dashboards` (
            `id` int(30) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(30) NOT NULL DEFAULT 1,
            `user_id` int(30) NOT NULL,
            `name` varchar(80) NOT NULL,
            `layout` longtext NOT NULL,
            `date_range` varchar(20) NOT NULL DEFAULT 'this_month',
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `date_created` datetime NOT NULL DEFAULT current_timestamp(),
            `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_dash_owner` (`tenant_id`, `user_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** All dashboards for the user; creates the starter dashboard the first time. */
    public function listDashboards(): array
    {
        $rows = $this->rows(
            "SELECT id, name, layout, date_range, sort_order FROM dashboards WHERE tenant_id = ? AND user_id = ? ORDER BY sort_order, id",
            'ii', $this->tenantId, $this->userId
        );
        if (!$rows) {
            $this->createDashboard('Overview', $this->defaultLayout());
            $rows = $this->rows(
                "SELECT id, name, layout, date_range, sort_order FROM dashboards WHERE tenant_id = ? AND user_id = ? ORDER BY sort_order, id",
                'ii', $this->tenantId, $this->userId
            );
        }
        return array_map(fn (array $r): array => $this->hydrate($r), $rows);
    }

    private function hydrate(array $r): array
    {
        $layout = json_decode((string)$r['layout'], true);
        return [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'range' => isset(self::RANGES[$r['date_range']]) ? $r['date_range'] : 'this_month',
            // Re-sanitise on read so widgets whose permission was revoked disappear.
            'layout' => $this->sanitizeLayout(is_array($layout) ? $layout : []),
        ];
    }

    public function createDashboard(string $name, ?array $layout = null): ?array
    {
        $name = $this->cleanName($name);
        $count = $this->rows("SELECT COUNT(*) c FROM dashboards WHERE tenant_id = ? AND user_id = ?", 'ii', $this->tenantId, $this->userId);
        if ((int)($count[0]['c'] ?? 0) >= self::MAX_DASHBOARDS) {
            return null;
        }
        $json = json_encode($this->sanitizeLayout($layout ?? []));
        $order = (int)($count[0]['c'] ?? 0);
        $range = 'this_month';
        $stmt = $this->conn?->prepare("INSERT INTO dashboards (tenant_id, user_id, name, layout, date_range, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iisssi', $this->tenantId, $this->userId, $name, $json, $range, $order);
        if (!$stmt->execute()) {
            return null;
        }
        return $this->getDashboard((int)$stmt->insert_id);
    }

    public function getDashboard(int $id): ?array
    {
        $rows = $this->rows(
            "SELECT id, name, layout, date_range, sort_order FROM dashboards WHERE id = ? AND tenant_id = ? AND user_id = ?",
            'iii', $id, $this->tenantId, $this->userId
        );
        return $rows ? $this->hydrate($rows[0]) : null;
    }

    public function saveDashboard(int $id, array $changes): ?array
    {
        $current = $this->getDashboard($id);
        if (!$current) {
            return null;
        }
        $name = array_key_exists('name', $changes) ? $this->cleanName((string)$changes['name']) : $current['name'];
        $range = (string)($changes['range'] ?? $current['range']);
        if (!isset(self::RANGES[$range])) {
            $range = $current['range'];
        }
        $layout = array_key_exists('layout', $changes) ? $this->sanitizeLayout($changes['layout']) : $current['layout'];
        $json = json_encode($layout);

        $stmt = $this->conn?->prepare("UPDATE dashboards SET name = ?, layout = ?, date_range = ? WHERE id = ? AND tenant_id = ? AND user_id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('sssiii', $name, $json, $range, $id, $this->tenantId, $this->userId);
        $stmt->execute();
        return $this->getDashboard($id);
    }

    public function deleteDashboard(int $id): bool
    {
        $count = $this->rows("SELECT COUNT(*) c FROM dashboards WHERE tenant_id = ? AND user_id = ?", 'ii', $this->tenantId, $this->userId);
        if ((int)($count[0]['c'] ?? 0) <= 1) {
            return false; // always keep one dashboard
        }
        $stmt = $this->conn?->prepare("DELETE FROM dashboards WHERE id = ? AND tenant_id = ? AND user_id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $id, $this->tenantId, $this->userId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    /** @param int[] $ids in the desired order */
    public function reorderDashboards(array $ids): void
    {
        $stmt = $this->conn?->prepare("UPDATE dashboards SET sort_order = ? WHERE id = ? AND tenant_id = ? AND user_id = ?");
        if (!$stmt) {
            return;
        }
        foreach (array_values($ids) as $pos => $id) {
            $id = (int)$id;
            $stmt->bind_param('iiii', $pos, $id, $this->tenantId, $this->userId);
            $stmt->execute();
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', strip_tags($name)) ?? '');
        return $name === '' ? 'Untitled dashboard' : mb_substr($name, 0, 60);
    }

    /* ==================================================================
     * Date ranges
     * ================================================================== */

    /**
     * Resolve a preset into the current window and the equal-length window
     * before it (for "vs previous period" deltas). Dates are Y-m-d, inclusive.
     */
    public static function resolveRange(string $preset, ?\DateTimeImmutable $today = null): array
    {
        $t = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        switch ($preset) {
            case 'last_month':
                $from = $t->modify('first day of last month');
                $to = $t->modify('last day of last month');
                break;
            case 'last_30':
                $from = $t->modify('-29 days');
                $to = $t;
                break;
            case 'qtd':
                $q = intdiv((int)$t->format('n') - 1, 3);
                $from = $t->setDate((int)$t->format('Y'), $q * 3 + 1, 1);
                $to = $t;
                break;
            case 'ytd':
                $from = $t->setDate((int)$t->format('Y'), 1, 1);
                $to = $t;
                break;
            case 'last_year':
                $y = (int)$t->format('Y') - 1;
                $from = $t->setDate($y, 1, 1);
                $to = $t->setDate($y, 12, 31);
                break;
            case 'last_12m':
                $from = $t->modify('first day of this month')->modify('-11 months');
                $to = $t;
                break;
            case 'this_month':
            default:
                $preset = 'this_month';
                $from = $t->modify('first day of this month');
                $to = $t;
                break;
        }

        $days = (int)$from->diff($to)->days + 1;
        if (in_array($preset, ['this_month', 'last_month'], true)) {
            // Compare month with month: same day-span of the previous month.
            $prevFrom = $from->modify('first day of last month');
            $prevTo = min($prevFrom->modify('+' . ($days - 1) . ' days'), $prevFrom->modify('last day of this month'));
        } elseif (in_array($preset, ['ytd', 'last_year'], true)) {
            $prevFrom = $from->modify('-1 year');
            $prevTo = $to->modify('-1 year');
        } else {
            $prevTo = $from->modify('-1 day');
            $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
        }

        return [
            'preset' => $preset,
            'label' => self::RANGES[$preset],
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'prev_from' => $prevFrom->format('Y-m-d'),
            'prev_to' => $prevTo->format('Y-m-d'),
        ];
    }

    /** Month buckets (Y-m => label) covering a range, at least the last 6 months. */
    public static function monthBuckets(string $from, string $to): array
    {
        $start = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        $sixBack = $end->modify('-5 months');
        if ($start > $sixBack) {
            $start = $sixBack;
        }
        $out = [];
        for ($m = $start; $m <= $end; $m = $m->modify('+1 month')) {
            $out[$m->format('Y-m')] = $m->format("M 'y");
        }
        return $out;
    }

    /* ==================================================================
     * Widget data
     * ================================================================== */

    /**
     * Data for several widgets at once. Each widget is computed independently so
     * a missing optional module (e.g. CRM tables) only blanks that one widget.
     */
    public function widgetData(array $widgetIds, string $preset): array
    {
        $range = self::resolveRange($preset);
        $defs = $this->catalog();
        $out = [];
        foreach (array_unique($widgetIds) as $id) {
            $id = (string)$id;
            if (!isset($defs[$id])) {
                $out[$id] = ['error' => 'unavailable'];
                continue;
            }
            $method = 'w_' . $id;
            try {
                $out[$id] = method_exists($this, $method) ? $this->{$method}($range) : [];
            } catch (\Throwable $e) {
                $out[$id] = ['error' => 'failed'];
            }
        }
        return ['range' => $range, 'currency' => $this->currencySymbol(), 'widgets' => $out];
    }

    public function currencySymbol(): string
    {
        $r = $this->rows("SELECT symbol FROM currencies WHERE tenant_id = ? AND is_default = 1 AND status = 1 LIMIT 1", 'i', $this->tenantId);
        return $r ? (string)$r[0]['symbol'] : '$';
    }

    private function kpi(float $value, ?float $previous, array $extra = []): array
    {
        $delta = null;
        if ($previous !== null) {
            if ($previous == 0.0) {
                $delta = $value == 0.0 ? 0.0 : null;
            } else {
                $delta = round(($value - $previous) / abs($previous) * 100, 1);
            }
        }
        return array_merge(['value' => $value, 'previous' => $previous, 'delta' => $delta], $extra);
    }

    private function sumOne(string $sql, string $types, ...$params): float
    {
        $r = $this->rows($sql, $types, ...$params);
        return $r ? (float)array_values($r[0])[0] : 0.0;
    }

    private function monthlySeries(array $buckets, array $rows, string $key = 'total'): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[$r['ym']] = (float)$r[$key];
        }
        $out = [];
        foreach (array_keys($buckets) as $ym) {
            $out[] = round($map[$ym] ?? 0, 2);
        }
        return $out;
    }

    // ---- Finance -------------------------------------------------------

    private function w_kpi_revenue(array $r): array
    {
        $sql = "SELECT COALESCE(SUM(total),0) FROM invoice_list WHERE tenant_id = ? AND status NOT IN ('draft','cancelled') AND invoice_date BETWEEN ? AND ?";
        $cur = $this->sumOne($sql, 'iss', $this->tenantId, $r['from'], $r['to']);
        $prev = $this->sumOne($sql, 'iss', $this->tenantId, $r['prev_from'], $r['prev_to']);
        $count = $this->sumOne("SELECT COUNT(*) FROM invoice_list WHERE tenant_id = ? AND status NOT IN ('draft','cancelled') AND invoice_date BETWEEN ? AND ?", 'iss', $this->tenantId, $r['from'], $r['to']);
        return $this->kpi($cur, $prev, ['format' => 'money', 'sub' => (int)$count . ' invoice' . ($count == 1 ? '' : 's'), 'link' => './?page=invoices', 'spark' => $this->spark("SELECT DATE_FORMAT(invoice_date,'%Y-%m') ym, SUM(total) total FROM invoice_list WHERE tenant_id = ? AND status NOT IN ('draft','cancelled') AND invoice_date BETWEEN ? AND ? GROUP BY ym", $r)]);
    }

    private function w_kpi_receipts(array $r): array
    {
        $sql = "SELECT COALESCE(SUM(amount),0) FROM payment_list WHERE tenant_id = ? AND type = 'receipt' AND status = 'posted' AND payment_date BETWEEN ? AND ?";
        $cur = $this->sumOne($sql, 'iss', $this->tenantId, $r['from'], $r['to']);
        $prev = $this->sumOne($sql, 'iss', $this->tenantId, $r['prev_from'], $r['prev_to']);
        return $this->kpi($cur, $prev, ['format' => 'money', 'link' => './?page=payments', 'spark' => $this->spark("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total FROM payment_list WHERE tenant_id = ? AND type = 'receipt' AND status = 'posted' AND payment_date BETWEEN ? AND ? GROUP BY ym", $r)]);
    }

    private function w_kpi_receivables(array $r): array
    {
        $rows = $this->rows(
            "SELECT COALESCE(SUM(balance),0) total,
                    COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() THEN balance ELSE 0 END),0) overdue,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) overdue_count
             FROM invoice_list WHERE tenant_id = ? AND status IN ('unpaid','partially_paid') AND balance > 0",
            'i', $this->tenantId
        );
        $total = (float)($rows[0]['total'] ?? 0);
        $overdue = (float)($rows[0]['overdue'] ?? 0);
        $n = (int)($rows[0]['overdue_count'] ?? 0);
        return $this->kpi($total, null, [
            'format' => 'money',
            'sub' => $n > 0 ? $n . ' overdue' : 'Nothing overdue',
            'subTone' => $n > 0 ? 'down' : 'up',
            'progress' => $total > 0 ? round($overdue / $total * 100) : 0,
            'progressLabel' => 'overdue share',
            'link' => './?page=reports/ar_aging',
        ]);
    }

    private function w_kpi_journals(array $r): array
    {
        $sql = "SELECT COUNT(*) FROM journal_entries WHERE tenant_id = ? AND journal_date BETWEEN ? AND ?";
        $cur = $this->sumOne($sql, 'iss', $this->tenantId, $r['from'], $r['to']);
        $prev = $this->sumOne($sql, 'iss', $this->tenantId, $r['prev_from'], $r['prev_to']);
        return $this->kpi($cur, $prev, ['format' => 'number', 'link' => './?page=journals']);
    }

    private function w_chart_financial(array $r): array
    {
        $b = self::monthBuckets($r['from'], $r['to']);
        $first = array_key_first($b) . '-01';
        $rows = $this->rows(
            "SELECT DATE_FORMAT(j.journal_date,'%Y-%m') ym, g.type, SUM(ji.amount) total
             FROM journal_items ji
             INNER JOIN journal_entries j ON ji.journal_id = j.id
             INNER JOIN group_list g ON ji.group_id = g.id
             WHERE j.tenant_id = ? AND j.journal_date BETWEEN ? AND ?
             GROUP BY ym, g.type",
            'iss', $this->tenantId, $first, $r['to']
        );
        $deb = array_filter($rows, fn ($x) => (int)$x['type'] === 1);
        $cre = array_filter($rows, fn ($x) => (int)$x['type'] === 2);
        return [
            'categories' => array_values($b),
            'series' => [
                ['name' => 'Debits', 'data' => $this->monthlySeries($b, $deb)],
                ['name' => 'Credits', 'data' => $this->monthlySeries($b, $cre)],
            ],
            'format' => 'money',
            'link' => './?page=reports/general_ledger',
        ];
    }

    private function w_chart_cashflow(array $r): array
    {
        $b = self::monthBuckets($r['from'], $r['to']);
        $first = array_key_first($b) . '-01';
        $inv = $this->rows("SELECT DATE_FORMAT(invoice_date,'%Y-%m') ym, SUM(total) total FROM invoice_list WHERE tenant_id = ? AND status NOT IN ('draft','cancelled') AND invoice_date BETWEEN ? AND ? GROUP BY ym", 'iss', $this->tenantId, $first, $r['to']);
        $rec = $this->rows("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total FROM payment_list WHERE tenant_id = ? AND type = 'receipt' AND status = 'posted' AND payment_date BETWEEN ? AND ? GROUP BY ym", 'iss', $this->tenantId, $first, $r['to']);
        return [
            'categories' => array_values($b),
            'series' => [
                ['name' => 'Invoiced', 'data' => $this->monthlySeries($b, $inv)],
                ['name' => 'Received', 'data' => $this->monthlySeries($b, $rec)],
            ],
            'format' => 'money',
            'link' => './?page=invoices',
        ];
    }

    private function w_chart_invoice_status(array $r): array
    {
        $rows = $this->rows("SELECT status, SUM(total) total, COUNT(*) n FROM invoice_list WHERE tenant_id = ? AND invoice_date BETWEEN ? AND ? GROUP BY status", 'iss', $this->tenantId, $r['from'], $r['to']);
        $order = ['paid' => 'Paid', 'partially_paid' => 'Part paid', 'unpaid' => 'Unpaid', 'draft' => 'Draft', 'cancelled' => 'Cancelled'];
        $map = [];
        foreach ($rows as $row) {
            $map[$row['status']] = (float)$row['total'];
        }
        $labels = [];
        $values = [];
        foreach ($order as $k => $label) {
            if (!empty($map[$k])) {
                $labels[] = $label;
                $values[] = round($map[$k], 2);
            }
        }
        return ['labels' => $labels, 'values' => $values, 'format' => 'money', 'link' => './?page=invoices'];
    }

    private function w_list_overdue(array $r): array
    {
        $rows = $this->rows(
            "SELECT i.id, i.invoice_no, p.name party, i.due_date, i.balance, DATEDIFF(CURDATE(), i.due_date) days
             FROM invoice_list i INNER JOIN party_list p ON p.id = i.party_id
             WHERE i.tenant_id = ? AND i.status IN ('unpaid','partially_paid') AND i.balance > 0 AND i.due_date < CURDATE()
             ORDER BY i.due_date ASC LIMIT 8",
            'i', $this->tenantId
        );
        return [
            'columns' => [['key' => 'invoice_no', 'label' => 'Invoice'], ['key' => 'party', 'label' => 'Customer'], ['key' => 'days', 'label' => 'Overdue', 'format' => 'days'], ['key' => 'balance', 'label' => 'Balance', 'format' => 'money', 'align' => 'right']],
            'rows' => array_map(fn ($x) => $x + ['href' => './?page=invoices/view&id=' . (int)$x['id']], $rows),
            'empty' => 'No overdue invoices — nice.',
            'link' => './?page=reports/ar_aging',
        ];
    }

    private function w_list_recent_invoices(array $r): array
    {
        $rows = $this->rows(
            "SELECT i.id, i.invoice_no, p.name party, i.invoice_date, i.total, i.status
             FROM invoice_list i INNER JOIN party_list p ON p.id = i.party_id
             WHERE i.tenant_id = ? ORDER BY i.invoice_date DESC, i.id DESC LIMIT 8",
            'i', $this->tenantId
        );
        return [
            'columns' => [['key' => 'invoice_no', 'label' => 'Invoice'], ['key' => 'party', 'label' => 'Customer'], ['key' => 'invoice_date', 'label' => 'Date', 'format' => 'date'], ['key' => 'status', 'label' => 'Status', 'format' => 'status'], ['key' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right']],
            'rows' => array_map(fn ($x) => $x + ['href' => './?page=invoices/view&id=' . (int)$x['id']], $rows),
            'empty' => 'No invoices yet.',
            'link' => './?page=invoices',
        ];
    }

    private function w_list_top_customers(array $r): array
    {
        $rows = $this->rows(
            "SELECT p.id, p.name party, COUNT(i.id) n, SUM(i.total) total
             FROM invoice_list i INNER JOIN party_list p ON p.id = i.party_id
             WHERE i.tenant_id = ? AND i.status NOT IN ('draft','cancelled') AND i.invoice_date BETWEEN ? AND ?
             GROUP BY p.id, p.name ORDER BY total DESC LIMIT 6",
            'iss', $this->tenantId, $r['from'], $r['to']
        );
        $max = 0.0;
        foreach ($rows as $x) {
            $max = max($max, (float)$x['total']);
        }
        foreach ($rows as &$x) {
            $x['bar'] = $max > 0 ? round((float)$x['total'] / $max * 100) : 0;
        }
        unset($x);
        return [
            'columns' => [['key' => 'party', 'label' => 'Customer', 'bar' => 'bar'], ['key' => 'n', 'label' => 'Invoices', 'align' => 'right'], ['key' => 'total', 'label' => 'Invoiced', 'format' => 'money', 'align' => 'right']],
            'rows' => $rows,
            'empty' => 'No invoiced sales in this period.',
            'link' => './?page=parties',
        ];
    }

    private function w_list_journals(array $r): array
    {
        $rows = $this->rows(
            "SELECT j.id, j.code, j.journal_date, j.description,
                    (SELECT COALESCE(SUM(ji.amount),0) FROM journal_items ji INNER JOIN group_list g ON g.id = ji.group_id WHERE ji.journal_id = j.id AND g.type = 1) amount
             FROM journal_entries j WHERE j.tenant_id = ? ORDER BY j.journal_date DESC, j.id DESC LIMIT 8",
            'i', $this->tenantId
        );
        return [
            'columns' => [['key' => 'code', 'label' => 'Ref'], ['key' => 'journal_date', 'label' => 'Date', 'format' => 'date'], ['key' => 'description', 'label' => 'Narration', 'truncate' => 48], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'align' => 'right']],
            'rows' => $rows,
            'empty' => 'No journals posted yet.',
            'link' => './?page=journals',
        ];
    }

    private function w_list_accounts(array $r): array
    {
        $rows = $this->rows(
            "SELECT a.code, a.name, g.name group_name, g.type
             FROM account_list a INNER JOIN group_list g ON a.group_id = g.id
             WHERE a.tenant_id = ? AND a.delete_flag = 0 AND a.status = 1
             ORDER BY a.code LIMIT 8",
            'i', $this->tenantId
        );
        foreach ($rows as &$x) {
            $x['type'] = (int)$x['type'] === 1 ? 'Debit' : 'Credit';
        }
        unset($x);
        $counts = $this->rows(
            "SELECT (SELECT COUNT(*) FROM group_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1) g,
                    (SELECT COUNT(*) FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1) a",
            'ii', $this->tenantId, $this->tenantId
        );
        return [
            'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Account'], ['key' => 'group_name', 'label' => 'Group'], ['key' => 'type', 'label' => 'Type', 'format' => 'status', 'align' => 'right']],
            'rows' => $rows,
            'meta' => ((int)($counts[0]['g'] ?? 0)) . ' groups · ' . ((int)($counts[0]['a'] ?? 0)) . ' accounts',
            'empty' => 'Set up your chart of accounts to start posting.',
            'link' => './?page=accounts',
        ];
    }

    // ---- People --------------------------------------------------------

    private function w_kpi_employees(array $r): array
    {
        $active = $this->sumOne("SELECT COUNT(*) FROM employee_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1", 'i', $this->tenantId);
        $joined = $this->sumOne("SELECT COUNT(*) FROM employee_list WHERE tenant_id = ? AND delete_flag = 0 AND date_of_joining BETWEEN ? AND ?", 'iss', $this->tenantId, $r['from'], $r['to']);
        return $this->kpi($active, null, ['format' => 'number', 'sub' => (int)$joined . ' joined in period', 'subTone' => $joined > 0 ? 'up' : 'neutral', 'link' => './?page=employees']);
    }

    private function payDate(): string
    {
        return "COALESCE(pay_date, period_end, DATE(date_created))";
    }

    private function w_kpi_payroll(array $r): array
    {
        $sql = "SELECT COALESCE(SUM(net_salary),0) FROM payroll_list WHERE tenant_id = ? AND " . $this->payDate() . " BETWEEN ? AND ?";
        $cur = $this->sumOne($sql, 'iss', $this->tenantId, $r['from'], $r['to']);
        $prev = $this->sumOne($sql, 'iss', $this->tenantId, $r['prev_from'], $r['prev_to']);
        return $this->kpi($cur, $prev, ['format' => 'money', 'invert' => true, 'link' => './?page=payroll', 'spark' => $this->spark("SELECT DATE_FORMAT(" . $this->payDate() . ",'%Y-%m') ym, SUM(net_salary) total FROM payroll_list WHERE tenant_id = ? AND " . $this->payDate() . " BETWEEN ? AND ? GROUP BY ym", $r)]);
    }

    private function w_kpi_pending_leaves(array $r): array
    {
        $n = $this->sumOne("SELECT COUNT(*) FROM leave_list WHERE tenant_id = ? AND status = 'pending'", 'i', $this->tenantId);
        $starting = $this->sumOne("SELECT COUNT(*) FROM leave_list WHERE tenant_id = ? AND status = 'pending' AND start_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)", 'i', $this->tenantId);
        return $this->kpi($n, null, [
            'format' => 'number',
            'sub' => $n > 0 ? ((int)$starting . ' start within 7 days') : 'All caught up',
            'subTone' => $starting > 0 ? 'down' : ($n > 0 ? 'neutral' : 'up'),
            'link' => './?page=leaves',
        ]);
    }

    private function w_chart_payroll_dept(array $r): array
    {
        $rows = $this->rows("SELECT COALESCE(NULLIF(TRIM(department),''),'Unassigned') dept, SUM(salary) total FROM employee_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 GROUP BY dept ORDER BY total DESC", 'i', $this->tenantId);
        $labels = [];
        $values = [];
        foreach ($rows as $i => $row) {
            if ($i >= 7) {
                $values[6] += (float)$row['total'];
                $labels[6] = 'Other';
                continue;
            }
            $labels[] = $row['dept'];
            $values[] = round((float)$row['total'], 2);
        }
        return ['labels' => array_values($labels), 'values' => array_values($values), 'format' => 'money', 'totalLabel' => 'Monthly total', 'link' => './?page=employees'];
    }

    private function w_chart_payroll_trend(array $r): array
    {
        $b = self::monthBuckets($r['from'], $r['to']);
        $first = array_key_first($b) . '-01';
        $rows = $this->rows(
            "SELECT DATE_FORMAT(" . $this->payDate() . ",'%Y-%m') ym, SUM(gross_salary) gross, SUM(net_salary) net, SUM(employee_fnpf + employer_fnpf) fnpf
             FROM payroll_list WHERE tenant_id = ? AND " . $this->payDate() . " BETWEEN ? AND ? GROUP BY ym",
            'iss', $this->tenantId, $first, $r['to']
        );
        return [
            'categories' => array_values($b),
            'series' => [
                ['name' => 'Gross', 'data' => $this->monthlySeries($b, $rows, 'gross')],
                ['name' => 'Net', 'data' => $this->monthlySeries($b, $rows, 'net')],
                ['name' => 'FNPF', 'data' => $this->monthlySeries($b, $rows, 'fnpf')],
            ],
            'format' => 'money',
            'link' => './?page=payroll',
        ];
    }

    private function w_chart_attendance(array $r): array
    {
        $rows = $this->rows("SELECT status, COUNT(*) n FROM attendance_list WHERE tenant_id = ? AND attendance_date BETWEEN ? AND ? GROUP BY status", 'iss', $this->tenantId, $r['from'], $r['to']);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['status']] = (int)$row['n'];
        }
        $labels = ['Present', 'Late', 'Half day', 'Absent'];
        $values = [$map['present'] ?? 0, $map['late'] ?? 0, $map['half_day'] ?? 0, $map['absent'] ?? 0];
        if (array_sum($values) === 0) {
            $labels = $values = [];
        }
        return ['labels' => $labels, 'values' => $values, 'format' => 'number', 'totalLabel' => 'Records', 'palette' => 'status', 'link' => './?page=attendance'];
    }

    private function w_list_pending_leaves(array $r): array
    {
        $rows = $this->rows(
            "SELECT l.id, CONCAT(e.firstname,' ',e.lastname) employee, l.leave_type, l.start_date, l.end_date,
                    DATEDIFF(l.end_date, l.start_date) + 1 days
             FROM leave_list l INNER JOIN employee_list e ON e.id = l.employee_id
             WHERE l.tenant_id = ? AND l.status = 'pending' ORDER BY l.start_date ASC LIMIT 8",
            'i', $this->tenantId
        );
        foreach ($rows as &$x) {
            $x['leave_type'] = ucfirst((string)$x['leave_type']);
        }
        unset($x);
        return [
            'columns' => [['key' => 'employee', 'label' => 'Employee'], ['key' => 'leave_type', 'label' => 'Type'], ['key' => 'start_date', 'label' => 'From', 'format' => 'date'], ['key' => 'days', 'label' => 'Days', 'align' => 'right']],
            'rows' => array_map(fn ($x) => $x + ['href' => './?page=leaves'], $rows),
            'empty' => 'No leave waiting for approval.',
            'link' => './?page=leaves',
        ];
    }

    // ---- Sales & stock -------------------------------------------------

    private function w_kpi_pipeline(array $r): array
    {
        $rows = $this->rows("SELECT COALESCE(SUM(amount),0) total, COALESCE(SUM(amount * probability / 100),0) weighted, COUNT(*) n FROM crm_opportunities WHERE tenant_id = ? AND stage NOT IN ('won','lost')", 'i', $this->tenantId);
        $total = (float)($rows[0]['total'] ?? 0);
        return $this->kpi($total, null, [
            'format' => 'money',
            'sub' => (int)($rows[0]['n'] ?? 0) . ' open · weighted ' . number_format((float)($rows[0]['weighted'] ?? 0), 0),
            'link' => './?page=crm',
        ]);
    }

    private function w_kpi_low_stock(array $r): array
    {
        $n = $this->sumOne("SELECT COUNT(*) FROM product_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND stock_quantity <= reorder_level", 'i', $this->tenantId);
        $out = $this->sumOne("SELECT COUNT(*) FROM product_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND stock_quantity <= 0", 'i', $this->tenantId);
        return $this->kpi($n, null, ['format' => 'number', 'sub' => $out > 0 ? (int)$out . ' out of stock' : 'None out of stock', 'subTone' => $out > 0 ? 'down' : 'up', 'link' => './?page=products']);
    }

    private function w_chart_pipeline(array $r): array
    {
        $rows = $this->rows("SELECT stage, SUM(amount) total FROM crm_opportunities WHERE tenant_id = ? GROUP BY stage", 'i', $this->tenantId);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['stage']] = (float)$row['total'];
        }
        $stages = ['qualification' => 'Qualification', 'proposal' => 'Proposal', 'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost'];
        $values = [];
        foreach ($stages as $k => $_) {
            $values[] = round($map[$k] ?? 0, 2);
        }
        if (array_sum($values) == 0) {
            return ['labels' => [], 'values' => [], 'format' => 'money', 'link' => './?page=crm'];
        }
        return ['labels' => array_values($stages), 'values' => $values, 'format' => 'money', 'link' => './?page=crm'];
    }

    private function w_list_low_stock(array $r): array
    {
        $rows = $this->rows(
            "SELECT id, product_code, name, stock_quantity, reorder_level, unit
             FROM product_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND stock_quantity <= reorder_level
             ORDER BY (stock_quantity / NULLIF(reorder_level,0)) ASC, stock_quantity ASC LIMIT 8",
            'i', $this->tenantId
        );
        foreach ($rows as &$x) {
            $x['stock'] = rtrim(rtrim(number_format((float)$x['stock_quantity'], 2, '.', ''), '0'), '.') . ' ' . $x['unit'];
            $lvl = (float)$x['reorder_level'];
            $x['bar'] = $lvl > 0 ? max(0, min(100, round((float)$x['stock_quantity'] / $lvl * 100))) : 0;
        }
        unset($x);
        return [
            'columns' => [['key' => 'name', 'label' => 'Product', 'bar' => 'bar', 'barTone' => 'red'], ['key' => 'stock', 'label' => 'In stock', 'align' => 'right'], ['key' => 'reorder_level', 'label' => 'Reorder at', 'format' => 'number', 'align' => 'right']],
            'rows' => $rows,
            'empty' => 'Every product is above its reorder level.',
            'link' => './?page=products',
        ];
    }

    private function w_kpi_open_tickets(array $r): array
    {
        $rows = $this->rows("SELECT COUNT(*) n, SUM(CASE WHEN priority IN ('high','urgent') THEN 1 ELSE 0 END) hot FROM tickets WHERE tenant_id = ? AND status NOT IN ('resolved','closed')", 'i', $this->tenantId);
        $n = (float)($rows[0]['n'] ?? 0);
        $hot = (int)($rows[0]['hot'] ?? 0);
        return $this->kpi($n, null, ['format' => 'number', 'sub' => $hot > 0 ? $hot . ' high priority' : 'No high-priority tickets', 'subTone' => $hot > 0 ? 'down' : 'up', 'link' => './?page=tickets']);
    }

    // ---- General -------------------------------------------------------

    private function w_quick_actions(array $r): array
    {
        $all = [
            ['label' => 'New invoice', 'icon' => 'fas fa-file-invoice', 'href' => './?page=invoices/manage_invoice', 'perm' => 'invoices_manage', 'tone' => 'blue'],
            ['label' => 'Record receipt', 'icon' => 'fas fa-hand-holding-usd', 'href' => './?page=payments/manage_payment', 'perm' => 'payments_manage', 'tone' => 'green'],
            ['label' => 'Manual journal', 'icon' => 'fas fa-book', 'href' => './?page=journals/manage_journal', 'perm' => 'journals_manage', 'tone' => 'indigo'],
            ['label' => 'New quote', 'icon' => 'fas fa-file-signature', 'href' => './?page=sales_orders/manage_order', 'perm' => 'sales_orders_manage', 'tone' => 'amber'],
            ['label' => 'Run payroll', 'icon' => 'fas fa-money-check-alt', 'href' => './?page=payroll/payrun_engine', 'perm' => 'payroll_manage', 'tone' => 'green'],
            ['label' => 'Employees', 'icon' => 'fas fa-users', 'href' => './?page=employees', 'perm' => 'employees_view', 'tone' => 'cyan'],
            ['label' => 'Reports', 'icon' => 'fas fa-chart-pie', 'href' => './?page=reports', 'perm' => 'reports_view', 'tone' => 'red'],
            ['label' => 'Contacts', 'icon' => 'fas fa-address-book', 'href' => './?page=parties', 'perm' => 'invoices_view', 'tone' => 'blue'],
        ];
        $actions = [];
        foreach ($all as $a) {
            if (($this->canAccess)($a['perm'])) {
                unset($a['perm']);
                $actions[] = $a;
            }
        }
        return ['actions' => $actions];
    }

    private function w_note(array $r): array
    {
        return [];
    }

    /* ==================================================================
     * Query helpers
     * ================================================================== */

    /** Sparkline over the range's month buckets. */
    private function spark(string $sql, array $r): array
    {
        $b = self::monthBuckets($r['from'], $r['to']);
        $first = array_key_first($b) . '-01';
        return $this->monthlySeries($b, $this->rows($sql, 'iss', $this->tenantId, $first, $r['to']));
    }

    /** Run a prepared SELECT and return all rows; [] on any failure (e.g. module tables not installed). */
    private function rows(string $sql, string $types = '', ...$params): array
    {
        if (!$this->conn) {
            return [];
        }
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            return [];
        }
        $res = $stmt->get_result();
        if (!$res) {
            return [];
        }
        return $res->fetch_all(MYSQLI_ASSOC);
    }
}
