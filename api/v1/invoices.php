<?php
/**
 * Invoices REST endpoint — Nuvis ERPX API v1
 *
 *   GET    /api/v1/invoices.php                 list (pagination: ?page, ?per_page; filters: ?status, ?party_id, ?invoice_no, ?from, ?to)
 *   GET    /api/v1/invoices.php?id=123          fetch one invoice with line items
 *   POST   /api/v1/invoices.php                 create invoice (fires invoice.created)
 *   PUT    /api/v1/invoices.php?id=123          update invoice (fires invoice.updated)
 *   PATCH  /api/v1/invoices.php?id=123          partial update (fires invoice.updated)
 *   POST   /api/v1/invoices.php?id=123&action=pay   record a payment against the invoice
 *   DELETE /api/v1/invoices.php?id=123          delete invoice (fires invoice.deleted)
 *
 * All operations are scoped to the API key's tenant. Responses are JSON.
 */

require_once(__DIR__ . '/auth.php');

use App\Support\IntegrationEvents;

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : '';

/* ----------------------------------------------------------------- helpers */

function inv_fetch($conn, int $tenant_id, int $id): ?array {
    $qry = $conn->query("SELECT i.*, p.name AS party_name, p.party_code, p.email AS party_email
        FROM `invoice_list` i INNER JOIN `party_list` p ON i.party_id = p.id
        WHERE i.id = '{$id}' AND i.tenant_id = '{$tenant_id}'");
    if (!$qry || $qry->num_rows === 0) return null;
    $row = $qry->fetch_assoc();
    $items = $conn->query("SELECT item_name, description, qty, unit_price, tax_rate, tax_amount, amount FROM `invoice_items` WHERE invoice_id = '{$id}'");
    $row['items'] = [];
    if ($items) { while ($it = $items->fetch_assoc()) { $row['items'][] = $it; } }
    return $row;
}

/* ----------------------------------------------------------------------- GET */

if ($method === 'GET') {
    check_permission('read_invoices');

    if ($id > 0) {
        $inv = inv_fetch($conn, $api_tenant_id, $id);
        if (!$inv) api_respond(404, ['status' => 'not_found', 'message' => 'Invoice not found.']);
        api_respond(200, ['status' => 'success', 'data' => $inv]);
    }

    // List with pagination + filters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $per_page;

    $where = " WHERE i.tenant_id = '{$api_tenant_id}' ";
    if (!empty($_GET['invoice_no'])) {
        $where .= " AND i.invoice_no = '" . $conn->real_escape_string($_GET['invoice_no']) . "' ";
    }
    if (!empty($_GET['status'])) {
        $where .= " AND i.status = '" . $conn->real_escape_string($_GET['status']) . "' ";
    }
    if (!empty($_GET['party_id'])) {
        $where .= " AND i.party_id = '" . (int)$_GET['party_id'] . "' ";
    }
    if (!empty($_GET['from'])) {
        $where .= " AND i.invoice_date >= '" . $conn->real_escape_string($_GET['from']) . "' ";
    }
    if (!empty($_GET['to'])) {
        $where .= " AND i.invoice_date <= '" . $conn->real_escape_string($_GET['to']) . "' ";
    }

    $total_row = $conn->query("SELECT COUNT(*) AS c FROM `invoice_list` i {$where}")->fetch_assoc();
    $total = (int)($total_row['c'] ?? 0);

    $qry = $conn->query("SELECT i.*, p.name AS party_name, p.party_code
        FROM `invoice_list` i INNER JOIN `party_list` p ON i.party_id = p.id
        {$where} ORDER BY i.id DESC LIMIT {$per_page} OFFSET {$offset}");

    $data = [];
    if ($qry) {
        while ($row = $qry->fetch_assoc()) {
            $inv_id = (int)$row['id'];
            $items_qry = $conn->query("SELECT item_name, description, qty, unit_price, tax_rate, tax_amount, amount FROM `invoice_items` WHERE invoice_id = '{$inv_id}'");
            $row['items'] = [];
            if ($items_qry) { while ($it = $items_qry->fetch_assoc()) { $row['items'][] = $it; } }
            $data[] = $row;
        }
    }

    api_respond(200, [
        'status' => 'success',
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => (int)ceil($total / $per_page),
        ],
        'count' => count($data),
        'data' => $data,
    ]);
}

/* ---------------------------------------------------------------------- POST */

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Payment action against an existing invoice
    if ($id > 0 && $action === 'pay') {
        check_permission('write_payments');
        $inv = inv_fetch($conn, $api_tenant_id, $id);
        if (!$inv) api_respond(404, ['status' => 'not_found', 'message' => 'Invoice not found.']);

        $amount = isset($input['amount']) ? (float)$input['amount'] : (float)$inv['balance'];
        if ($amount <= 0) api_respond(400, ['status' => 'failed', 'message' => 'Payment amount must be greater than zero.']);
        $amount = min($amount, (float)$inv['balance']);

        $pay_no = 'REC-' . date('Ym') . '-' . rand(1000, 9999);
        $pay_date = !empty($input['payment_date']) ? $conn->real_escape_string($input['payment_date']) : date('Y-m-d');
        $pmethod = !empty($input['method']) ? $conn->real_escape_string($input['method']) : 'cash';
        $pid = (int)$inv['party_id'];

        $conn->query("INSERT INTO `payment_list` SET
            `tenant_id` = '{$api_tenant_id}', `payment_no` = '{$pay_no}', `type` = 'receipt',
            `party_id` = '{$pid}', `payment_date` = '{$pay_date}', `amount` = '{$amount}',
            `method` = '{$pmethod}', `status` = 'posted'");
        $payment_id = (int)$conn->insert_id;

        $conn->query("INSERT INTO `payment_allocations` SET `payment_id` = '{$payment_id}', `invoice_id` = '{$id}', `amount` = '{$amount}'");

        // Recalculate invoice balance/status
        $sum = $conn->query("SELECT SUM(amount) AS paid FROM `payment_allocations` WHERE invoice_id = '{$id}'")->fetch_assoc();
        $tot_paid = (float)($sum['paid'] ?? 0);
        $inv_total = (float)$inv['total'];
        $inv_credit = (float)($conn->query("SELECT credited_amount FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc()['credited_amount'] ?? 0);
        $new_bal = \App\Services\CreditNoteService::outstanding($inv_total, $tot_paid, $inv_credit);
        $new_status = $tot_paid + $inv_credit >= $inv_total && $inv_total > 0 ? 'paid' : ($tot_paid + $inv_credit > 0 ? 'partially_paid' : 'unpaid');
        $conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$id}'");

        api_emit(IntegrationEvents::PAYMENT_RECEIVED, [
            'payment_id' => $payment_id, 'payment_no' => $pay_no, 'party_id' => $pid,
            'party_name' => $inv['party_name'], 'party_email' => $inv['party_email'],
            'amount' => $amount, 'method' => $pmethod,
        ]);
        if ($new_status === 'paid' || $new_status === 'partially_paid') {
            api_emit($new_status === 'paid' ? IntegrationEvents::INVOICE_PAID : IntegrationEvents::INVOICE_PARTIALLY_PAID, [
                'invoice_id' => $id, 'invoice_no' => $inv['invoice_no'], 'party_id' => $pid,
                'party_name' => $inv['party_name'], 'party_email' => $inv['party_email'],
                'total' => $inv_total, 'paid_amount' => $tot_paid, 'balance' => $new_bal, 'status' => $new_status,
            ]);
        }

        api_respond(201, [
            'status' => 'success',
            'message' => 'Payment recorded.',
            'payment_id' => $payment_id,
            'invoice_status' => $new_status,
            'balance' => $new_bal,
        ]);
    }

    // Create invoice
    check_permission('write_invoices');

    $party_id = isset($input['party_id']) ? (int)$input['party_id'] : 0;
    $invoice_no = !empty($input['invoice_no']) ? $conn->real_escape_string($input['invoice_no']) : 'INV-' . date('Ym') . '-' . rand(1000, 9999);
    $invoice_date = !empty($input['invoice_date']) ? $conn->real_escape_string($input['invoice_date']) : date('Y-m-d');
    $due_date = !empty($input['due_date']) ? $conn->real_escape_string($input['due_date']) : date('Y-m-d', strtotime('+30 days'));
    $subtotal = isset($input['subtotal']) ? (float)$input['subtotal'] : 0;
    $tax_amount = isset($input['tax_amount']) ? (float)$input['tax_amount'] : 0;
    $discount = isset($input['discount']) ? (float)$input['discount'] : 0;
    $total = isset($input['total']) ? (float)$input['total'] : 0;
    $notes = isset($input['notes']) ? $conn->real_escape_string($input['notes']) : '';

    if ($party_id <= 0 || $total <= 0) {
        api_respond(400, ['status' => 'failed', 'message' => 'Valid party_id and total amount are required.']);
    }

    // Verify party belongs to this tenant
    $party_q = $conn->query("SELECT id, name, email FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$api_tenant_id}'");
    if (!$party_q || $party_q->num_rows === 0) {
        api_respond(422, ['status' => 'failed', 'message' => 'party_id does not belong to this tenant.']);
    }
    $party = $party_q->fetch_assoc();

    $conn->query("INSERT INTO `invoice_list` SET
        `tenant_id` = '{$api_tenant_id}', `invoice_no` = '{$invoice_no}', `party_id` = '{$party_id}',
        `invoice_date` = '{$invoice_date}', `due_date` = '{$due_date}', `subtotal` = '{$subtotal}',
        `tax_amount` = '{$tax_amount}', `discount` = '{$discount}', `total` = '{$total}',
        `balance` = '{$total}', `status` = 'unpaid', `notes` = '{$notes}'");

    if ($conn->error) {
        api_respond(500, ['status' => 'failed', 'message' => 'Failed to create invoice.', 'error' => $conn->error]);
    }
    $inv_id = (int)$conn->insert_id;

    if (isset($input['items']) && is_array($input['items'])) {
        foreach ($input['items'] as $it) {
            $i_name = isset($it['item_name']) ? $conn->real_escape_string($it['item_name']) : 'API Item';
            $i_desc = isset($it['description']) ? $conn->real_escape_string($it['description']) : '';
            $i_qty = isset($it['qty']) ? (float)$it['qty'] : 1;
            $i_price = isset($it['unit_price']) ? (float)$it['unit_price'] : 0;
            $i_tax_rate = isset($it['tax_rate']) ? (float)$it['tax_rate'] : 0;
            $i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
            $i_amt = isset($it['amount']) ? (float)$it['amount'] : round(($i_qty * $i_price) + $i_tax_amt, 2);
            $conn->query("INSERT INTO `invoice_items` SET `invoice_id` = '{$inv_id}', `item_name` = '{$i_name}',
                `description` = '{$i_desc}', `qty` = '{$i_qty}', `unit_price` = '{$i_price}',
                `tax_rate` = '{$i_tax_rate}', `tax_amount` = '{$i_tax_amt}', `amount` = '{$i_amt}'");
        }
    }

    api_emit(IntegrationEvents::INVOICE_CREATED, [
        'invoice_id' => $inv_id, 'invoice_no' => $invoice_no, 'party_id' => $party_id,
        'party_name' => $party['name'], 'party_email' => $party['email'],
        'subtotal' => $subtotal, 'tax_amount' => $tax_amount, 'discount' => $discount,
        'total' => $total, 'balance' => $total, 'status' => 'unpaid',
        'invoice_date' => $invoice_date, 'due_date' => $due_date,
    ]);

    api_respond(201, [
        'status' => 'success',
        'message' => 'Invoice created successfully via API.',
        'invoice_id' => $inv_id,
        'invoice_no' => $invoice_no,
    ]);
}

/* -------------------------------------------------------------- PUT / PATCH */

if ($method === 'PUT' || $method === 'PATCH') {
    check_permission('write_invoices');
    if ($id <= 0) api_respond(400, ['status' => 'failed', 'message' => 'An invoice id is required for updates.']);

    $inv = inv_fetch($conn, $api_tenant_id, $id);
    if (!$inv) api_respond(404, ['status' => 'not_found', 'message' => 'Invoice not found.']);

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $fields = [];
    $allowed = ['invoice_no', 'invoice_date', 'due_date', 'subtotal', 'tax_amount', 'discount', 'total', 'notes', 'status'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $input)) {
            if (in_array($f, ['subtotal', 'tax_amount', 'discount', 'total'], true)) {
                $fields[] = "`{$f}` = '" . (float)$input[$f] . "'";
            } else {
                $fields[] = "`{$f}` = '" . $conn->real_escape_string((string)$input[$f]) . "'";
            }
        }
    }
    if (empty($fields)) api_respond(400, ['status' => 'failed', 'message' => 'No updatable fields supplied.']);

    // Keep balance consistent if total changed
    if (array_key_exists('total', $input)) {
        $new_total = (float)$input['total'];
        $paid = (float)$inv['paid_amount'];
        $cr = (float)($conn->query("SELECT credited_amount FROM `invoice_list` WHERE id = '" . (int)$inv['id'] . "'")->fetch_assoc()['credited_amount'] ?? 0);
        $fields[] = "`balance` = '" . \App\Services\CreditNoteService::outstanding($new_total, $paid, $cr) . "'";
    }

    $conn->query("UPDATE `invoice_list` SET " . implode(', ', $fields) . " WHERE id = '{$id}' AND tenant_id = '{$api_tenant_id}'");
    if ($conn->error) api_respond(500, ['status' => 'failed', 'message' => 'Update failed.', 'error' => $conn->error]);

    $updated = inv_fetch($conn, $api_tenant_id, $id);

    $event = (($input['status'] ?? '') === 'cancelled') ? IntegrationEvents::INVOICE_CANCELLED : IntegrationEvents::INVOICE_UPDATED;
    api_emit($event, [
        'invoice_id' => $id, 'invoice_no' => $updated['invoice_no'], 'party_id' => (int)$updated['party_id'],
        'party_name' => $updated['party_name'], 'party_email' => $updated['party_email'],
        'total' => (float)$updated['total'], 'balance' => (float)$updated['balance'], 'status' => $updated['status'],
    ]);

    api_respond(200, ['status' => 'success', 'message' => 'Invoice updated.', 'data' => $updated]);
}

/* -------------------------------------------------------------------- DELETE */

if ($method === 'DELETE') {
    check_permission('write_invoices');
    if ($id <= 0) api_respond(400, ['status' => 'failed', 'message' => 'An invoice id is required.']);

    $inv = inv_fetch($conn, $api_tenant_id, $id);
    if (!$inv) api_respond(404, ['status' => 'not_found', 'message' => 'Invoice not found.']);

    if (!empty($inv['journal_id'])) {
        $conn->query("DELETE FROM `journal_entries` WHERE id = '" . (int)$inv['journal_id'] . "'");
    }
    $conn->query("DELETE FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$api_tenant_id}'");

    api_emit(IntegrationEvents::INVOICE_DELETED, ['invoice_id' => $id, 'invoice_no' => $inv['invoice_no']]);
    api_respond(200, ['status' => 'success', 'message' => 'Invoice deleted.']);
}

api_respond(405, ['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST, PUT, PATCH, DELETE']);
