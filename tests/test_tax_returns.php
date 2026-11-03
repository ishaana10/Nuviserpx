<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\TaxReturnService as T;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

$jv = ['output_tax' => 15.0, 'output_net' => 100.0, 'input_tax' => 3.0, 'input_net' => 20.0];
$t = T::totals([['total' => 115.0, 'tax_amount' => 15.0]], [['total' => 46.0, 'tax_amount' => 6.0]], $jv);
check('taxable sales', $t['taxable_sales'] === 200.0);
check('taxable purchases', $t['taxable_purchases'] === 60.0);
check('output tax', $t['output_tax'] === 30.0);
check('input tax', $t['input_tax'] === 9.0);
check('net = output - input', $t['net_tax'] === 21.0);
check('refund is negative', T::totals([], [['total' => 115.0, 'tax_amount' => 15.0]], ['output_tax' => 0, 'output_net' => 0, 'input_tax' => 0, 'input_net' => 0])['net_tax'] === -15.0);

$ok = ['period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'filed_date' => '2026-10-20'];
$today = '2026-11-04';
check('valid when books closed to period end', T::validate($ok, $today, [], '2026-09-30') === []);
check('books closed past period end is fine', T::validate($ok, $today, [], '2026-10-31') === []);
check('books not closed', count(T::validate($ok, $today, [], '2026-08-31')) === 1);
check('no lock at all', count(T::validate($ok, $today, [], null)) === 1);
check('overlap with filed return', count(T::validate($ok, $today, [['2026-09-01', '2026-09-30']], '2026-09-30')) === 1);
check('adjacent period does not overlap', T::validate($ok, $today, [['2026-04-01', '2026-06-30']], '2026-09-30') === []);
check('period not ended', count(T::validate(['period_from' => '2026-10-01', 'period_to' => '2026-12-31', 'filed_date' => '2026-11-01'] , $today, [], '2026-12-31')) === 1);
check('backwards period', count(T::validate(['period_from' => '2026-09-30', 'period_to' => '2026-07-01', 'filed_date' => '2026-10-20'], $today, [], '2026-09-30')) >= 1);
check('bad dates', T::validate(['period_from' => 'x', 'period_to' => '2026-09-30'], $today, [], '2026-09-30') === ['Enter the period the return covers.']);
check('future filing date', count(T::validate(['filed_date' => '2026-12-01'] + $ok, $today, [], '2026-09-30')) === 1);

echo $fail ? "\n$fail FAILED\n" : "\nAll tax return tests passed\n";
exit($fail ? 1 : 0);
