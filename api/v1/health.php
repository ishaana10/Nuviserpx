<?php

declare(strict_types=1);

require_once(__DIR__ . '/../../initialize.php');
require_once(__DIR__ . '/../../classes/DBConnection.php');

header('Content-Type: application/json');

$db = new DBConnection();
$conn = $db->conn;

$dbConnected = false;
$dbError = null;

if ($conn && !$conn->connect_error) {
    $res = @$conn->query("SELECT 1");
    if ($res) {
        $dbConnected = true;
    }
}

$tenantCount = 0;
$userCount = 0;

if ($dbConnected) {
    $tRes = $conn->query("SELECT COUNT(*) as cnt FROM `tenants` WHERE delete_flag = 0");
    if ($tRes) {
        $tenantCount = (int)$tRes->fetch_assoc()['cnt'];
    }

    $uRes = $conn->query("SELECT COUNT(*) as cnt FROM `users` WHERE status = 1");
    if ($uRes) {
        $userCount = (int)$uRes->fetch_assoc()['cnt'];
    }
}

$status = $dbConnected ? 'healthy' : 'unhealthy';

http_response_code($dbConnected ? 200 : 503);

echo json_encode([
    'status' => $status,
    'timestamp' => date('c'),
    'environment' => [
        'php_version' => PHP_VERSION,
        'app_name' => 'Nuvis ERPX',
        'api_version' => 'v1'
    ],
    'services' => [
        'database' => [
            'status' => $dbConnected ? 'up' : 'down',
            'server' => DB_SERVER,
            'database' => DB_NAME
        ]
    ],
    'metrics' => [
        'active_tenants' => $tenantCount,
        'active_users' => $userCount,
        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
    ]
]);
