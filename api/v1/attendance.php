<?php

declare(strict_types=1);

require_once(__DIR__ . '/../../initialize.php');
require_once(__DIR__ . '/../../classes/DBConnection.php');

header('Content-Type: application/json');

$db = new DBConnection();
$conn = $db->conn;

// Token verification
$headers = getallheaders();
$apiKey = $headers['X-API-KEY'] ?? $_GET['api_key'] ?? '';

if (empty($apiKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'API Key required.']);
    exit;
}

$keyEsc = $conn->real_escape_string($apiKey);
$keyQry = $conn->query("SELECT * FROM `api_keys` WHERE `api_key` = '{$keyEsc}' AND `status` = 1");

if (!$keyQry || $keyQry->num_rows == 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or revoked API key.']);
    exit;
}

$keyData = $keyQry->fetch_assoc();
$tenant_id = $keyData['tenant_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $employee_code = $conn->real_escape_string((string)($input['employee_code'] ?? ''));
    $attendance_date = $conn->real_escape_string((string)($input['attendance_date'] ?? date('Y-m-d')));
    $type = strtolower((string)($input['type'] ?? 'clock_in')); // clock_in or clock_out
    $time = $conn->real_escape_string((string)($input['time'] ?? date('H:i:s')));

    $empQry = $conn->query("SELECT id FROM `employee_list` WHERE `employee_code` = '{$employee_code}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0");
    if (!$empQry || $empQry->num_rows == 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => "Employee with code '{$employee_code}' not found."]);
        exit;
    }

    $empId = $empQry->fetch_assoc()['id'];

    $attQry = $conn->query("SELECT * FROM `attendance_list` WHERE `employee_id` = '{$empId}' AND `attendance_date` = '{$attendance_date}' AND tenant_id = '{$tenant_id}'");

    if ($type === 'clock_out') {
        if ($attQry && $attQry->num_rows > 0) {
            $conn->query("UPDATE `attendance_list` SET `check_out` = '{$time}' WHERE `employee_id` = '{$empId}' AND `attendance_date` = '{$attendance_date}' AND tenant_id = '{$tenant_id}'");
            echo json_encode(['status' => 'success', 'message' => "Clock-out logged for employee {$employee_code} at {$time}."]);
        } else {
            $conn->query("INSERT INTO `attendance_list` (`tenant_id`, `employee_id`, `attendance_date`, `check_out`, `status`) VALUES ('{$tenant_id}', '{$empId}', '{$attendance_date}', '{$time}', 'Present')");
            echo json_encode(['status' => 'success', 'message' => "Clock-out logged for employee {$employee_code} at {$time}."]);
        }
    } else {
        if ($attQry && $attQry->num_rows > 0) {
            $conn->query("UPDATE `attendance_list` SET `check_in` = '{$time}', `status` = 'Present' WHERE `employee_id` = '{$empId}' AND `attendance_date` = '{$attendance_date}' AND tenant_id = '{$tenant_id}'");
        } else {
            $conn->query("INSERT INTO `attendance_list` (`tenant_id`, `employee_id`, `attendance_date`, `check_in`, `status`) VALUES ('{$tenant_id}', '{$empId}', '{$attendance_date}', '{$time}', 'Present')");
        }
        echo json_encode(['status' => 'success', 'message' => "Clock-in logged for employee {$employee_code} at {$time}."]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
