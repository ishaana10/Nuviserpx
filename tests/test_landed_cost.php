<?php
// Landed cost: allocation maths, input cleaning and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/InventoryAccountingService.php";
require_once "$root/src/Services/LandedCostService.php";
use App\Services\LandedCostService as L;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$a = L::allocate([1 => 100, 2 => 300], 40);
check('split by weight', $a[1] === 10.0 && $a[2] === 30.0);
$a = L::allocate([1 => 1, 2 => 1, 3 => 1], 100);
check('rounding left-overs keep the total exact', abs(array_sum($a) - 100) < 0.0001 && max($a) === 33.34);
$a = L::allocate([1 => 7, 2 => 11, 3 => 13], 99.99);
check('odd weights still add up', abs(array_sum($a) - 99.99) < 0.0001);
check('no weight, nothing allocated', array_sum(L::allocate([1 => 0, 2 => 0], 50)) === 0.0);
check('zero total allocates nothing', array_sum(L::allocate([1 => 5], 0)) === 0.0);

check('all on hand is all capitalised', L::capitalisable(100, 10, 10) === 100.0);
check('more on hand than received is capped', L::capitalisable(100, 10, 25) === 100.0);
check('half sold, half capitalised', L::capitalisable(100, 10, 5) === 50.0);
check('all sold, nothing capitalised', L::capitalisable(100, 10, 0) === 0.0);

check('cost rises by share per unit', L::newCost(5.0, 10, 20) === 7.0);
check('no stock keeps cost', L::newCost(5.0, 0, 20) === 5.0);

[$c, $e] = L::cleanCharges(['Freight', '', 'Duty'], ['120.50', '', '30']);
check('charges cleaned', $e === null && count($c) === 2 && $c[0]['amount'] === 120.5);
check('blank rows ignored', L::cleanCharges(['', ''], ['', ''])[1] === 'Enter at least one charge.');
check('amount needs description', L::cleanCharges([''], ['10'])[1] !== null);
check('zero amount rejected', L::cleanCharges(['Freight'], ['0'])[1] !== null);
check('negative amount rejected', L::cleanCharges(['Freight'], ['-5'])[1] !== null);
check('thousands separator accepted', L::cleanCharges(['Freight'], ['1,200'])[0][0]['amount'] === 1200.0);

$svc = file_get_contents("$root/src/Services/LandedCostService.php");
check('posting is locked per receipt', strpos($svc, 'GET_LOCK') !== false && strpos($svc, 'RELEASE_LOCK') !== false);
check('journal reversed if saving fails', strpos($svc, 'reverseJournal') !== false);
check('journal posted before the cost changes', strpos($svc, 'postSystemJournal') < strpos($svc, 'UPDATE `product_list`'));
check('audited', strpos($svc, 'log_audit') !== false);
$mig = file_get_contents("$root/database/migrations/031_landed_costs.sql");
check('numbered entries and one row per product', strpos($mig, 'uniq_landed_no') !== false && strpos($mig, 'uniq_landed_product') !== false);
$perm = file_get_contents("$root/src/Support/Permissions.php");
foreach (['preview_landed_cost', 'post_landed_cost'] as $a) {
    check("$a needs purchases edit", preg_match("/'$a'\s*=>\s*\['purchases', 'edit'\]/", $perm) === 1);
}
$m = file_get_contents("$root/classes/Master.php");
foreach (['preview_landed_cost', 'post_landed_cost'] as $a) { check("$a is routed", strpos($m, "case '$a':") !== false); }
exit($fail ? 1 : 0);
