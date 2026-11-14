<?php
// Slice 3 of the UX polish: Invoices list (empty state, linked number, overdue hint).
$root = dirname(__DIR__);
$f = file_get_contents($root . '/admin/invoices/index.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('query runs before the table so an empty state can replace it', strpos($f, '$qry = $conn->query') < strpos($f, '<table'));
check('empty state with a create button when there are no invoices', strpos($f, 'No invoices yet') !== false && strpos($f, 'nv-empty') !== false && strpos($f, '!$nv_has_rows') !== false);
check('table only rendered when rows exist', (bool)preg_match('/<\?php else: \?>\s*<table.*<\/table>\s*<\?php endif; \?>/s', $f));
check('DataTable init is guarded when the table is absent', strpos($f, "if(\$('#list').length)") !== false);
check('invoice number links to the invoice and is escaped', (bool)preg_match('/<a href="\.\/\?page=invoices\/view&id=<\?php echo \(int\)\$row\[\'id\'\] \?>"><b><\?php echo htmlspecialchars\(\$row\[\'invoice_no\'\]\)/', $f));
check('overdue only for unpaid/partially paid with a balance', strpos($f, "in_array(\$row['status'], ['unpaid','partially_paid'], true)") !== false && strpos($f, "\$row['balance'] > 0") !== false);
check('overdue compares due date with today', strpos($f, "new DateTime('today')") !== false && strpos($f, '$nv_due < $nv_today') !== false);
check('overdue label pluralises days', strpos($f, "=== 1 ? '' : 's'") !== false);
check('card mode from slice 1 retained', strpos($f, 'data-nv-cards') !== false);
exit($fail ? 1 : 0);
