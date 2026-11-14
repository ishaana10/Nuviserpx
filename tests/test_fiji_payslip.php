<?php
// The payslip captures what a Fiji PayGlobal-style slip shows: named FNPF and
// P.A.Y.E. deductions with the tax code, SRT when levied, YTD balances for
// the tax year, FNPF superannuation (employee + employer), leave balances,
// employer levies by name, and the net pay in words.
$root = dirname(__DIR__);
$s = file_get_contents($root . '/admin/payroll/view_payslip.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('deductions are itemised with Fiji names', strpos($s, 'FNPF Employee Contribution') !== false && strpos($s, 'P.A.Y.E. (Tax Code') !== false && strpos($s, 'Social Responsibility Tax (SRT)') !== false);
check('SRT and other-deduction lines only show when non-zero', strpos($s, "(float)\$srt_tax != 0") !== false && strpos($s, "\$nv_other_ded > 0") !== false);
check('YTD balances cover the calendar tax year up to this pay', strpos($s, "date('Y-01-01', strtotime(\$pay_date))") !== false && strpos($s, "pay_date BETWEEN ? AND ?") !== false && strpos($s, "NOT IN ('rejected','voided')") !== false);
check('YTD box shows gross, PAYE, deductions and net', strpos($s, 'Year-to-Date Balances') !== false && strpos($s, "\$nv_ytd['gross']") !== false && strpos($s, "\$nv_ytd['net']") !== false);
check('superannuation shows employee and employer FNPF YTD', strpos($s, 'Superannuation (FNPF, YTD)') !== false && strpos($s, "\$nv_ytd['er_fnpf']") !== false);
check('leave balances come from LeaveBalanceService at the pay date', strpos($s, 'LeaveBalanceService($conn, $_settings))->forEmployee($nv_eid, $pay_date)') !== false && strpos($s, 'Leave Balances') !== false);
check('net pay is written in words', strpos($s, 'nv_amount_in_words((float)$net_salary)') !== false && strpos($s, 'NumberFormatter') !== false);
check('employer levies carry their names incl. training levy', strpos($s, 'FNPF Employer Contribution') !== false && strpos($s, 'Workcare Levy') !== false && strpos($s, 'Training Levy (FNU)') !== false);
check('pay period start-end and frequency shown', strpos($s, 'Pay Period:') !== false && strpos($s, "\$period_start") !== false);
check('header carries the company branding and logo', strpos($s, "\$nv_brand['logo']") !== false && strpos($s, "\$nv_brand['address']") !== false);
check('non-taxable components are marked', strpos($s, 'non-taxable') !== false);
check('YTD query is prepared and tenant-scoped', strpos($s, 'tenant_id = ? AND employee_id = ?') !== false);
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
