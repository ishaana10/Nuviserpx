<?php
if(!defined('TESTING')) define('TESTING', true);
if(!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if(!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

require_once base_app . 'config.php';

// Buffer initial loading output from classes/Login.php
ob_start();
require_once base_app . 'classes/Login.php';
ob_end_clean();

echo "=== TEST 1: Direct Login::index() Method Output ===\n";

$login = new Login();

ob_start();
$login->index();
$output = ob_get_clean();

echo "Captured Output: {$output}\n";

$expected = "<h1>Access Denied</h1> <a href='".base_url."'>Go Back.</a>";
if ($output !== $expected) {
    echo "FAIL: Output does not match expected output.\nExpected: '{$expected}'\nActual: '{$output}'\n";
    exit(1);
}
if (strpos($output, "Access Denied") === false) {
    echo "FAIL: Output missing 'Access Denied'\n";
    exit(1);
}
if (strpos($output, base_url) === false) {
    echo "FAIL: Output missing base_url\n";
    exit(1);
}

echo "=== TEST 2: Login.php Default Route Fallback Execution ===\n";

// Test default route when action 'f' parameter is unset or invalid
unset($_GET['f']);
ob_start();
include base_app . 'classes/Login.php';
$route_output = ob_get_clean();

if (strpos($route_output, "<h1>Access Denied</h1>") === false) {
    echo "FAIL: Default route fallback failed for unset action 'f'\n";
    exit(1);
}

$_GET['f'] = 'invalid_action_xyz';
ob_start();
include base_app . 'classes/Login.php';
$invalid_route_output = ob_get_clean();

if (strpos($invalid_route_output, "<h1>Access Denied</h1>") === false) {
    echo "FAIL: Default route fallback failed for invalid action 'f'\n";
    exit(1);
}

echo "\nALL LOGIN INDEX UNIT TESTS PASSED SUCCESSFULLY!\n";
?>
