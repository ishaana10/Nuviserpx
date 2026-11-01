<?php
/**
 * Nuvis ERPX — Webhook delivery worker.
 *
 * Drains the `webhook_deliveries` queue: delivers pending rows, applies retries with
 * exponential backoff, and records outcomes. Designed to run from cron, e.g.:
 *
 *     * * * * * php /path/to/nuviserpx/cron/webhook_worker.php >> /var/log/erpx_webhooks.log 2>&1
 *
 * Safe to run every minute; each run processes up to --limit rows (default 100) and
 * exits. Parallel runs are safe (rows are claimed atomically before sending).
 *
 * CLI flags:
 *   --limit=N   max deliveries to process this run (default 100, max 500)
 *   --loop=S    keep draining for S seconds before exiting (default 0 = single pass)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once(dirname(__DIR__) . '/initialize.php');
require_once(dirname(__DIR__) . '/classes/DBConnection.php');

use App\Services\WebhookService;

// Parse flags
$opts = getopt('', ['limit::', 'loop::']);
$limit = isset($opts['limit']) ? max(1, min(500, (int)$opts['limit'])) : 100;
$loopSeconds = isset($opts['loop']) ? max(0, (int)$opts['loop']) : 0;

$db = new DBConnection();
$conn = $db->conn;
if (!$conn) {
    fwrite(STDERR, "[webhook_worker] No database connection.\n");
    exit(1);
}

$service = new WebhookService($conn);
$started = time();
$totals = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'retrying' => 0];

do {
    $stats = $service->drainQueue($limit);
    foreach ($stats as $k => $v) {
        $totals[$k] = ($totals[$k] ?? 0) + $v;
    }

    if ($loopSeconds > 0 && (time() - $started) < $loopSeconds) {
        if ($stats['processed'] === 0) {
            sleep(2); // idle backoff
        }
    } else {
        break;
    }
} while (true);

printf(
    "[%s] webhook_worker: processed=%d delivered=%d retrying=%d failed=%d\n",
    date('Y-m-d H:i:s'),
    $totals['processed'],
    $totals['delivered'],
    $totals['retrying'],
    $totals['failed']
);

exit(0);
