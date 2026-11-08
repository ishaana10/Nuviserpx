<?php
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollAttendanceService as A;
use App\Services\PayrollComponentService as C;
use App\Services\PayrollPostingService as P;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
function near($a, $b) { return abs($a - $b) < 0.005; }

// ---- Components: flags decide tax and pension treatment
$base = ['basic_salary' => 3000, 'allowances' => 0];
$plain = PayrollEngine::calculate($base);
$comp = fn($type, $amt, $tax, $fn) => ['type' => $type, 'amount' => $amt, 'is_taxable' => $tax, 'is_fnpf_subject' => $fn];

$a = PayrollEngine::calculate($base + ['components' => [$comp('earning', 200, 1, 1)]]);
check('taxable+pension earning raises gross', near($a['gross_salary'], $plain['gross_salary'] + 200));
check('pension base includes it', near($a['fnpf_base'], $plain['fnpf_base'] + 200));
check('employee pension = 8% of base', near($a['employee_fnpf'], round($a['fnpf_base'] * 0.08, 2)));

$b = PayrollEngine::calculate($base + ['components' => [$comp('earning', 200, 1, 0)]]);
check('non-pension earning leaves pension base alone', near($b['fnpf_base'], $plain['fnpf_base']));
check('...but is in gross', near($b['gross_salary'], $plain['gross_salary'] + 200));

$big = ['basic_salary' => 6000];
$p0 = PayrollEngine::calculate($big);
$t = PayrollEngine::calculate($big + ['components' => [$comp('earning', 500, 1, 1)]]);
$x = PayrollEngine::calculate($big + ['components' => [$comp('earning', 500, 0, 1)]]);
check('taxable component raises tax', $t['paye_tax'] > $p0['paye_tax']);
check('tax-exempt component adds no tax', near($x['paye_tax'], $p0['paye_tax']));
check('exempt component excluded from taxable income', near($x['taxable_income'], $x['gross_salary'] - 500));

$d = PayrollEngine::calculate($base + ['deductions' => 25, 'components' => [$comp('deduction', 40, 0, 0)]]);
check('component deduction comes off net pay', near($d['net_salary'], $plain['net_salary'] - 25 - 40));
check('deductions total = typed + component', near($d['deductions'], 65) && near($d['component_deductions'], 40) && near($d['typed_deductions'], 25));
check('deduction component does not change tax', near($d['paye_tax'], $plain['paye_tax']));
check('zero/negative component ignored', near(PayrollEngine::calculate($base + ['components' => [$comp('earning', 0, 1, 1), $comp('earning', -5, 1, 1)]])['gross_salary'], $plain['gross_salary']));

// Journal stays balanced with components and unpaid days
$acc = ['wages' => 1, 'er_pension' => 2, 'er_levies' => 3, 'pension' => 4, 'tax' => 5, 'levies' => 6, 'other' => 7, 'net' => 8];
$mix = PayrollEngine::calculate(['basic_salary' => 4200, 'unpaid_deduction' => 387.69, 'deductions' => 10, 'components' => [$comp('earning', 250, 1, 1), $comp('earning', 80, 0, 0), $comp('deduction', 55.55, 0, 0)]]);
$lines = P::lines(P::figures($mix), $acc);
$dr = $cr = 0.0;
foreach ($lines as [$ac, $side, $amt]) { $side === 1 ? $dr += $amt : $cr += $amt; }
check('journal balances with components + unpaid leave', abs(round($dr, 2) - round($cr, 2)) < 0.005 && $dr > 0);
check('other deductions payable carries typed + component', near(array_values(array_filter($lines, fn($l) => $l[0] === 7))[0][2], 65.55));

// Lines: default amount fallback, zero dropped, snapshot shape
$ls = C::lines([
    ['component_id' => 1, 'code' => 'H', 'name' => 'Housing', 'type' => 'earning', 'is_taxable' => 1, 'is_fnpf_subject' => 0, 'amount' => null, 'default_amount' => '120.00'],
    ['component_id' => 2, 'code' => 'L', 'name' => 'Loan', 'type' => 'deduction', 'is_taxable' => 0, 'is_fnpf_subject' => 0, 'amount' => '30', 'default_amount' => '0'],
    ['component_id' => 3, 'code' => 'Z', 'name' => 'Zero', 'type' => 'earning', 'amount' => null, 'default_amount' => '0'],
]);
check('default amount used when none set', count($ls) === 2 && near($ls[0]['amount'], 120));
check('own amount beats default; zero line dropped', near($ls[1]['amount'], 30) && $ls[1]['type'] === 'deduction');

// ---- Unpaid leave: engine
$u = PayrollEngine::calculate(['basic_salary' => 3000, 'unpaid_deduction' => 300]);
check('unpaid deduction lowers gross', near($u['gross_salary'], 2700));
check('and pension base', near($u['fnpf_base'], 2700));
check('and net pay', $u['net_salary'] < PayrollEngine::calculate(['basic_salary' => 3000])['net_salary']);
check('overtime rate stays on contract basic', near(PayrollEngine::calculate(['basic_salary' => 3000, 'unpaid_deduction' => 300, 'overtime_1_5_hours' => 2])['overtime_hourly_rate'], PayrollEngine::calculate(['basic_salary' => 3000, 'overtime_1_5_hours' => 2])['overtime_hourly_rate']));
check('deduction capped at basic', near(PayrollEngine::calculate(['basic_salary' => 1000, 'unpaid_deduction' => 5000])['gross_salary'], 0));

// ---- Attendance
check('Mon-Fri working days in a week', count(A::workingDays('2026-11-02', '2026-11-08', 5)) === 5);
check('Mon-Sat', count(A::workingDays('2026-11-02', '2026-11-08', 6)) === 6);
check('bad range is empty', A::workingDays('2026-11-08', '2026-11-02') === []);
$from = '2026-11-01'; $to = '2026-11-30';
check('unpaid leave counts working days only (Mon 2 - Sun 8)', A::unpaidDays([['leave_type' => 'unpaid', 'start_date' => '2026-11-02', 'end_date' => '2026-11-08']], [], $from, $to) === 5.0);
check('leave is clipped to the period', A::unpaidDays([['leave_type' => 'unpaid', 'start_date' => '2026-10-28', 'end_date' => '2026-11-03']], [], $from, $to) === 2.0);
check('paid leave is never unpaid', A::unpaidDays([['leave_type' => 'sick', 'start_date' => '2026-11-02', 'end_date' => '2026-11-04']], [], $from, $to) === 0.0);
check('absence counts a day', A::unpaidDays([], [['attendance_date' => '2026-11-03', 'status' => 'absent']], $from, $to) === 1.0);
check('half day counts half', A::unpaidDays([], [['attendance_date' => '2026-11-03', 'status' => 'half_day']], $from, $to) === 0.5);
check('absence on a weekend is ignored', A::unpaidDays([], [['attendance_date' => '2026-11-07', 'status' => 'absent']], $from, $to) === 0.0);
check('absence covered by approved paid leave is not deducted', A::unpaidDays([['leave_type' => 'paid', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03']], [['attendance_date' => '2026-11-03', 'status' => 'absent']], $from, $to) === 0.0);
check('absence on unpaid-leave day is not counted twice', A::unpaidDays([['leave_type' => 'unpaid', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03']], [['attendance_date' => '2026-11-03', 'status' => 'absent']], $from, $to) === 1.0);
check('late is not deducted', A::unpaidDays([], [['attendance_date' => '2026-11-03', 'status' => 'late']], $from, $to) === 0.0);
check('outside the period is ignored', A::unpaidDays([], [['attendance_date' => '2026-10-30', 'status' => 'absent']], $from, $to) === 0.0);

$daily = A::dailyRate(4200, 12, 52, 5);
check('daily rate = annual basic / (52 x 5)', near($daily, 4200 * 12 / 260));
check('deduction = days x daily rate', near(A::deduction(2, $daily, 4200), round(2 * $daily, 2)));
check('deduction never exceeds basic', near(A::deduction(400, $daily, 4200), 4200));

// ---- Rules
$r = PayrollEngine::normalize_rules(['attendance' => ['working_days_per_week' => 6]]);
check('rule: working days per week stored', $r['attendance']['working_days_per_week'] === 6);
check('rule: default is 5', PayrollEngine::normalize_rules(null)['attendance']['working_days_per_week'] === 5);
check('rule: bad value falls back', PayrollEngine::normalize_rules(['attendance' => ['working_days_per_week' => 9]])['attendance']['working_days_per_week'] === 5);
$v = PayrollEngine::default_rules(); $v['attendance']['working_days_per_week'] = 0;
check('rule: validation rejects 0', count(PayrollEngine::validate_rules($v)) === 1);
check('rule: defaults validate', PayrollEngine::validate_rules(PayrollEngine::default_rules()) === []);

// ---- Wiring (no database in tests): the save path uses both features and stores what it paid
$svc = file_get_contents(base_app . 'src/Services/PayrollService.php');
check('save passes components and unpaid deduction to the engine', strpos($svc, "'components' => \$compLines") !== false && strpos($svc, "'unpaid_deduction' => \$unpaid_deduction") !== false);
check('payroll stores unpaid and component columns', substr_count($svc, '`component_deductions` =') === 2 && substr_count($svc, '`unpaid_days` =') === 2);
check('new payroll snapshots its component lines', strpos($svc, '->saveItems(') !== false);
check('edit reads stored lines, not today\'s assignments', strpos($svc, '$components->forPayroll($editId)') !== false);
check('batch passes unpaid days per employee', strpos($svc, "emp_unpaid_days") !== false);
check('assignment actions are permission-mapped', strpos(file_get_contents(base_app . 'src/Support/Permissions.php'), "'assign_salary_component'") !== false);

echo $fail ? "\n$fail checks FAILED\n" : "\nAll component and unpaid-leave tests passed\n";
exit($fail ? 1 : 0);
