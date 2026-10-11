<?php
/**
 * RESTful API v1 Authentication & Tenant Resolution Engine
 * Nuvis ERPX
 */

header('Content-Type: application/json; charset=utf-8');

if(!defined('base_app')) define('base_app', dirname(dirname(__DIR__)) . '/');

require_once(base_app . 'classes/DBConnection.php');

$db = new DBConnection();
$conn = $db->conn;

// Extract API Key from Header or Query
$api_key = isset($_SERVER['HTTP_X_API_KEY']) ? trim($_SERVER['HTTP_X_API_KEY']) : '';

if(empty($api_key) && isset($_SERVER['HTTP_AUTHORIZATION'])){
    if(preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)){
        $api_key = $matches[1];
    }
}

if(empty($api_key) && isset($_GET['api_key'])){
    $api_key = trim($_GET['api_key']);
}

if(empty($api_key)){
    http_response_code(401);
    echo json_encode([
        'status' => 'unauthorized',
        'message' => 'API Key required. Provide X-API-Key header or Authorization: Bearer token.'
    ]);
    exit;
}

$api_key_clean = $conn->real_escape_string($api_key);
$key_qry = $conn->query("SELECT * FROM `api_keys` WHERE api_key = '{$api_key_clean}' AND status = 1 LIMIT 1");

if(!$key_qry || $key_qry->num_rows == 0){
    http_response_code(401);
    echo json_encode([
        'status' => 'unauthorized',
        'message' => 'Invalid or inactive API Key.'
    ]);
    exit;
}

$api_user = $key_qry->fetch_assoc();
$api_tenant_id = (int)$api_user['tenant_id'];

// Update last used at
$conn->query("UPDATE `api_keys` SET `last_used_at` = NOW() WHERE id = '{$api_user['id']}'");

// Parse permissions
$api_permissions = !empty($api_user['permissions']) ? explode(',', $api_user['permissions']) : ['*'];

function check_permission($required_perm) {
    global $api_permissions;
    if(in_array('*', $api_permissions) || in_array($required_perm, $api_permissions)){
        return true;
    }
    http_response_code(403);
    echo json_encode([
        'status' => 'forbidden',
        'message' => "Insufficient permissions for endpoint. Required: {$required_perm}"
    ]);
    exit;
}
?>