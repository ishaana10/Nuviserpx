<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollRemittanceService as R;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
$ok = ['liability' => 'pension', 'amount' => '500.00', 'remit_date' => '2026-11-03', 'bank_account_id' => 7];
check('valid payment', R::validate($ok, 800) === []);
check('exactly the amount owed', R::validate(['amount' => '800'] + $ok, 800) === []);
check('over the amount owed', count(R::validate(['amount' => '800.01'] + $ok, 800)) === 1);
check('nothing owed', count(R::validate($ok, 0)) === 1);
check('unknown liability', count(R::validate(['liability' => 'x'] + $ok, 800)) === 1);
check('zero amount', count(R::validate(['amount' => '0'] + $ok, 800)) === 1);
check('text amount', count(R::validate(['amount' => 'abc'] + $ok, 800)) === 1);
check('bad date', count(R::validate(['remit_date' => 'soon'] + $ok, 800)) === 1);
check('no bank', count(R::validate(['bank_account_id' => 0] + $ok, 800)) === 1);
check('all four liabilities known', array_keys(R::LIABILITIES) === ['pension', 'tax', 'levies', 'other']);
foreach (R::LIABILITIES as $k => [$label, $accKey]) {
    check("liability $k maps to a payroll account", isset(\App\Services\PayrollPostingService::ACCOUNTS[$accKey]));
}
$l = R::lines(11, 22, 123.456, 'Pension');
check('lines debit liability, credit bank', $l[0]['account_id'] === 11 && $l[0]['debit'] === 123.46 && $l[1]['account_id'] === 22 && $l[1]['credit'] === 123.46);
check('lines balance', $l[0]['debit'] === $l[1]['credit'] && $l[0]['credit'] === 0.0 && $l[1]['debit'] === 0.0);

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll remittance tests passed\n";
exit($fail ? 1 : 0);
