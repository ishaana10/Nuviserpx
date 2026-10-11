<?php
require_once(__DIR__ . '/auth.php');

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    check_permission('read_payroll');

    $where = " WHERE p.tenant_id = '{$api_tenant_id}' ";
    if(isset($_GET['month'])){
        $m = $conn->real_escape_string($_GET['month']);
        $where .= " AND p.salary_month = '{$m}' ";
    }

    $qry = $conn->query("SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code, e.tin, e.fnpf_no FROM `payroll_list` p INNER JOIN `employee_list` e ON p.employee_id = e.id {$where} ORDER BY p.id DESC");
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

http_response_code(405);
echo json_encode(['status' => 'method_not_allowed', 'message' => 'Supported methods: GET']);
?>