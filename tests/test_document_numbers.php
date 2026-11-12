<?php
// Document numbers come from the locked per-company series, never from rand().
$root = dirname(__DIR__);
$read = fn($f) => file_get_contents("$root/$f");
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$master = ($read('classes/Master.php') . $read('src/Services/SalesOrderService.php'));
$purch = $read('src/Services/PurchaseService.php');
$wf = $read('src/Services/WorkflowService.php');
check('no rand() in document numbers in Master', !preg_match("/(TCK|CTR|EXP|SO)-'[^;]*rand\\(/", $master));
$expSvc = $read('src/Services/ExpenseClaimService.php');
check('Master numbers tickets, contracts, orders from the series', substr_count($master, 'SequenceService::documentNo') >= 3);
check('claims are numbered from the series in ExpenseClaimService', strpos($expSvc, "SequenceService::documentNo(\$this->conn, \$t, 'expense_reports', 'claim_no', 'EXP-'") !== false);
check('workflow tickets use the same ticket series', strpos($wf, "'ticket-' . date('Ym')") !== false && strpos($wf, 'random_int(1000, 9999)') === false);
check('purchasing numbers come from the locked series', strpos($purch, 'SequenceService::documentNo') !== false && strpos($purch, 'ORDER BY `{$column}` DESC LIMIT 1') === false);
check('typed PO / bill / order numbers are checked for duplicates', substr_count($purch, 'SequenceService::numberTaken') >= 2 && strpos($master, 'SequenceService::numberTaken') !== false);
check('sales-order invoices are numbered from the invoice series', strpos($master, "'invoice_no'   => ''") !== false && strpos($master, "'INV-' . str_replace(['SO-'") === false);
foreach (['admin/purchases/manage_po.php', 'admin/purchases/manage_bill.php', 'admin/sales_orders/manage_order.php'] as $f) {
    $s = $read($f);
    check("$f no longer pre-fills a random number", strpos($s, 'rand(1000, 9999)') === false && strpos($s, 'placeholder="Automatic"') !== false);
}
$mig = $read('database/migrations/025_document_number_uniqueness.sql');
foreach (['sales_order_list', 'expense_reports', 'contracts', 'tickets'] as $t) {
    check("unique number per company on $t", strpos($mig, "ALTER TABLE `$t` ADD UNIQUE INDEX") !== false);
}
check('migration comments have no semicolons', !preg_match('/^--.*;/m', $mig));

// The pure numbering helpers
require_once "$root/src/Services/SequenceService.php";
use App\Services\SequenceService as S;
check('document number format', S::formatDoc('PO-202611-', 7) === 'PO-202611-0007' && S::formatDoc('PO-202611-', 12345) === 'PO-202611-12345');
exit($fail ? 1 : 0);
