<?php
/**
 * Nuvis ERPX — Auto-reversing journals.
 *
 * Posts the reversal of every manual journal whose “Reverse on” date has arrived, for every active company.
 * Run daily, after midnight:
 *
 *     15 0 * * * php /path/to/nuviserpx/cron/auto_reverse_journals.php >> /var/log/erpx_autoreverse.log 2>&1
 *
 * Safe to run more than once a day or to re-run after a failure: a journal is only reversed once, and one
 * that cannot be reversed (for example, its period is locked) is reported and tried again next run.
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
    fwrite(STDERR, "[auto_reverse] No database connection.\n");
    exit(1);
}

$today = date('Y-m-d');
$tenants = $conn->query("SELECT id FROM tenants WHERE delete_flag = 0 AND status = 1");
$exit = 0;
foreach ($tenants ? $tenants->fetch_all(MYSQLI_ASSOC) : [] as $t) {
    // Act as the system for this company: no user, developer tier so the company switch is honoured.
    $_SESSION['userdata'] = ['id' => 0, 'type' => 1, 'tenant_id' => (int)$t['id'], 'active_tenant_id' => (int)$t['id']];
    $res = (new \App\Services\AutoReversalService($conn, $_settings))->run($today);
    foreach ($res['done'] as $code => $rev) {
        echo "[auto_reverse] tenant {$t['id']}: {$code} reversed by {$rev}\n";
    }
    foreach ($res['failed'] as $code => $why) {
        echo "[auto_reverse] tenant {$t['id']}: {$code} NOT reversed: {$why}\n";
        $exit = 2;
    }
}
exit($exit);
