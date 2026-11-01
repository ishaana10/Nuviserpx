<?php
/**
 * Nuvis ERPX — Overdue invoice sweep.
 *
 * Finds unpaid / partially-paid invoices whose due_date has passed and fires the
 * `invoice.overdue` event once per invoice per day (webhooks + workflows). Run daily:
 *
 *     0 6 * * * php /path/to/nuviserpx/cron/invoice_overdue_sweep.php >> /var/log/erpx_overdue.log 2>&1
 *
 * De-duplication: an invoice is only emitted once per calendar day, tracked via the
 * `workflow_logs` / `webhook_deliveries` context — here we rely on a lightweight guard
 * table `invoice_overdue_marks` to avoid repeat firing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once(dirname(__DIR__) . '/initialize.php');
require_once(dirname(__DIR__) . '/classes/DBConnection.php');
require_once(dirname(__DIR__) . '/classes/SystemSettings.php');
require_once(dirname(__DIR__) . '/classes/ModuleManager.php');

use App\Support\IntegrationEvents;

$db = new DBConnection();
$conn = $db->conn;
if (!$conn) {
    fwrite(STDERR, "[overdue_sweep] No database connection.\n");
    exit(1);
}

// Guard table so we only emit once per invoice per day.
$conn->query("CREATE TABLE IF NOT EXISTS `invoice_overdue_marks` (
    `invoice_id` int(30) NOT NULL,
    `marked_on` date NOT NULL,
    PRIMARY KEY (`invoice_id`, `marked_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$today = date('Y-m-d');
$sql = "SELECT i.id, i.tenant_id, i.invoice_no, i.party_id, i.total, i.balance, i.due_date,
               p.name AS party_name, p.email AS party_email,
               DATEDIFF('{$today}', i.due_date) AS days_overdue
        FROM `invoice_list` i
        INNER JOIN `party_list` p ON i.party_id = p.id
        LEFT JOIN `invoice_overdue_marks` m ON m.invoice_id = i.id AND m.marked_on = '{$today}'
        WHERE i.status IN ('unpaid','partially_paid')
          AND i.due_date IS NOT NULL
          AND i.due_date < '{$today}'
          AND m.invoice_id IS NULL";

$res = $conn->query($sql);
$count = 0;

if ($res) {
    while ($row = $res->fetch_assoc()) {
        ModuleManager::trigger_hook(IntegrationEvents::INVOICE_OVERDUE, [
            'invoice_id'  => (int)$row['id'],
            'invoice_no'  => $row['invoice_no'],
            'party_id'    => (int)$row['party_id'],
            'party_name'  => $row['party_name'],
            'party_email' => $row['party_email'],
            'total'       => (float)$row['total'],
            'balance'     => (float)$row['balance'],
            'due_date'    => $row['due_date'],
            'days_overdue'=> (int)$row['days_overdue'],
        ], (int)$row['tenant_id']);

        $iid = (int)$row['id'];
        $conn->query("INSERT IGNORE INTO `invoice_overdue_marks` (`invoice_id`, `marked_on`) VALUES ('{$iid}', '{$today}')");
        $count++;
    }
}

printf("[%s] invoice_overdue_sweep: emitted %d overdue event(s).\n", date('Y-m-d H:i:s'), $count);
exit(0);
