<?php
// Clone invoice is wired in; the dead Modules Manager is gone and nothing refers to it.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$form = file_get_contents("$root/admin/invoices/manage_invoice.php");
check('clone starts a new invoice from the original, scoped to the company', strpos($form, "\$_GET['clone']") !== false && strpos($form, "tenant_id = '{\$tenant_id}' LIMIT 1") !== false && strpos($form, '$clone_of = (int)$src') !== false);
check('a clone gets today\'s date, the same terms and no number or id', strpos($form, "\$invoice_date = date('Y-m-d');") !== false && strpos($form, '$terms_days') !== false && strpos($form, '$clone_no') !== false);
check('clone copies the lines of the original', strpos($form, '(isset($id) ? $id : $clone_of)') !== false);
check('clone button on the list and the invoice page', strpos(file_get_contents("$root/admin/invoices/index.php"), 'manage_invoice&clone=') !== false && strpos(file_get_contents("$root/admin/invoices/view.php"), 'manage_invoice&clone=') !== false);
check('the Modules Manager page is gone', !is_file("$root/admin/system_info/modules.php"));
$refs = shell_exec('grep -rIl "system_info/modules\|save_module_settings" ' . escapeshellarg($root) . '/admin ' . escapeshellarg($root) . '/classes ' . escapeshellarg($root) . '/src 2>/dev/null');
check('nothing refers to the Modules Manager or its save action', trim((string)$refs) === '');
check('the per-company Modules & Features overview stays', is_file("$root/admin/settings/modules.php"));
check('account queries were not given item columns', strpos($form, 'FROM `account_list` WHERE tenant_id = \'{$tenant_id}\' AND delete_flag = 0 AND status = 1 ORDER BY') !== false);
exit($fail ? 1 : 0);
