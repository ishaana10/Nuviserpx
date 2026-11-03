<?php
// Pure tests for the jurisdiction-neutral payroll engine.
define('base_app', dirname(__DIR__) . '/');
require_once(base_app . 'classes/PayrollEngine.php');

$fail = 0;
function check($name, $cond) { global $fail; echo ($cond ? "PASS" : "FAIL") . " - $name\n"; if (!$cond) $fail++; }
function near($a, $b) { return abs($a - $b) < 0.005; }

// Starting preset: marginal bands reproduce the cumulative tables.
check('resident below threshold', PayrollEngine::tax_lookup(30000, true) == 0.0);
check('resident 100k', near(PayrollEngine::tax_lookup(100000, true), 13600.00));
check('resident 300k', near(PayrollEngine::tax_lookup(300000, true), 57500.00));
check('resident 1.2m', near(PayrollEngine::tax_lookup(1200000, true), 396500.00));
check('non-resident 100k', near(PayrollEngine::tax_lookup(100000, false), 20000.00));
check('non-resident 300k', near(PayrollEngine::tax_lookup(300000, false), 63900.00));
check('secondary income flat', near(PayrollEngine::tax_lookup(1000, true, 'S'), 200.00));

// Standard monthly calculation.
$r = PayrollEngine::calculate(['basic_salary' => 3000, 'overtime' => 200, 'allowances' => 100,
    'other_earnings' => 50, 'deductions' => 50, 'pay_frequency' => 'Monthly', 'is_resident' => true]);
check('gross', $r['gross_salary'] == 3350);
check('employee pension 8%', near($r['employee_pension'], 268.00));
check('employer pension 8%', near($r['employer_pension'], 268.00));
check('legacy alias equals neutral', $r['employee_fnpf'] == $r['employee_pension'] && $r['paye_tax'] == $r['income_tax']);
check('net = gross - pension - tax - deductions', near($r['net_salary'], 3350 - 268 - $r['income_tax'] - 50));
check('employer cost includes levies', near($r['employer_cost'], 3350 + 268 + $r['employer_levies_total']));

// Custom rules fully replace the preset: 5%/10% pension, one 10% band over 20k, no levies.
$rules = ['pension' => ['employee_rate' => 0.05, 'employer_rate' => 0.10],
    'resident_bands' => [['up_to' => 20000, 'rate' => 0], ['up_to' => null, 'rate' => 0.10]],
    'employer_levies' => []];
check('custom bands', near(PayrollEngine::tax_lookup(50000, true, 'P', PayrollEngine::normalize_rules($rules)), 3000.00));
$c = PayrollEngine::calculate(['basic_salary' => 1000, 'rules' => $rules]);
check('custom employee pension', near($c['employee_pension'], 50.00));
check('custom employer pension', near($c['employer_pension'], 100.00));
check('no levies', $c['employer_levies_total'] == 0.0 && $c['workcare_levy'] == 0.0);
check('employer cost custom', near($c['employer_cost'], 1100.00));

// Invalid rules fall back to the preset instead of corrupting payroll.
$bad = PayrollEngine::normalize_rules(['pension' => ['employee_rate' => 5], 'resident_bands' => [['up_to' => 10, 'rate' => 0.1]]]);
check('rate > 1 rejected', $bad['pension']['employee_rate'] == 0.08);
check('bands without open-ended top rejected', count($bad['resident_bands']) === 10);
$bad2 = PayrollEngine::normalize_rules(['resident_bands' => [['up_to' => 50, 'rate' => 0.1], ['up_to' => 40, 'rate' => 0.2], ['up_to' => null, 'rate' => 0.3]]]);
check('non-increasing bands rejected', count($bad2['resident_bands']) === 10);

// Redundancy exemption is rule-driven.
$red = PayrollEngine::calculate_cumulative_tax(['regular_pay' => 0, 'redundancy_pay' => 20000]);
check('redundancy excess taxed', near($red['paye_redundancy'], 750.00));
$red2 = PayrollEngine::calculate_cumulative_tax(['regular_pay' => 0, 'redundancy_pay' => 20000],
    PayrollEngine::normalize_rules(['redundancy' => ['exempt_up_to' => 5000, 'rate' => 0.10]]));
check('custom redundancy rule', near($red2['paye_redundancy'], 1500.00));

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll engine tests passed\n";
exit($fail ? 1 : 0);
