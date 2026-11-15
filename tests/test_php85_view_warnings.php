<?php
// Pages that used to raise PHP warnings/deprecations on missing data must keep guarding it.
$fail = 0;
function check($n, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $n\n"; if (!$ok) { $fail++; } }
$root = __DIR__ . '/..';
$slip = file_get_contents("$root/admin/payroll/view_payslip.php");
check('payslip defaults a missing pay_date before any strtotime()', preg_match('/empty\(\$pay_date\)\)\s*\{\s*\$pay_date\s*=\s*date\(/', $slip) === 1
    && strpos($slip, 'empty($pay_date)') < strpos($slip, 'strtotime($pay_date)'));
check('payslip answers 404 when the payslip does not exist', strpos($slip, 'Payslip not found') !== false);
$reg = file_get_contents("$root/portal/register.php");
check('portal activation does not read a non-existent users.name column', strpos($reg, "\$user['name']") === false);
check('portal activation shows the company name read-only', strpos($reg, "\$user['party_name']") !== false && strpos($reg, 'readonly') !== false);
exit($fail ? 1 : 0);
