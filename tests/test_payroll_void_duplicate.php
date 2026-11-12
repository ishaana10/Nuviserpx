<?php
// Guards: posted payroll is voided (journal reversed, never deleted) and one payroll per employee per month.
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
$svc = file_get_contents(__DIR__ . '/../src/Services/PayrollService.php');
$db  = file_get_contents(__DIR__ . '/../src/Database/LegacySchemaCatchup.php');
$ui  = file_get_contents(__DIR__ . '/../admin/payroll/index.php');

$i = strpos($svc, 'public function deletePayroll');
$j = strpos($svc, 'public function emailPayslip');
$del = substr($svc, $i, $j - $i);
$posted = substr($del, 0, strpos($del, 'Never reached the ledger'));

check('posted payroll reverses its journal', strpos($posted, 'reverseJournal(') !== false);
check('posted branch never deletes the journal or the payroll', stripos($posted, 'DELETE FROM') === false);
check("posted payroll is marked 'voided'", strpos($posted, "status = 'voided'") !== false);
check('paid payroll cannot be voided', strpos($del, "=== 'paid'") !== false);
check('already-remitted liabilities block a void', strpos($posted, 'remittedAgainst(') !== false);
check('adjustments released on void', strpos($posted, 'release($id)') !== false);

check('single save has a duplicate-month guard', strpos($svc, 'already has payroll for') !== false);
check('duplicate guard ignores the record being edited', strpos($svc, "AND id <> '{\$id}' LIMIT 1") !== false);
check('voided payroll cannot be edited', strpos($svc, 'was voided and cannot be edited') !== false);
check('voided payroll excluded from year-to-date', substr_count($svc, "status NOT IN ('rejected','voided')") >= 4);

check("status enum migration allows 'voided'", strpos($db, "enum('posted','pending_approval','rejected','voided')") !== false);
check('list shows Void for posted payroll', strpos($ui, "'Void'") !== false);

echo $fail ? "\n$fail payroll void/duplicate checks FAILED\n" : "\nAll payroll void/duplicate checks passed\n";
exit($fail ? 1 : 0);
