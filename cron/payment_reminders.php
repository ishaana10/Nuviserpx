<?php
/**
 * Nuvis ERPX - Payment reminders and late fees.
 *
 * For every company that has switched reminders or late fees on, emails customers whose invoices are overdue and raises
 * late fees. Safe to run more than once a day: each reminder stage and each late fee happens once per invoice. Run daily:
 *
 *     0 7 * * * php /path/to/nuviserpx/cron/payment_reminders.php >> /var/log/erpx_reminders.log 2>&1
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
    fwrite(STDERR, "[payment_reminders] No database connection.\n");
    exit(1);
}

$today = date('Y-m-d');
$tenants = $conn->query("SELECT t.id FROM tenants t INNER JOIN reminder_settings r ON r.tenant_id = t.id WHERE t.delete_flag = 0 AND t.status = 1 AND (r.enabled = 1 OR r.fee_enabled = 1)");
$exit = 0;
foreach ($tenants ? $tenants->fetch_all(MYSQLI_ASSOC) : [] as $t) {
    $_SESSION['userdata'] = ['id' => 0, 'type' => 1, 'tenant_id' => (int)$t['id'], 'active_tenant_id' => (int)$t['id']];
    $res = (new \App\Services\PaymentReminderService($conn, $_settings))->run($today);
    foreach ($res['sent'] as [$no, $who, $stage]) {
        echo "[payment_reminders] tenant {$t['id']}: reminder ({$stage}d) invoice {$no} to {$who}\n";
    }
    foreach ($res['fees'] as [$no, $feeNo, $amt]) {
        echo "[payment_reminders] tenant {$t['id']}: late fee {$amt} on {$no} -> {$feeNo}\n";
    }
    foreach ($res['skipped_no_email'] as [$no, $who]) {
        echo "[payment_reminders] tenant {$t['id']}: invoice {$no} ({$who}) has no valid email\n";
    }
    foreach ($res['problems'] as $why) {
        echo "[payment_reminders] tenant {$t['id']}: PROBLEM {$why}\n";
        $exit = 1;
    }
}
exit($exit);
