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

// Tranche 2: eight more domains out of Master, delegates in place.
$t2 = [
    'FixedAssetService' => ['save_asset', 'delete_asset'],
    'ContractService' => ['save_contract', 'save_proposal_signature'],
    'HelpdeskService' => ['save_ticket', 'reply_ticket'],
    'CurrencyService' => ['save_currency', 'delete_currency', 'save_bank_account'],
    'FiscalizationService' => ['save_tenant_vms_settings', 'test_sdc_ping', 'simulate_vms_fiscalization', 'upload_tenant_vsdc_cert'],
    'MaintenanceService' => ['import_csv', 'backup_db', 'optimize_db'],
    'ExtrafieldService' => ['save_extrafield', 'delete_extrafield'],
    'ShiftRosterService' => ['save_shift_type', 'save_shift_assignment'],
];
foreach ($t2 as $svc => $fns) {
    $s = file_get_contents($root . "/src/Services/{$svc}.php");
    $ok = true;
    foreach ($fns as $fn) {
        $ok = $ok && strpos($s, "function {$fn}") !== false;
        if ($fn !== 'import_csv') {
            $ok = $ok && strpos($ms, "{$svc}::class)->{$fn}()") !== false
                && substr_count($ms, "function {$fn}(") === 1;
        }
    }
    check("{$svc}: bodies moved, Master delegates", $ok);
}
check('CSV import wrappers call the service', substr_count($ms, "MaintenanceService::class)->import_csv('") === 3);
check('Master is under 75 KB (was 132 KB)', strlen($ms) < 76800);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
