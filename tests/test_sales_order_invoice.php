<?php
define('base_app', dirname(__DIR__) . '/');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

$m = file_get_contents(base_app . 'src/Services/SalesOrderService.php');
$a = strpos($m, 'public function confirm_sales_order_internal');
$b = strpos($m, 'public function confirm_sales_order()');
$fn = substr($m, $a, $b - $a);

check('confirmation raises the invoice through InvoiceService', str_contains($fn, 'InvoiceService::class') && str_contains($fn, '->saveInvoice($post)'));
check('no hand-built invoice or journal is left in the order path', !str_contains($fn, 'INSERT INTO `invoice_list`') && !str_contains($fn, 'INSERT INTO `journal_entries`') && !str_contains($fn, 'INSERT INTO `journal_items`'));
check('no account is guessed by name in the order path', !str_contains($fn, 'LIKE \'%receivable%\''));
check('the period lock is checked before anything is written', strpos($fn, 'PeriodLockService::guard') < strpos($fn, 'saveInvoice'));
check('the invoice is raised before stock is deducted or the order is flagged', strpos($fn, 'saveInvoice') < strpos($fn, 'UPDATE `product_list`') && strpos($fn, 'saveInvoice') < strpos($fn, 'UPDATE `sales_order_list`'));
check('a refused invoice stops the confirmation with its reason', str_contains($fn, '$this->confirm_error = (string)($res[\'msg\']') && str_contains($fn, 'return false;'));
check('units-only deduction only runs when inventory accounting is off (or the invoice already existed)', str_contains($fn, 'if (!$inventoryOn || $hadInvoice)'));
check('order lines (with product) are passed to the invoice', str_contains($fn, "\$post['product_id'][]") && str_contains($fn, "\$post['item_tax_rate'][]"));
check('the order warehouse is passed so stock leaves the right location', str_contains($fn, "'stock_warehouse_id' => \$wh"));
check('re-confirming a confirmed order does nothing', str_contains($fn, "if (\$order['stock_adjusted'] != 0) {\n            return true;"));

$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('InvoiceService returns the new invoice id', str_contains($inv, "\$resp['id'] = (int)\$invoice_id;"));
check('InvoiceService stores the stock warehouse', str_contains($inv, "`stock_warehouse_id` = '{\$held['warehouse']}'"));
check('VAT is split by InvoiceService (the path orders now use)', str_contains($inv, 'TaxService::postingLines(\'sale\''));

echo $fail ? "\n$fail FAILED\n" : "\nAll sales order invoice tests passed\n";
exit($fail ? 1 : 0);
