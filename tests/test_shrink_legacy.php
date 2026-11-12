<?php
// Master and DBConnection shrink: moved code is complete and delegated.
$root = dirname(__DIR__);
$db = file_get_contents($root . '/classes/DBConnection.php');
$ms = file_get_contents($root . '/classes/Master.php');
$lc = file_get_contents($root . '/src/Database/LegacySchemaCatchup.php');
$so = file_get_contents($root . '/src/Services/SalesOrderService.php');
$cp = file_get_contents($root . '/src/Services/CustomerPortalService.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('DBConnection is small again (under 10 KB, was 128 KB)', strlen($db) < 10240);
check('DBConnection runs the catch-up through the new class', strpos($db, '(new \App\Database\LegacySchemaCatchup($this->conn))->run();') !== false && strpos($db, 'function check_') === false);
check('all 44 checks moved and all 44 run', preg_match_all('/function (check_\w+_migrations)\(/', $lc, $d) === 44 && preg_match_all('/\$this->check_\w+_migrations\(\);/', $lc) === 44);
check('catch-up helpers moved with their checks', strpos($lc, 'function table_exists') !== false && strpos($lc, 'function once_a_day') !== false && strpos($lc, 'function add_tenant_fk') !== false && strpos($lc, 'function account_type_sql_list') !== false);
check('account_type_sql_list proxy kept for callers', strpos($db, 'function account_type_sql_list') !== false);

check('Master lost the sales-order bodies but keeps the entry points', strpos($ms, 'SalesOrderService::class)->save_sales_order()') !== false && strpos($ms, 'function confirm_sales_order_internal') === false);
check('sales-order logic lives in the service with its error state', strpos($so, 'function confirm_sales_order_internal') !== false && strpos($so, 'private string $confirm_error') !== false);
check('Master lost the portal bodies but keeps the entry points', strpos($ms, 'CustomerPortalService::class)->customer_login()') !== false && strpos($ms, "INSERT INTO `customer_portal_logs`") === false);
check('portal logic lives in the service', strpos($cp, 'function log_portal_activity') !== false && strpos($cp, 'function customer_register') !== false && strpos($cp, 'function get_portal_logs') !== false);
check('save_invoice_template audit call no longer fatals', strpos($ms, '$this->settings->log_audit("save_invoice_template"') !== false && strpos($ms, '$this->log_audit("save_invoice_template"') === false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
