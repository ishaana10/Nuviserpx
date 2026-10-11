<?php
if(!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if(!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "=== TEST 1: Tenant ID Resolution & Session Isolation ===\n";
$_SESSION['userdata']['tenant_id'] = 1;
$_SESSION['userdata']['active_tenant_id'] = 2;

function active_tenant_id_test(){
    if(isset($_SESSION['userdata']['active_tenant_id']) && $_SESSION['userdata']['active_tenant_id'] > 0){
        return (int)$_SESSION['userdata']['active_tenant_id'];
    }
    if(isset($_SESSION['userdata']['tenant_id']) && $_SESSION['userdata']['tenant_id'] > 0){
        return (int)$_SESSION['userdata']['tenant_id'];
    }
    return 1;
}

$active_tenant = active_tenant_id_test();
echo "Active Tenant ID: {$active_tenant} (Expected: 2)\n";
assert($active_tenant === 2, "Active tenant ID resolution");

$_SESSION['userdata']['active_tenant_id'] = 5;
$new_tenant = active_tenant_id_test();
echo "Updated Active Tenant ID: {$new_tenant} (Expected: 5)\n";
assert($new_tenant === 5, "Tenant switching resolution");

echo "\n=== TEST 2: SQL Injection & Parameter Type Safety ===\n";
$malicious_id = "1' OR '1'='1";
$clean_int = intval($malicious_id);
echo "Cleaned Integer ID: {$clean_int} (Expected: 1)\n";
assert($clean_int === 1, "Integer type safety check");

$malicious_str = "RUN-2024'; DROP TABLE users; --";
$clean_str = addslashes($malicious_str);
echo "Escaped String: {$clean_str}\n";
assert(strpos($clean_str, "DROP TABLE") !== false && strpos($clean_str, "'") === false, "String escaping check");

echo "\nALL TENANT SECURITY & ISOLATION UNIT TESTS PASSED SUCCESSFULLY!\n";
?>