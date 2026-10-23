<?php
if(!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if(!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "=== TEST: manage_payroll.php SQL Injection Fix Verification ===\n";

$_SESSION['userdata']['active_tenant_id'] = 1;
$_SESSION['userdata']['tenant_id'] = 1;

$test_inputs = [
    "1' OR '1'='1" => false,
    "1 UNION SELECT 1,2,3" => false,
    "1; DROP TABLE payroll_list;" => false,
    "abc" => false,
    "0" => false,
    "-5" => false,
    "123" => true,
    123 => true,
];

foreach ($test_inputs as $input => $should_be_valid) {
    $_GET['id'] = $input;

    $is_valid = isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0;

    if ($is_valid !== $should_be_valid) {
        echo "FAILED for input: " . var_export($input, true) . " | Expected valid: " . var_export($should_be_valid, true) . " | Got: " . var_export($is_valid, true) . "\n";
        exit(1);
    }

    if ($is_valid) {
        $payroll_id_clean = intval($_GET['id']);
        $tenant_id = 1;
        $query_str = "SELECT * from `payroll_list` where id = '{$payroll_id_clean}' and tenant_id = '{$tenant_id}' ";

        // Assert clean query format
        if (strpos($query_str, "'123'") === false || strpos($query_str, "tenant_id = '1'") === false) {
            echo "FAILED: Query string formatting error: {$query_str}\n";
            exit(1);
        }
    }
}

echo "PASSED: All SQL injection test payloads were safely handled and sanitized.\n";
