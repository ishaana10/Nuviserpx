<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollPaymentService as P;
use App\Services\PayrollPostingService as Post;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

check('valid', P::validate(['refs' => ['RUN-1'], 'pay_date' => '2026-11-03', 'bank_account_id' => 5], 100.0) === []);
check('no runs', count(P::validate(['refs' => [], 'pay_date' => '2026-11-03', 'bank_account_id' => 5], 0.0)) >= 1);
check('blank run ref ignored', count(P::validate(['refs' => ['  '], 'pay_date' => '2026-11-03', 'bank_account_id' => 5], 0.0)) >= 1);
check('nothing to pay', in_array('There is nothing left to pay on the chosen runs.', P::validate(['refs' => ['R'], 'pay_date' => '2026-11-03', 'bank_account_id' => 5], 0.0), true));
check('bad date', count(P::validate(['refs' => ['R'], 'pay_date' => 'x', 'bank_account_id' => 5], 10.0)) === 1);
check('no bank', count(P::validate(['refs' => ['R'], 'pay_date' => '2026-11-03', 'bank_account_id' => 0], 10.0)) === 1);

$l = P::lines(8, 9, 1234.567, 'Net salaries');
check('two lines, balanced', count($l) === 2 && $l[0]['debit'] === 1234.57 && $l[1]['credit'] === 1234.57);
check('debits payable, credits bank', $l[0]['account_id'] === 8 && $l[1]['account_id'] === 9);

// Payroll credits the same account the payment debits, so it clears to zero.
check('payroll uses Net Salaries Payable', Post::ACCOUNTS['net'][0] === 'Net Salaries Payable' && Post::ACCOUNTS['net'][1] === 'Current Liability');
$acc = ['wages' => 1, 'er_pension' => 2, 'er_levies' => 3, 'pension' => 4, 'tax' => 5, 'levies' => 6, 'other' => 7, 'net' => 8];
$calc = PayrollEngine::calculate(['basic_salary' => 3000]);
$net = 0.0;
foreach (Post::lines(Post::figures($calc), $acc) as [$a, $s, $x]) { if ($a === 8) $net += $x; }
$pay = P::lines(8, 9, round($net, 2), 'x');
check('payment clears the payable', abs($net - $pay[0]['debit']) < 0.005);

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll payment tests passed\n";
exit($fail ? 1 : 0);
