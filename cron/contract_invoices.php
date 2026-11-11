<?php
/**
 * Nuvis ERPX - Recurring contract invoices.
 *
 * Raises the invoice for every billing period that has come due on an active contract. Safe to run more than once a
 * day: each contract period is billed once. Run daily:
 *
 *     0 5 * * * php /path/to/nuviserpx/cron/contract_invoices.php >> /var/log/erpx_contracts.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once(dirname(__DIR__) . '/initialize.php');
require_once(dirname(__DIR__) . '/classes/DBConnection.php');
require_once(dirname(__DIR__) . '/classes/SystemSettings.php');

$conn = $_settings->conn;
if (!$conn) {
    fwrite(STDERR, "[contract_invoices] No database connection.\n");
    exit(1);
}

$today = date('Y-m-d');
$tenants = $conn->query("SELECT id FROM tenants WHERE delete_flag = 0 AND status = 1");
$exit = 0;
foreach ($tenants ? $tenants->fetch_all(MYSQLI_ASSOC) : [] as $t) {
    $_SESSION['userdata'] = ['id' => 0, 'type' => 1, 'tenant_id' => (int)$t['id'], 'active_tenant_id' => (int)$t['id']];
    $res = (new \App\Services\ContractBillingService($conn, $_settings))->run($today);
    foreach ($res['made'] as [$cno, $ino, $p]) {
        echo "[contract_invoices] tenant {$t['id']}: {$cno} period {$p} -> {$ino}\n";
    }
    foreach ($res['problems'] as [$cno, $why]) {
        echo "[contract_invoices] tenant {$t['id']}: {$cno} NOT billed: {$why}\n";
        $exit = 1;
    }
}
exit($exit);
