<?php
// Pay is set as annual gross; per-period basics derive from it by frequency.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$mig = (string)@file_get_contents($root.'/database/migrations/041_annual_salary.sql');
$es = file_get_contents($root.'/src/Services/EmployeeService.php');
$mf = file_get_contents($root.'/admin/employees/manage_employee.php');
$mp = file_get_contents($root.'/admin/payroll/manage_payroll.php');
$ps = file_get_contents($root.'/src/Services/PayrollService.php');

check('migration adds annual_salary and backfills monthly x12', strpos($mig, '`annual_salary`') !== false && strpos($mig, '`salary` * 12') !== false && strpos(file_get_contents($root.'/src/Database/LegacySchemaCatchup.php'), "LIKE 'annual_salary'") !== false);
check('employee form captures annual gross with live equivalents', strpos($mf, 'Annual Gross Salary') !== false && strpos($mf, 'fortnightly') !== false && strpos($mf, 'name="annual_salary"') !== false);
check('saving annual keeps the derived monthly in step', strpos($es, "\$post['salary'] = round(\$post['annual_salary'] / 12, 2);") !== false && strpos($es, "'annual_salary',") !== false);
check('batch run derives each basic from annual at the run frequency', strpos($mp, 'data-annual') !== false && strpos($mp, "annual / nv_periods") !== false && strpos($mp, "#batch-payroll-form .emp-basic") !== false);
check('frequency map matches the engine (26/52/24/12)', strpos($mp, "case 'fortnightly': return 26") !== false && strpos($mp, "case 'weekly': return 52") !== false);
check('single run re-derives on employee pick and frequency change', strpos($mp, "#payroll-form #pay_frequency") !== false && substr_count($mp, 'nv_periods(') >= 3);
check('engine fallback uses annual / periods per year', strpos($ps, 'PayrollEngine::get_periods_per_year($pay_frequency)') !== false && strpos($ps, "\$nv_annual / max(1,") !== false);
check('employee list shows the annual figure', strpos(file_get_contents($root.'/admin/employees/index.php'), 'Annual Gross') !== false);
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
