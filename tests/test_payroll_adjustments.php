<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollAdjustmentService as A;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
$ok = ['employee_id' => 3, 'amount' => '250.50', 'effective_date' => '2026-09-01', 'reason' => 'Backdated raise'];
check('valid adjustment', A::validate($ok) === []);
check('no employee', count(A::validate(['employee_id' => 0] + $ok)) === 1);
check('zero amount', count(A::validate(['amount' => '0'] + $ok)) === 1);
check('negative amount', count(A::validate(['amount' => '-5'] + $ok)) === 1);
check('text amount', count(A::validate(['amount' => 'abc'] + $ok)) === 1);
check('bad date', count(A::validate(['effective_date' => '2026-13-45x'] + $ok)) === 1);
check('missing reason', count(A::validate(['reason' => ' '] + $ok)) === 1);
check('total', A::total([['amount' => '100.10'], ['amount' => '50.20']]) === 150.30);
check('empty total', A::total([]) === 0.0);

// Back pay is pensionable and increases tax and gross by the lump amount.
$base = PayrollEngine::calculate(['basic_salary' => 3000]);
$with = PayrollEngine::calculate(['basic_salary' => 3000, 'lump_sum_payments' => 500]);
check('gross rises by back pay', abs($with['gross_salary'] - $base['gross_salary'] - 500) < 0.005);
check('pension on back pay (8%)', abs($with['employee_pension'] - $base['employee_pension'] - 40) < 0.005);
check('tax not lower with back pay', $with['income_tax'] >= $base['income_tax']);
check('net equals gross - pension - tax', abs($with['net_salary'] - ($with['gross_salary'] - $with['employee_pension'] - $with['income_tax'])) < 0.005);

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll adjustment tests passed\n";
exit($fail ? 1 : 0);
