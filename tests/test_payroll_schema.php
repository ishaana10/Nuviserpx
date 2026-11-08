<?php
// Payroll schema must not depend on on-load ALTERs: everything DBConnection adds at runtime has to be in
// migration 018 (and the payroll_list / employee_list columns in the base schema too).
$root = dirname(__DIR__);
$db   = file_get_contents("$root/classes/DBConnection.php");
$mig  = file_get_contents("$root/database/migrations/018_payroll_schema.sql");
$base = file_get_contents("$root/database/nuviserpx.sql");
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$tables = ['employee_list', 'payroll_list', 'payroll_remittances', 'payroll_component_items'];
preg_match_all('/"ALTER TABLE `(\w+)` ADD COLUMN `(\w+)`/', $db, $m, PREG_SET_ORDER);
$runtime = [];
foreach ($m as [, $t, $c]) { if (in_array($t, $tables, true)) { $runtime[$t][$c] = true; } }
check('found the runtime payroll columns', array_sum(array_map('count', $runtime)) >= 55);

$missingMig = [];
foreach ($runtime as $t => $cols) {
    foreach (array_keys($cols) as $c) {
        if ($t === 'payroll_list' && $c === 'status') { continue; } // converted by MODIFY below
        if (!preg_match('/ALTER TABLE `' . $t . '` ADD COLUMN `' . $c . '`/', $mig)) { $missingMig[] = "$t.$c"; }
    }
}
check('every runtime payroll column is in migration 018' . ($missingMig ? ' (missing: ' . implode(', ', $missingMig) . ')' : ''), !$missingMig);

preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`/', $db, $ct);
$payrollTables = array_filter(array_unique($ct[1]), fn($t) => preg_match('/^(payroll_|salary_components|employee_salary_components|employee_loans|loan_repayments)/', $t));
$missingT = array_filter($payrollTables, fn($t) => strpos($mig, "CREATE TABLE IF NOT EXISTS `$t`") === false);
check('every runtime payroll table is in migration 018' . ($missingT ? ' (missing: ' . implode(', ', $missingT) . ')' : ''), !$missingT && count($payrollTables) >= 10);

check('payroll_list.status becomes the enum with voided', strpos($mig, "MODIFY `status` enum('posted','pending_approval','rejected','voided')") !== false);
check('payroll_remittances.status enum is in the migration', strpos($mig, "ALTER TABLE `payroll_remittances` MODIFY `status` enum(") !== false);
check('run reference counter table is in the migration', strpos($mig, 'CREATE TABLE IF NOT EXISTS `payroll_run_counters`') !== false);

// Base schema
foreach (['payroll_list', 'employee_list'] as $t) {
    $a = strpos($base, "CREATE TABLE `$t` (");
    $blk = substr($base, $a, strpos($base, 'ENGINE=InnoDB', $a) - $a);
    $miss = [];
    foreach (array_keys($runtime[$t]) as $c) { if (strpos($blk, "`$c`") === false) { $miss[] = $c; } }
    check("base schema has every $t column the code uses" . ($miss ? ' (missing: ' . implode(', ', $miss) . ')' : ''), !$miss);
}
$a = strpos($base, 'CREATE TABLE `payroll_list` (');
$blk = substr($base, $a, strpos($base, 'ENGINE=InnoDB', $a) - $a);
check('base payroll_list.status is the enum, not a tinyint', strpos($blk, "`status` enum('posted','pending_approval','rejected','voided')") !== false && strpos($blk, '`status` tinyint') === false);
check('base payroll_list money columns are DECIMAL(18,4)', strpos($blk, 'decimal(12,2)') === false);

// The runner splits on ';' and swallows errors per statement, so the file must not contain a ';' inside a comment or string
$noComments = preg_replace('/^--.*$/m', '', $mig);
check('no semicolons in comments', substr_count($mig, ';') === substr_count($noComments, ';'));
check('no AFTER clauses (they fail when the anchor column is missing)', stripos($mig, ' AFTER `') === false);

echo $fail ? "\n$fail checks FAILED\n" : "\nAll payroll schema checks passed\n";
exit($fail ? 1 : 0);
