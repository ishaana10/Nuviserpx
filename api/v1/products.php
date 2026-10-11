<?php
require_once(__DIR__ . '/auth.php');

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    check_permission('read_products');

    $where = " WHERE tenant_id = '{$api_tenant_id}' AND delete_flag = 0 ";
    if(isset($_GET['code'])){
        $code = $conn->real_escape_string($_GET['code']);
        $where .= " AND product_code = '{$code}' ";
    }

    $qry = $conn->query("SELECT id, product_code, name, category, unit, cost_price, selling_price, stock_quantity, reorder_level, status FROM `product_list` {$where} ORDER BY name ASC");
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
    check_permission('write_products');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $product_code = isset($input['product_code']) ? $conn->real_escape_string($input['product_code']) : 'SKU-'.date('Ym').'-'.rand(100,999);
    $name = isset($input['name']) ? $conn->real_escape_string($input['name']) : '';
    $category = isset($input['category']) ? $conn->real_escape_string($input['category']) : 'General';
    $unit = isset($input['unit']) ? $conn->real_escape_string($input['unit']) : 'pcs';
    $cost_price = isset($input['cost_price']) ? (float)$input['cost_price'] : 0;
    $selling_price = isset($input['selling_price']) ? (float)$input['selling_price'] : 0;
    $stock_quantity = isset($input['stock_quantity']) ? (float)$input['stock_quantity'] : 0;

    if(empty($name)){
        http_response_code(400);
        echo json_encode(['status' => 'failed', 'message' => 'Product name is required.']);
        exit;
    }

    $sql = "INSERT INTO `product_list` SET
        `tenant_id` = '{$api_tenant_id}',
        `product_code` = '{$product_code}',
        `name` = '{$name}',
        `category` = '{$category}',
        `unit` = '{$unit}',
        `cost_price` = '{$cost_price}',
        `selling_price` = '{$selling_price}',
        `stock_quantity` = '{$stock_quantity}'";

    if($conn->query($sql)){
        $prod_id = $conn->insert_id;
        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Product successfully created via API.',
            'product_id' => $prod_id,
            'product_code' => $product_code
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'failed', 'message' => 'Failed to create product.', 'error' => $conn->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'method_not_allowed', 'message' => 'Supported methods: GET, POST']);
?>