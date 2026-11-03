<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollPostingService as P;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
$acc = ['wages' => 1, 'er_pension' => 2, 'er_levies' => 3, 'pension' => 4, 'tax' => 5, 'levies' => 6, 'other' => 7, 'bank' => 8];
function totals($lines) { $d = $c = 0.0; foreach ($lines as [$a, $s, $x]) { $s === 1 ? $d += $x : $c += $x; } return [round($d, 2), round($c, 2)]; }
function by($lines, $a) { foreach ($lines as $l) if ($l[0] === $a) return $l; return null; }

// A real calculation, with an extra deduction, posts balanced.
$calc = PayrollEngine::calculate(['basic_salary' => 4200, 'overtime' => 150, 'allowances' => 100, 'other_earnings' => 25.55, 'deductions' => 60.10]);
$lines = P::lines(P::figures($calc), $acc);
[$d, $c] = totals($lines);
check('balanced', $d === $c && $d > 0);
check('debits = employer cost', abs($d - $calc['employer_cost']) < 0.005);
check('wages debit = gross', by($lines, 1)[1] === 1 && abs(by($lines, 1)[2] - $calc['gross_salary']) < 0.005);
check('bank credit = net pay', by($lines, 8)[1] === 2 && abs(by($lines, 8)[2] - $calc['net_salary']) < 0.005);
check('pension payable = employee + employer', abs(by($lines, 4)[2] - ($calc['employee_pension'] + $calc['employer_pension'])) < 0.005);
check('income tax payable = tax withheld', abs(by($lines, 5)[2] - $calc['income_tax']) < 0.005);
check('other deductions payable', abs(by($lines, 7)[2] - 60.10) < 0.005);

// Zero lines are left out (no deductions, no levies).
$calc2 = PayrollEngine::calculate(['basic_salary' => 1000, 'rules' => ['employer_levies' => []]]);
$l2 = P::lines(P::figures($calc2), $acc);
check('no zero lines', by($l2, 7) === null && by($l2, 3) === null && by($l2, 6) === null);
[$d2, $c2] = totals($l2);
check('still balanced', $d2 === $c2);

// Many awkward salaries all balance to the cent.
$ok = true;
foreach ([333.33, 1234.56, 2999.99, 5555.55, 7777.77, 12345.67, 99999.99] as $b) {
    foreach ([0, 13.37] as $ded) {
        $cc = PayrollEngine::calculate(['basic_salary' => $b, 'overtime_1_5_hours' => 3.5, 'deductions' => $ded]);
        try { [$x, $y] = totals(P::lines(P::figures($cc), $acc)); if ($x !== $y) $ok = false; } catch (\Throwable $e) { $ok = false; echo $e->getMessage() . " @ $b\n"; }
    }
}
check('rounding never unbalances', $ok);

// Inconsistent figures are refused rather than posted.
$thrown = false;
try { P::lines(['gross' => 1000, 'employee_pension' => 80, 'employer_pension' => 80, 'income_tax' => 0, 'levies' => 0, 'deductions' => 0, 'net' => 900], $acc); } catch (\RuntimeException $e) { $thrown = true; }
check('unbalanced figures rejected', $thrown);

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll posting tests passed\n";
exit($fail ? 1 : 0);
