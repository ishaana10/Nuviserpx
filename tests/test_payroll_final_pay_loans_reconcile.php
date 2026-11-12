<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollAttendanceService as A;
use App\Services\PayrollLoanService as L;
use App\Services\PayrollPostingService as P;
use App\Services\PayrollReconciliationService as R;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
function near($a, $b) { return abs($a - $b) < 0.005; }
$acc = ['wages' => 1, 'er_pension' => 2, 'er_levies' => 3, 'pension' => 4, 'tax' => 5, 'levies' => 6, 'other' => 7, 'loans' => 9, 'net' => 8];
function sums($lines) { $d = $c = 0.0; foreach ($lines as [$a, $s, $x]) { $s === 1 ? $d += $x : $c += $x; } return [round($d, 2), round($c, 2)]; }
function line($lines, $a) { foreach ($lines as $l) if ($l[0] === $a) return $l; return null; }

// ---- Final pay
$base = PayrollEngine::calculate(['basic_salary' => 4000]);
$lp = PayrollEngine::calculate(['basic_salary' => 4000, 'leave_payout' => 1200]);
check('leave payout adds to gross', near($lp['gross_salary'], $base['gross_salary'] + 1200));
check('leave payout is pensionable', near($lp['fnpf_base'], $base['fnpf_base'] + 1200));
check('leave payout is taxed as a lump sum', $lp['paye_lump_sum'] > 0 && $lp['paye_tax'] > $base['paye_tax']);
check('negative payout ignored', near(PayrollEngine::calculate(['basic_salary' => 4000, 'leave_payout' => -50])['gross_salary'], $base['gross_salary']));
$cap = PayrollEngine::default_rules()['redundancy']['exempt_up_to'];
$red = PayrollEngine::calculate(['basic_salary' => 4000, 'redundancy_payments' => $cap - 1000]);
check('redundancy under the cap adds gross, no extra tax', near($red['gross_salary'], $base['gross_salary'] + $cap - 1000) && near($red['paye_tax'], $base['paye_tax']));
$red2 = PayrollEngine::calculate(['basic_salary' => 4000, 'redundancy_payments' => $cap + 2000]);
check('redundancy over the cap is taxed on the excess only', near($red2['paye_tax'] - $base['paye_tax'], round(2000 * PayrollEngine::default_rules()['redundancy']['rate'], 2)));
check('redundancy is not pensionable', near($red2['fnpf_base'], $base['fnpf_base']));
$fin = PayrollEngine::calculate(['basic_salary' => 4000, 'leave_payout' => 1200, 'redundancy_payments' => $cap + 2000, 'unpaid_deduction' => 800]);
[$d, $c] = sums(P::lines(P::figures($fin), $acc));
check('final pay journal balances', $d === $c && $d > 0);

// ---- Termination
$after = A::daysAfterTermination('2026-11-01', '2026-11-30', '2026-11-12', 5); // Thu 12 -> 13th is Fri
check('days after last day count (Nov 13, 16-20, 23-27, 30 = 12)', count($after) === 12);
check('termination on period end: nothing unpaid', A::daysAfterTermination('2026-11-01', '2026-11-30', '2026-11-30') === []);
check('left before the period: every working day', count(A::daysAfterTermination('2026-11-01', '2026-11-30', '2026-10-15')) === 21);
check('blank / bad date: employed', A::daysAfterTermination('2026-11-01', '2026-11-30', null) === [] && A::daysAfterTermination('2026-11-01', '2026-11-30', 'nonsense') === []);
check('leave on days after leaving is not counted twice', A::unpaidDays([['leave_type' => 'unpaid', 'start_date' => '2026-11-16', 'end_date' => '2026-11-17']], [['attendance_date' => '2026-11-18', 'status' => 'absent']], '2026-11-01', '2026-11-30', 5, $after) === 0.0);
check('leave before leaving still counts', A::unpaidDays([['leave_type' => 'unpaid', 'start_date' => '2026-11-09', 'end_date' => '2026-11-10']], [], '2026-11-01', '2026-11-30', 5, $after) === 2.0);

// ---- Loans
check('recovery is the instalment', L::recovery(200, 1000) === 200.0);
check('recovery is capped at the balance', L::recovery(200, 80.5) === 80.5);
check('no balance, no recovery', L::recovery(200, 0) === 0.0);
$lines = [
    ['loan_id' => 0, 'type' => 'deduction', 'amount' => 30],
    ['loan_id' => 1, 'type' => 'deduction', 'amount' => 200],
    ['loan_id' => 2, 'type' => 'deduction', 'amount' => 100],
];
$cut = L::capToShortfall($lines, 150);
check('later loan trimmed to zero and dropped, earlier one reduced', count($cut) === 2 && near($cut[1]['amount'], 150));
$cut2 = L::capToShortfall($lines, 400);
check('never touches non-loan deductions', count($cut2) === 1 && $cut2[0]['loan_id'] === 0 && near($cut2[0]['amount'], 30));
check('no shortfall, no change', L::capToShortfall($lines, 0) === $lines);
check('validation: good loan', L::validate(['kind' => 'loan', 'principal' => 1000, 'installment' => 100, 'start_date' => '2026-11-08']) === []);
check('validation: instalment over principal', count(L::validate(['kind' => 'loan', 'principal' => 100, 'installment' => 150, 'start_date' => '2026-11-08'])) === 1);
check('validation: bad type / amount / date', count(L::validate(['kind' => 'gift', 'principal' => 0, 'installment' => 0, 'start_date' => 'x'])) === 4);
$il = L::issueLines(9, 3, 500.0, 'x');
check('payout journal: Dr loans, Cr bank, balanced', $il[0][0] === 9 && $il[0][1] === 500.0 && $il[1][0] === 3 && $il[1][2] === 500.0);

$ln = PayrollEngine::calculate(['basic_salary' => 3000, 'deductions' => 20, 'components' => [['type' => 'deduction', 'amount' => 150, 'loan_id' => 7, 'is_taxable' => 0, 'is_fnpf_subject' => 0], ['type' => 'deduction', 'amount' => 35, 'loan_id' => 0]]]);
check('engine reports loan recovery separately', near($ln['loan_recovery'], 150) && near($ln['component_deductions'], 185) && near($ln['deductions'], 205));
$ll = P::lines(P::figures($ln), $acc);
[$d, $c] = sums($ll);
check('journal with loan recovery balances', $d === $c && $d > 0);
check('loan recovery credits the loan account', near(line($ll, 9)[2], 150));
check('other deductions payable excludes the loan', near(line($ll, 7)[2], 55));
check('no loan: no loan line', line(P::lines(P::figures(PayrollEngine::calculate(['basic_salary' => 3000])), $acc), 9) === null);

// ---- Reconciliation
$calc = PayrollEngine::calculate(['basic_salary' => 4200, 'overtime' => 100, 'deductions' => 20, 'leave_payout' => 300,
    'components' => [['type' => 'deduction', 'amount' => 90, 'loan_id' => 3, 'is_taxable' => 0, 'is_fnpf_subject' => 0], ['type' => 'earning', 'amount' => 150, 'is_taxable' => 1, 'is_fnpf_subject' => 1]]]);
$rec = ['gross_salary' => $calc['gross_salary'], 'employee_fnpf' => $calc['employee_fnpf'], 'employer_fnpf' => $calc['employer_fnpf'], 'paye_tax' => $calc['paye_tax'], 'srt_tax' => 0,
    'deductions' => $calc['deductions'], 'net_salary' => $calc['net_salary'], 'employer_cost' => $calc['employer_cost'], 'loan_recovery' => $calc['loan_recovery']];
check('record adds up', R::recordGap($rec) === 0.0);
$bad = $rec; $bad['net_salary'] += 0.50;
check('record that does not add up is caught', near(R::recordGap($bad), 0.50));

// What the journal would hold, by account name
$names = array_map(fn($a) => $a[0], P::ACCOUNTS);
$ids = array_flip(array_keys(P::ACCOUNTS));
$actual = [];
foreach (P::lines(P::figures($calc), array_map(fn($i) => $i + 1, $ids)) as [$aid, $side, $amt]) {
    $actual[$names[array_keys(P::ACCOUNTS)[$aid - 1]]][$side === 1 ? 'debit' : 'credit'] = $amt;
}
check('a correct journal reconciles', R::compare(R::expected($rec), $actual) === []);
$off = $actual; $off['Net Salaries Payable']['credit'] += 1.00;
$diff = R::compare(R::expected($rec), $off);
check('a journal off by 1.00 is reported on that account', count($diff) === 1 && $diff[0][0] === 'Net Salaries Payable' && near($diff[0][4], 1.00));
$miss = $actual; unset($miss['Income Tax Withheld Payable']);
check('a missing line is reported', count(R::compare(R::expected($rec), $miss)) === 1);
$extra = $actual; $extra['Bank']['credit'] = 5.0;
check('an unexpected account is reported', count(R::compare(R::expected($rec), $extra)) === 1);
$lv = $rec; $lv['employer_cost'] += 12.34; // a levy with no payslip column still has to be in the journal
check('levies are checked via employer cost', count(R::compare(R::expected($lv), $actual)) === 2);
check('half a cent of rounding is tolerated', R::compare(R::expected($rec), array_map(fn($s) => array_map(fn($v) => $v + 0.004, $s), $actual)) === []);

// ---- Wiring (no database in tests)
$svc = file_get_contents(base_app . 'src/Services/PayrollService.php');
check('loan recoveries applied when a payroll is saved', strpos($svc, '->apply($payrollId, $compLines)') !== false);
check('loans put back on void and delete', substr_count($svc, 'PayrollLoanService($this->conn, $this->settings))->release($id)') === 2);
check('loans put back when a run is returned', strpos(file_get_contents(base_app . 'src/Services/PayrollApprovalService.php'), '$loans->release($pid)') !== false);
check('payroll stores final pay and loan columns', substr_count($svc, '`leave_payout` =') === 2 && substr_count($svc, '`loan_recovery` =') === 2);
check('payroll for a period after leaving is refused', strpos($svc, 'so no pay is due for a period that starts after that') !== false);
check('batch skips people who left before the period', strpos($svc, "(left ' . \$left") !== false);
check('loan actions need the pay permission', strpos(file_get_contents(base_app . 'src/Support/Permissions.php'), "'issue_staff_loan'          => ['payroll_pay']") !== false);
check('payroll columns widened to ledger precision', strpos(file_get_contents(base_app . 'src/Database/LegacySchemaCatchup.php'), "MODIFY `{\$mc}` decimal(18,4)") !== false);

echo $fail ? "\n$fail checks FAILED\n" : "\nAll final pay, loan and reconciliation tests passed\n";
exit($fail ? 1 : 0);
