<?php
require_once(__DIR__ . '/auth.php');

use App\Core\Container;
use App\Services\VmsService;

$vmsService = Container::getInstance()->make(VmsService::class);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    check_permission('read_invoices');

    $action = $_GET['action'] ?? 'queue';

    if ($action === 'receipt') {
        $invoice_id = intval($_GET['invoice_id'] ?? 0);
        $receipt = $vmsService->getReceiptByInvoice($invoice_id, $api_tenant_id);
        if ($receipt) {
            echo json_encode(['status' => 'success', 'data' => $receipt]);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'failed', 'message' => 'Fiscal receipt not found for this invoice.']);
        }
        exit;
    }

    if ($action === 'logs') {
        $type = $_GET['type'] ?? null;
        $logs = $vmsService->getLogs($api_tenant_id, $type, 50);
        echo json_encode(['status' => 'success', 'count' => count($logs), 'data' => $logs]);
        exit;
    }

    $status = $_GET['status'] ?? null;
    $queue = $vmsService->getQueue($api_tenant_id, $status, 50);
    echo json_encode(['status' => 'success', 'count' => count($queue), 'data' => $queue]);
    exit;
}

if ($method === 'POST') {
    check_permission('write_invoices');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? 'enqueue';

    if ($action === 'enqueue') {
        $invoice_id = intval($input['invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'failed', 'message' => 'Valid invoice_id is required.']);
            exit;
        }

        $res = $vmsService->enqueueInvoice($invoice_id, $api_tenant_id);
        http_response_code($res['status'] === 'success' ? 200 : 400);
        echo json_encode($res);
        exit;
    }

    if ($action === 'process') {
        $limit = isset($input['limit']) ? intval($input['limit']) : 10;
        $res = $vmsService->processQueue($api_tenant_id, $limit);
        echo json_encode($res);
        exit;
    }

    if ($action === 'retry') {
        $queue_id = intval($input['queue_id'] ?? 0);
        if ($queue_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'failed', 'message' => 'Valid queue_id is required.']);
            exit;
        }

        $res = $vmsService->retryFailedQueue($queue_id, $api_tenant_id);
        echo json_encode($res);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'failed', 'message' => 'Invalid action parameter. Allowed: enqueue, process, retry']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST']);
?>