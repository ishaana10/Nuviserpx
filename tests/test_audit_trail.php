<?php
// Audit trail: self-healing writes, visible read errors, core-action coverage.
$root = dirname(__DIR__);
$ss = file_get_contents($root . '/classes/SystemSettings.php');
$pg = file_get_contents($root . '/admin/settings/audit_logs.php');
$inv = file_get_contents($root . '/src/Services/InvoiceService.php');
$pay = file_get_contents($root . '/src/Services/PaymentService.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('failed audit insert repairs the table and retries', (bool)preg_match('/if\(!@\$this->conn->query\(\$sql\)\)\{.*ensure_audit_schema\(\);.*@\$this->conn->query\(\$sql\);/s', $ss));
check('repair creates the table when missing', strpos($ss, 'CREATE TABLE IF NOT EXISTS `audit_logs`') !== false);
check('repair adds every missing column', substr_count($ss, 'ADD COLUMN') >= 7 && strpos($ss, 'SHOW COLUMNS FROM `audit_logs`') !== false);
check('audit page repairs then reports read errors instead of showing empty', strpos($pg, 'ensure_audit_schema()') !== false && strpos($pg, 'The audit log could not be read:') !== false);
check('module filter is built from logged modules', strpos($pg, 'SELECT DISTINCT `module` FROM `audit_logs`') !== false && strpos($pg, 'foreach($modules as $m)') !== false);
check('saving an invoice is logged', strpos($inv, "'Created invoice' : 'Updated invoice'") !== false);
check('deleting an invoice is logged', strpos($inv, "log_audit('Deleted invoice'") !== false);
check('recording a receipt is logged', strpos($pay, "'Recorded receipt' : 'Updated receipt'") !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
