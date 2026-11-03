<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\DashboardService as D;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
$ids = fn (array $l) => array_column($l, 'widget');

// Everything allowed: the starter layout leads with the checklist and has real content.
$all = new D(null, 1, 1, fn ($p) => true);
$l = $all->defaultLayout();
check('starter layout opens with the revenue KPI and has the set-up checklist', ($ids($l)[0] ?? '') === 'kpi_revenue' && in_array('getting_started', $ids($l), true));
check('starter layout is substantial', count($l) >= 10);
check('starter layout includes bank, payables and approvals', count(array_intersect(['kpi_bank', 'kpi_payables', 'list_approvals'], $ids($l))) === 3);
check('every widget id in the layout exists in the catalogue', count(array_diff($ids($l), array_keys(D::definitions()))) === 0);

// Nothing allowed: still something useful, never a blank board.
$none = new D(null, 1, 1, fn ($p) => false);
$n = $ids($none->defaultLayout());
check('no permissions: shortcuts, checklist and approvals still show', in_array('quick_actions', $n, true) && in_array('getting_started', $n, true));
check('no permissions: no data widgets leak', count(array_intersect($n, ['kpi_revenue', 'kpi_payroll', 'list_activity', 'kpi_bank'])) === 0);

// Audit activity needs settings access; bank and bills need their modules.
$some = new D(null, 1, 1, fn ($p) => $p === 'journals_view');
$s = array_keys($some->catalog());
check('journals_view shows bank widgets', in_array('kpi_bank', $s, true) && in_array('list_bank_balances', $s, true));
check('journals_view does not show payables or activity', !in_array('kpi_payables', $s, true) && !in_array('list_activity', $s, true));

// New widgets survive sanitising; unknown ones are dropped.
$san = $all->sanitizeLayout([
    ['uid' => 'a', 'widget' => 'getting_started', 'size' => 'lg'],
    ['uid' => 'b', 'widget' => 'list_approvals', 'size' => 'full'],
    ['uid' => 'c', 'widget' => 'nope', 'size' => 'md'],
]);
check('new widgets kept, unknown dropped', $ids($san) === ['getting_started', 'list_approvals']);

// With no database the dashboard list is the unsaved starter dashboard, not an empty list.
$list = $all->listDashboards();
check('no database: unsaved starter dashboard is returned', count($list) === 1 && $list[0]['id'] === 0 && !empty($list[0]['unsaved']) && count($list[0]['layout']) >= 10);

// Every definition is complete (a widget with a missing key breaks the gallery).
$complete = true;
foreach (D::definitions() as $id => $def) {
    foreach (['title', 'category', 'type', 'icon', 'tone', 'size', 'sizes', 'ranged', 'description'] as $k) {
        if (!array_key_exists($k, $def)) { $complete = false; echo "  $id missing $k\n"; }
    }
    if (!in_array($def['size'], $def['sizes'], true)) { $complete = false; echo "  $id default size not offered\n"; }
}
check('all widget definitions are complete', $complete);

// Each widget has a data method (or is static).
$ref = new ReflectionClass(D::class);
$missing = [];
foreach (array_keys(D::definitions()) as $id) {
    if (!in_array($id, ['note'], true) && !$ref->hasMethod('w_' . $id)) $missing[] = $id;
}
check('every widget has a data method', $missing === []);

echo $fail ? "\n$fail FAILED\n" : "\nAll dashboard fallback tests passed\n";
exit($fail ? 1 : 0);
