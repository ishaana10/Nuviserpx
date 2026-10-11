<?php
require_once(__DIR__ . '/auth.php');

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    check_permission('read_payments');

    $where = " WHERE p.tenant_id = '{$api_tenant_id}' ";
    $qry = $conn->query("SELECT p.*, pt.name as party_name, pt.party_code FROM `payment_list` p INNER JOIN `party_list` pt ON p.party_id = pt.id {$where} ORDER BY p.id DESC");
    $data = [];
    while($row = $qry->fetch_assoc()){
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
    check_permission('write_payments');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $party_id = isset($input['party_id']) ? intval($input['party_id']) : 0;
    $payment_no = !empty($input['payment_no']) ? $conn->real_escape_string($input['payment_no']) : 'REC-'.date('Ym').'-'.rand(1000,9999);
    $payment_date = !empty($input['payment_date']) ? $conn->real_escape_string($input['payment_date']) : date('Y-m-d');
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $method_type = !empty($input['method']) ? $conn->real_escape_string($input['method']) : 'bank_transfer';
    $reference = isset($input['reference']) ? $conn->real_escape_string($input['reference']) : 'API Payment';

    if($party_id <= 0 || $amount <= 0){
        http_response_code(400);
        echo json_encode(['status' => 'failed', 'message' => 'Valid party_id and payment amount are required.']);
        exit;
    }

    $sql = "INSERT INTO `payment_list` SET
        `tenant_id` = '{$api_tenant_id}',
        `payment_no` = '{$payment_no}',
        `type` = 'receipt',
        `party_id` = '{$party_id}',
        `payment_date` = '{$payment_date}',
        `amount` = '{$amount}',
        `method` = '{$method_type}',
        `reference` = '{$reference}',
        `status` = 'posted'";

    if($conn->query($sql)){
        $pay_id = $conn->insert_id;

        // Auto allocate across customer unpaid invoices
        if(isset($input['invoice_id']) && is_numeric($input['invoice_id'])){
            $inv_id = intval($input['invoice_id']);
            $conn->query("INSERT INTO `payment_allocations` SET `payment_id` = '{$pay_id}', `invoice_id` = '{$inv_id}', `amount` = '{$amount}'");
            $conn->query("UPDATE `invoice_list` SET `paid_amount` = `paid_amount` + {$amount}, `balance` = GREATEST(0, `total` - (`paid_amount` + {$amount})), `status` = IF((`paid_amount` + {$amount}) >= `total`, 'paid', 'partially_paid') WHERE id = '{$inv_id}'");
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Payment receipt recorded successfully via API.',
            'payment_id' => $pay_id,
            'payment_no' => $payment_no
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'failed', 'message' => 'Failed to record payment.', 'error' => $conn->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST']);
?>