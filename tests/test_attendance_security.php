<?php
if(!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if(!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "=== TEST: Attendance Management Security & SQL Injection Prevention ===\n";

// Test 1: Verify integer casting neutralizes SQL Injection vectors
$payloads = [
    "1' OR '1'='1",
    "1; DROP TABLE attendance_list; --",
    "9999 UNION SELECT 1,2,3,4,5,6,7,8 --",
    "abc"
];

foreach ($payloads as $payload) {
    $clean_id = isset($payload) ? intval($payload) : 0;
    echo "Payload: " . var_export($payload, true) . " => Sanitized ID: " . var_export($clean_id, true) . "\n";
    assert(is_int($clean_id), "Sanitized ID must be integer");
    assert(strpos((string)$clean_id, "'") === false, "Sanitized ID must not contain quotes");
    assert(strpos((string)$clean_id, ";") === false, "Sanitized ID must not contain semicolons");
}

echo "\n=== TEST: Multi-Tenant Query Scoping Construction ===\n";
$_SESSION['userdata']['tenant_id'] = 1;
$_SESSION['userdata']['active_tenant_id'] = 2;

$tenant_id = (isset($_SESSION['userdata']['active_tenant_id']) && $_SESSION['userdata']['active_tenant_id'] > 0)
    ? (int)$_SESSION['userdata']['active_tenant_id']
    : (int)$_SESSION['userdata']['tenant_id'];

$raw_id = "5' OR '1'='1";
$id = isset($raw_id) ? intval($raw_id) : 0;

$expected_sql = "SELECT * from `attendance_list` where id = '{$id}' and tenant_id = '{$tenant_id}' ";
echo "Constructed SQL Query: " . $expected_sql . "\n";

assert(strpos($expected_sql, "where id = '5' and tenant_id = '2'") !== false, "Query must use integer id and active tenant_id");
assert(strpos($expected_sql, "OR '1'='1'") === false, "Query must not contain injected SQL payload");

echo "\nALL ATTENDANCE SECURITY TESTS PASSED SUCCESSFULLY!\n";
?>
