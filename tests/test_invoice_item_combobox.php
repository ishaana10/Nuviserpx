<?php
// Invoice line items: one combobox that searches products or takes a typed
// custom item, replacing the stacked select + text input.
$root = dirname(__DIR__);
$page = file_get_contents($root . '/admin/invoices/manage_invoice.php');
$master = file_get_contents($root . '/classes/Master.php');

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

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
