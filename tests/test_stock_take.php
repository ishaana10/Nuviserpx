<?php
// Stock take: pure maths, input parsing and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/StockTakeService.php";
use App\Services\StockTakeService as S;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('variance over', S::variance(12, 10) === 2.0);
check('variance short', S::variance(7.5, 10) === -2.5);
check('variance rounds float noise', S::variance(0.3, 0.1 + 0.2) === 0.0);
check('uncounted line is skipped', S::lineAction(null, 5) === 'skip');
check('matching line needs nothing', S::lineAction(5, 5) === 'none');
check('zero counted against stock is a removal', S::lineAction(0, 5) === 'out');
check('more than system is an addition', S::lineAction(6, 5) === 'in');

check('blank cell is not counted', S::parseCount('') === null && S::parseCount('  ') === null);
check('zero is a real count', S::parseCount('0') === 0.0);
check('thousands separator accepted', S::parseCount('1,250.5') === 1250.5);
check('negative rejected', S::parseCount('-3') < 0);
check('text rejected', S::parseCount('abc') < 0);

$s = S::summary([
    ['system_qty' => 10, 'counted_qty' => '12', 'unit_cost' => 2.5],
    ['system_qty' => 5, 'counted_qty' => '4', 'unit_cost' => 10],
    ['system_qty' => 3, 'counted_qty' => '3', 'unit_cost' => 1],
    ['system_qty' => 8, 'counted_qty' => null, 'unit_cost' => 1],
]);
check('summary counts', $s['lines'] === 4 && $s['counted'] === 3 && $s['uncounted'] === 1 && $s['over'] === 1 && $s['short'] === 1);
check('summary value of difference', $s['value'] === -5.0);

$svc = file_get_contents("$root/src/Services/StockTakeService.php");
check('posting goes through the stock adjustment service', strpos($svc, 'saveStockAdjustment(') !== false);
check('posting is locked per count', strpos($svc, 'GET_LOCK') !== false && strpos($svc, 'RELEASE_LOCK') !== false);
check('posted lines are never repeated', strpos($svc, "line_status'] === 'posted'") !== false);
check('count edits cannot touch posted lines', strpos($svc, "line_status <> 'posted'") !== false);
check('counting is audited', substr_count($svc, 'log_audit') >= 3);
$mig = file_get_contents("$root/database/migrations/030_stock_counts.sql");
check('one line per product per count', strpos($mig, 'uniq_count_product') !== false && strpos($mig, 'uniq_count_no') !== false);
$perm = file_get_contents("$root/src/Support/Permissions.php");
foreach (['create_stock_count', 'save_stock_counts', 'post_stock_count', 'cancel_stock_count'] as $a) {
    check("$a needs stock save", preg_match("/'$a'\s*=>\s*\['stock', 'save'\]/", $perm) === 1);
}
check('page is mapped to stock view', preg_match("/'stock_counts'\s*=>\s*'stock_view'/", $perm) === 1);
$m = file_get_contents("$root/classes/Master.php");
foreach (['create_stock_count', 'save_stock_counts', 'post_stock_count', 'cancel_stock_count'] as $a) {
    check("$a is routed", strpos($m, "'$a',") !== false);
}
exit($fail ? 1 : 0);
