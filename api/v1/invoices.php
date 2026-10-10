<?php
require_once(__DIR__ . '/auth.php');

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    check_permission('read_invoices');

    $where = " WHERE i.tenant_id = '{$api_tenant_id}' ";
    if(isset($_GET['invoice_no'])){
        $inv_no = $conn->real_escape_string($_GET['invoice_no']);
        $where .= " AND i.invoice_no = '{$inv_no}' ";
    }

    $qry = $conn->query("SELECT i.*, p.name as party_name, p.party_code FROM `invoice_list` i INNER JOIN `party_list` p ON i.party_id = p.id {$where} ORDER BY i.id DESC");
    $data = [];
    while($row = $qry->fetch_assoc()){
        $inv_id = $row['id'];
        $items_qry = $conn->query("SELECT item_name, description, qty, unit_price, tax_rate, tax_amount, amount FROM `invoice_items` WHERE invoice_id = '{$inv_id}'");
        $row['items'] = [];
        while($it = $items_qry->fetch_assoc()){
            $row['items'][] = $it;
        }
        $data[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'count' => count($data),
        'data' => $data
    ]);
    exit;
}

if($method === 'POST'){
    check_permission('write_invoices');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $party_id = isset($input['party_id']) ? intval($input['party_id']) : 0;
    $invoice_no = !empty($input['invoice_no']) ? $conn->real_escape_string($input['invoice_no']) : 'INV-'.date('Ym').'-'.rand(1000,9999);
    $invoice_date = !empty($input['invoice_date']) ? $conn->real_escape_string($input['invoice_date']) : date('Y-m-d');
    $due_date = !empty($input['due_date']) ? $conn->real_escape_string($input['due_date']) : date('Y-m-d', strtotime('+30 days'));
    $subtotal = isset($input['subtotal']) ? (float)$input['subtotal'] : 0;
    $tax_amount = isset($input['tax_amount']) ? (float)$input['tax_amount'] : 0;
    $total = isset($input['total']) ? (float)$input['total'] : 0;

    if($party_id <= 0 || $total <= 0){
        http_response_code(400);
        echo json_encode(['status' => 'failed', 'message' => 'Valid party_id and total amount are required.']);
        exit;
    }

    $sql = "INSERT INTO `invoice_list` SET
        `tenant_id` = '{$api_tenant_id}',
        `invoice_no` = '{$invoice_no}',
        `party_id` = '{$party_id}',
        `invoice_date` = '{$invoice_date}',
        `due_date` = '{$due_date}',
        `subtotal` = '{$subtotal}',
        `tax_amount` = '{$tax_amount}',
        `total` = '{$total}',
        `balance` = '{$total}',
        `status` = 'unpaid'";

    if($conn->query($sql)){
        $inv_id = $conn->insert_id;

        if(isset($input['items']) && is_array($input['items'])){
            foreach($input['items'] as $it){
                $i_name = isset($it['item_name']) ? $conn->real_escape_string($it['item_name']) : 'API Item';
                $i_qty = isset($it['qty']) ? (float)$it['qty'] : 1;
                $i_price = isset($it['unit_price']) ? (float)$it['unit_price'] : 0;
                $i_amt = isset($it['amount']) ? (float)$it['amount'] : ($i_qty * $i_price);

                $conn->query("INSERT INTO `invoice_items` SET
                    `invoice_id` = '{$inv_id}',
                    `item_name` = '{$i_name}',
                    `qty` = '{$i_qty}',
                    `unit_price` = '{$i_price}',
                    `amount` = '{$i_amt}'");
            }
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Invoice created successfully via API.',
            'invoice_id' => $inv_id,
            'invoice_no' => $invoice_no
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'failed', 'message' => 'Failed to create invoice.', 'error' => $conn->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST']);
?>