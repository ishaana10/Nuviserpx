<?php
/**
 * Tests for App\Services\DashboardService — catalogue permissions, layout
 * sanitising, date ranges and widget data shapes (no database required).
 *
 * Run: php tests/test_dashboard_service.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\DashboardService;

$passed = 0;
function check(bool $cond, string $label): void
{
    global $passed;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $passed++;
    echo "  ✓ {$label}\n";
}

echo "=== Catalogue & permissions ===\n";
$admin = new DashboardService(null, 1, 1, fn () => true);
$payrollOnly = new DashboardService(null, 1, 2, fn (string $p) => in_array($p, ['payroll_view', 'employees_view'], true));

$all = $admin->catalog();
check(count($all) === count(DashboardService::definitions()), 'admin sees every widget');
check(!isset($payrollOnly->catalog()['kpi_revenue']), 'invoice widgets hidden without invoices_view');
check(isset($payrollOnly->catalog()['kpi_payroll']), 'payroll widgets visible with payroll_view');
check(isset($payrollOnly->catalog()['note']), 'permission-free widgets always visible');
foreach (DashboardService::definitions() as $id => $d) {
    check(in_array($d['size'], $d['sizes'], true) && !array_diff($d['sizes'], DashboardService::SIZES), "{$id}: default size is allowed");
    check(method_exists(DashboardService::class, 'w_' . $id), "{$id}: has a data provider");
}

echo "\n=== Default layout ===\n";
$def = $admin->defaultLayout();
check(count($def) >= 8, 'standard dashboard has the executive widgets');
check($def[0]['widget'] === 'kpi_revenue', 'starts with the revenue KPI');
$limited = (new DashboardService(null, 1, 3, fn () => false))->defaultLayout();
check(count($limited) === 2 && $limited[0]['widget'] === 'quick_actions', 'role with no permissions still gets a usable dashboard');

echo "\n=== Layout sanitising ===\n";
$dirty = [
    ['uid' => 'a1', 'widget' => 'kpi_revenue', 'size' => 'full', 'config' => ['title' => '  Sales  ', 'evil' => '<script>']],
    ['uid' => 'a1', 'widget' => 'chart_financial', 'size' => 'lg', 'config' => ['chart' => 'pie', 'color' => 'green']],
    ['uid' => 'x<>y', 'widget' => 'does_not_exist'],
    ['uid' => 'n1', 'widget' => 'note', 'size' => 'md', 'config' => ['text' => str_repeat('a', 5000)]],
    'not-an-array',
];
$clean = $admin->sanitizeLayout($dirty);
check(count($clean) === 3, 'unknown widgets and junk entries dropped');
check($clean[0]['size'] === 'sm', 'disallowed size falls back to the widget default');
check(((array)$clean[0]['config']) === ['title' => 'Sales'], 'titles trimmed, unknown config keys removed');
check($clean[1]['uid'] !== 'a1', 'duplicate uid replaced');
check(!isset(((array)$clean[1]['config'])['chart']) && ((array)$clean[1]['config'])['color'] === 'green', 'invalid chart type dropped, valid colour kept');
check(mb_strlen(((array)$clean[2]['config'])['text']) === 2000, 'note text capped at 2000 chars');
check($payrollOnly->sanitizeLayout([['uid' => 'z', 'widget' => 'kpi_revenue', 'size' => 'sm']]) === [], 'widgets the user may not see are stripped');
$many = array_fill(0, 60, ['widget' => 'note', 'size' => 'md']);
check(count($admin->sanitizeLayout($many)) === 40, 'layout capped at 40 widgets');
check($admin->sanitizeLayout('nope') === [], 'non-array layout becomes empty');
check(json_encode($admin->sanitizeLayout([['uid' => 'q', 'widget' => 'note']]))  === '[{"uid":"q","widget":"note","size":"md","config":{}}]', 'empty config serialises as an object');

echo "\n=== Date ranges ===\n";
$today = new DateTimeImmutable('2026-10-26');
$r = DashboardService::resolveRange('this_month', $today);
check($r['from'] === '2026-10-01' && $r['to'] === '2026-10-26', 'this month = 1st to today');
check($r['prev_from'] === '2026-09-01' && $r['prev_to'] === '2026-09-26', 'compared with the same days last month');
$r = DashboardService::resolveRange('this_month', new DateTimeImmutable('2026-03-31'));
check($r['prev_from'] === '2026-02-01' && $r['prev_to'] === '2026-02-28', 'previous month clamps to its last day');
$r = DashboardService::resolveRange('qtd', $today);
check($r['from'] === '2026-10-01', 'Q4 starts in October');
$r = DashboardService::resolveRange('ytd', $today);
check($r['from'] === '2026-01-01' && $r['prev_from'] === '2025-01-01' && $r['prev_to'] === '2025-10-26', 'YTD compares with the same span last year');
$r = DashboardService::resolveRange('last_30', $today);
check($r['from'] === '2026-09-27' && $r['prev_to'] === '2026-09-26' && $r['prev_from'] === '2026-08-28', 'last 30 days and the 30 before');
$r = DashboardService::resolveRange('last_year', $today);
check($r['from'] === '2025-01-01' && $r['to'] === '2025-12-31', 'last year is the full calendar year');
$r = DashboardService::resolveRange('last_month', $today);
check($r['from'] === '2026-09-01' && $r['to'] === '2026-09-30' && $r['prev_from'] === '2026-08-01', 'last month');
$r = DashboardService::resolveRange('bogus', $today);
check($r['preset'] === 'this_month', 'unknown preset falls back to this month');

$b = DashboardService::monthBuckets('2026-10-01', '2026-10-26');
check(count($b) === 6 && array_key_first($b) === '2026-05' && array_key_last($b) === '2026-10', 'short ranges still chart six months');
$b = DashboardService::monthBuckets('2025-11-01', '2026-10-26');
check(count($b) === 12, 'last 12 months gives twelve buckets');

echo "\n=== Widget data without a database ===\n";
$out = $admin->widgetData(array_keys(DashboardService::definitions()), 'ytd');
check($out['currency'] === '$' && $out['range']['preset'] === 'ytd', 'response carries currency and range');
foreach ($out['widgets'] as $id => $data) {
    check(!isset($data['error']), "{$id}: renders an empty state instead of failing");
}
check($out['widgets']['kpi_revenue']['value'] === 0.0 && $out['widgets']['kpi_revenue']['delta'] === 0.0, 'zero vs zero is a 0% change');
check(count($out['widgets']['chart_financial']['series']) === 2, 'financial chart has debit and credit series');
check($out['widgets']['list_overdue']['rows'] === [], 'lists return empty rows');
check(count($out['widgets']['quick_actions']['actions']) === 8, 'admin gets every shortcut');
$restricted = $payrollOnly->widgetData(['kpi_revenue', 'kpi_payroll'], 'this_month')['widgets'];
check(($restricted['kpi_revenue']['error'] ?? '') === 'unavailable', 'forbidden widget data is refused');
check(!isset($restricted['kpi_payroll']['error']), 'permitted widget data is returned');

echo "\nAll {$passed} dashboard checks passed.\n";
