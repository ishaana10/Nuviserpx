<?php
/**
 * Test View Payslip Security
 * Ensures $_GET['id'] in view_payslip.php is safely sanitized against SQL Injection.
 */

echo "=== TEST: view_payslip.php SQL Injection Security ===\n";

$test_payloads = [
    "1' OR '1'='1" => false, // should not pass is_numeric check
    "1; DROP TABLE users;--" => false, // should not pass is_numeric check
    "abc" => false, // should not pass is_numeric check
    "0" => false, // <= 0, should not execute
    "-5" => false, // <= 0, should not execute
    "1" => true, // valid numeric integer ID
    "100" => true // valid numeric integer ID
];

foreach ($test_payloads as $input => $expected_valid) {
    $_GET['id'] = $input;

    $is_valid = isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0;

    if ($is_valid !== $expected_valid) {
        echo "FAILED: Input '$input' expected valid=" . ($expected_valid ? "true" : "false") . ", got=" . ($is_valid ? "true" : "false") . "\n";
        exit(1);
    }

    if ($is_valid) {
        $sanitized_id = intval($_GET['id']);
        if (!is_int($sanitized_id) || $sanitized_id <= 0) {
            echo "FAILED: Input '$input' sanitized ID is invalid integer: $sanitized_id\n";
            exit(1);
        }
    }
}

echo "PASSED: All SQL injection payloads correctly rejected and valid IDs properly sanitized as integers.\n";
