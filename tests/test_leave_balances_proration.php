<?php
// Leave balances / accruals and joining-date proration.
$root = dirname(__DIR__);
require_once "$root/src/Services/PayrollAttendanceService.php";
require_once "$root/src/Services/LeaveBalanceService.php";
use App\Services\LeaveBalanceService as L;
use App\Services\PayrollAttendanceService as A;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
function eq($a, $b) { return abs($a - $b) < 0.006; }

// ---- accrual
check('full year accrues the full entitlement', eq(L::yearAccrual(20, '2020-01-01', null, 2026, '2026-12-31'), 20));
check('half year accrues about half', eq(L::yearAccrual(20, null, null, 2026, '2026-07-02'), 20 * 183 / 365));
check('joiner mid-year accrues from joining date', eq(L::yearAccrual(20, '2026-07-01', null, 2026, '2026-12-31'), 20 * 184 / 365));
check('nothing before joining', L::yearAccrual(20, '2027-03-01', null, 2026, '2026-12-31') === 0.0);
check('stops at termination', eq(L::yearAccrual(20, '2020-01-01', '2026-03-31', 2026, '2026-12-31'), 20 * 90 / 365));
check('leap year uses 366', eq(L::yearAccrual(366, '2024-01-01', null, 2024, '2024-12-31'), 366));
check('no entitlement, no accrual', L::yearAccrual(0, '2020-01-01', null, 2026, '2026-12-31') === 0.0);

// ---- taken days
$t = L::takenByYear([['start_date' => '2026-12-28', 'end_date' => '2027-01-04'], ['start_date' => '2026-12-29', 'end_date' => '2026-12-30']]);
check('taken split by year, working days only, overlaps once', $t === [2026 => 4.0 + 0, 2027 => 2.0 + 0] || ($t[2026] == 4 && $t[2027] == 2));

// ---- roll forward
$pol = ['annual_days' => 10, 'carry_over_max' => 5.0, 'max_balance' => null];
$b = L::balance($pol, '2025-01-01', null, '2026-01-01', [2025 => 2], []);
check('year 1: 10 earned, 2 taken -> 8 closing, carry capped to 5', eq($b['opening'], 5) && eq($b['lapsed'], 3));
check('carry + first day of new year accrual', eq($b['balance'], 5 + 10 / 365 - 0 + 0));
$b = L::balance(['annual_days' => 10, 'carry_over_max' => null, 'max_balance' => null], '2025-01-01', null, '2026-12-31', [2025 => 2], []);
check('blank carry-over keeps everything', eq($b['balance'], 8 + 10));
$b = L::balance(['annual_days' => 10, 'carry_over_max' => 0.0, 'max_balance' => null], '2025-01-01', null, '2026-12-31', [], []);
check('zero carry-over lapses the lot', eq($b['balance'], 10) && eq($b['lapsed'], 10));
$b = L::balance(['annual_days' => 10, 'carry_over_max' => null, 'max_balance' => 12.0], '2025-01-01', null, '2026-12-31', [], []);
check('ceiling caps the balance', eq($b['balance'], 12) && eq($b['lapsed'], 8));
$b = L::balance(['annual_days' => 10, 'carry_over_max' => null, 'max_balance' => null], '2026-01-01', null, '2026-12-31', [2026 => 3], [['effective_date' => '2026-01-01', 'days' => 4.5]]);
check('adjustment adds, taken subtracts', eq($b['balance'], 10 + 4.5 - 3) && eq($b['adjusted'], 4.5));
$b = L::balance(['annual_days' => 10, 'carry_over_max' => null, 'max_balance' => null], '2026-01-01', null, '2026-06-30', [], [['effective_date' => '2026-09-01', 'days' => 5]]);
check('future-dated adjustment not counted yet', $b['adjusted'] == 0);
$b = L::balance(['annual_days' => 10, 'carry_over_max' => null, 'max_balance' => null], null, null, '2026-06-30', [], [['effective_date' => '2025-12-31', 'days' => 7]]);
check('opening balance adjustment from a previous year rolls forward', eq($b['opening'], 7));
$b = L::balance(['annual_days' => 10, 'carry_over_max' => 0.0, 'max_balance' => null], '2026-01-01', null, '2026-02-01', [2026 => 5], []);
check('balance can go negative', $b['balance'] < 0);

// ---- shortfall
check('within balance is fine', L::shortfall(3, 5, false, 'Annual leave') === null);
check('exact balance is fine', L::shortfall(5, 5, false, 'Annual leave') === null);
check('over balance refused with numbers', strpos((string)L::shortfall(6, 5, false, 'Annual leave'), '6 days requested, 5 available') !== false);
check('negative allowed by policy', L::shortfall(9, 1, true, 'Annual leave') === null);
check('zero-day request ignored', L::shortfall(0, 0, false, 'x') === null);

// ---- joining date proration
$d = A::daysBeforeJoining('2026-11-01', '2026-11-30', '2026-11-16');
check('working days before joining (Nov 2026: 2-13 = 10 weekdays)', count($d) === 10 && $d[0] === '2026-11-02' && end($d) === '2026-11-13');
check('joining on the 1st: nothing to dock', A::daysBeforeJoining('2026-11-01', '2026-11-30', '2026-11-01') === []);
check('joined before period: nothing', A::daysBeforeJoining('2026-11-01', '2026-11-30', '2026-01-05') === []);
check('joined after period: whole period', count(A::daysBeforeJoining('2026-11-01', '2026-11-30', '2026-12-10')) === 21);
check('blank / zero joining date: nothing', A::daysBeforeJoining('2026-11-01', '2026-11-30', null) === [] && A::daysBeforeJoining('2026-11-01', '2026-11-30', '0000-00-00') === []);
check('prorates a starter inside the period', A::prorateJoiner('2026-11-01', '2026-11-30', '2026-11-16', false));
check('not when employee already has payroll', !A::prorateJoiner('2026-11-01', '2026-11-30', '2026-11-16', true));
check('not for a back-dated period before joining', !A::prorateJoiner('2026-09-01', '2026-09-30', '2026-11-16', false));
check('not when joined before the period', !A::prorateJoiner('2026-11-01', '2026-11-30', '2026-10-31', false));
check('not when the rule is off', !A::prorateJoiner('2026-11-01', '2026-11-30', '2026-11-16', false, false));
$days = count($d);
$daily = A::dailyRate(2600, 12, 52, 5);
check('starter deduction = days x daily rate', eq(A::deduction($days, $daily, 2600), round($days * $daily, 2)) && A::deduction($days, $daily, 2600) < 2600);

// ---- wiring
$read = fn($f) => file_get_contents("$root/$f");
$att = $read('src/Services/PayrollAttendanceService.php');
check('attendance service applies proration', strpos($att, 'prorateJoiner(') !== false && strpos($att, 'daysBeforeJoining(') !== false);
check('payroll service passes the rule', strpos($read('src/Services/PayrollService.php'), "prorate_joiners") !== false);
check('engine defaults and normalises prorate_joiners', substr_count($read('classes/PayrollEngine.php'), 'prorate_joiners') >= 2);
check('rules page has the toggle', strpos($read('admin/payroll/rules_settings.php'), 'r-pj') !== false);
check('employee form no longer defaults joining date to today', strpos($read('admin/employees/manage_employee.php'), "date_of_joining) ? \$date_of_joining : date(") === false);
check('blank joining date saved as NULL', strpos($read('src/Services/EmployeeService.php'), "'date_of_joining'") !== false);
$es = $read('src/Services/EmployeeService.php');
check('leave save and approval check the balance', substr_count($es, 'LeaveBalanceService') >= 2);
$perm = $read('src/Support/Permissions.php');
foreach (['save_leave_policy', 'save_leave_adjustment', 'leave_balance_info', 'leave_payout_suggestion'] as $a) {
    check("$a is permission-mapped and routed", strpos($perm, "'$a'") !== false && strpos($read('classes/Master.php'), "case '$a':") !== false);
}
$mig = $read('database/migrations/020_leave_balances.sql');
check('migration: tables, cancelled status, no semicolons in comments', strpos($mig, 'leave_policies') !== false && strpos($mig, 'leave_adjustments') !== false && strpos($mig, "'cancelled'") !== false && !preg_match('/^--.*;/m', $mig));
check('base SQL carries leave tables and cancelled', strpos($read('database/nuviserpx.sql'), 'CREATE TABLE `leave_policies`') !== false && strpos($read('database/nuviserpx.sql'), "enum('pending','approved','rejected','cancelled')") !== false);
exit($fail ? 1 : 0);
