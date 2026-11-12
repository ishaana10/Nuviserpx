<?php
// Invoice line items: one combobox that searches products or takes a typed
// custom item, replacing the stacked select + text input.
$root = dirname(__DIR__);
$page = file_get_contents($root . '/admin/invoices/manage_invoice.php');
$master = file_get_contents($root . '/src/Services/SalesOrderService.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('stacked "-- Custom Item --" option is gone', strpos($page, '-- Custom Item --') === false);
check('every line select becomes a tag-enabled combobox', strpos($page, 'function init_item_select') !== false && strpos($page, 'tags: true') !== false);
check('typed names post a non-numeric product id', strpos($page, "{ id: 'custom:' + t, text: t, nv_custom: true }") !== false);
check('item_name input is hidden but kept in sync', strpos($page, "find('.item-name').addClass('d-none').prop('required', false)") !== false);
check('saved custom lines show their name on edit', strpos($page, "new Option(name, 'custom:' + name, true, true)") !== false);
check('new rows are initialised too', (bool)preg_match('/append\(row\);\s*init_item_select\(\);/', $page));
check('quick-product save refreshes the combobox', strpos($page, "sel.val(new_id).trigger('change.select2')") !== false);
check('price-list lookup only sends real product ids', strpos($page, "/^\\d+\$/.test(pid)") !== false);
check('submit blocks lines without an item', strpos($page, 'Choose an item, or type a name, on every line.') !== false);
check('server stores non-numeric product ids as NULL', (bool)preg_match('/is_numeric\(\$product_id\[\$k\]\)\s*\?\s*\(int\)\$product_id\[\$k\]\s*:\s*\'NULL\'/', $master));

// Regression: the extract() removal must bind every item array the totals
// and line writer read, or every line computes as qty 1 x price 0.
$inv = file_get_contents($root . '/src/Services/InvoiceService.php');
$fnStart = strpos($inv, 'function saveInvoice');
$fn = substr($inv, $fnStart, strpos($inv, 'function ', $fnStart + 10) - $fnStart);
preg_match_all('/\$(item_\w+)/', $fn, $u);
preg_match_all('/\$(item_\w+)\s*=(?!=)/', $fn, $a);
$missing = array_diff(array_unique($u[1]), array_unique($a[1]));
check('every item_* array saveInvoice reads is bound from the post', $missing === []);
check('line amounts come from the posted qty, price, discount and tax', strpos($fn, "\$item_qty = \$post['item_qty']") !== false && strpos($fn, "\$item_price = \$post['item_price']") !== false && strpos($fn, "\$item_discount_pct = \$post['item_discount_pct']") !== false && strpos($fn, "\$item_tax_rate = \$post['item_tax_rate']") !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
