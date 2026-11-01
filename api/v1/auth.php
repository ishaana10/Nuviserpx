<?php
/**
 * RESTful API v1 Authentication & Tenant Resolution Engine
 * Nuvis ERPX
 *
 * Resolves the caller's tenant + permissions from an API key, bootstraps the
 * integration layer (so API writes fire webhooks + workflows), and provides
 * helpers: check_permission(), api_respond(), api_log_request(), api_emit().
 */

header('Content-Type: application/json; charset=utf-8');

if(!defined('base_app')) define('base_app', dirname(dirname(__DIR__)) . '/');

// Bootstrap env + autoloader so App\* classes and ModuleManager are available.
require_once(base_app . 'initialize.php');
require_once(base_app . 'classes/DBConnection.php');
require_once(base_app . 'classes/ModuleManager.php');

use App\Support\IntegrationEvents;

$__api_started = microtime(true);

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
$api_key_id = (int)$api_user['id'];

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
    api_log_request(403);
    echo json_encode([
        'status' => 'forbidden',
        'message' => "Insufficient permissions for endpoint. Required: {$required_perm}"
    ]);
    exit;
}

/**
 * Send a JSON response, log the request for metrics, and exit.
 * @param array<string,mixed> $body
 */
function api_respond(int $code, array $body): void {
    http_response_code($code);
    api_log_request($code);
    echo json_encode($body);
    exit;
}

/** Record an API request for the developer application-performance dashboard. */
function api_log_request(int $status_code): void {
    global $conn, $api_tenant_id, $api_key_id, $__api_started;
    if (!isset($conn) || !$conn) return;

    $endpoint = $conn->real_escape_string(basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'unknown'));
    $method = $conn->real_escape_string($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
    $duration = (int)round((microtime(true) - $__api_started) * 1000);
    $tid = (int)$api_tenant_id;
    $kid = (int)$api_key_id;

    @$conn->query("INSERT INTO `api_request_logs`
        (`tenant_id`, `api_key_id`, `endpoint`, `method`, `status_code`, `duration_ms`, `ip_address`)
        VALUES ('{$tid}', '{$kid}', '{$endpoint}', '{$method}', '{$status_code}', '{$duration}', '{$ip}')");
}

/**
 * Fire an integration event from the API context.
 * @param array<string,mixed> $payload
 */
function api_emit(string $event, array $payload): void {
    global $api_tenant_id;
    if (class_exists('ModuleManager')) {
        try {
            ModuleManager::trigger_hook($event, $payload, (int)$api_tenant_id);
        } catch (\Throwable $e) {
            // never break the API response on an integration failure
        }
    }
}
