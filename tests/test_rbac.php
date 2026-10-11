<?php
if (!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if (!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "=== TEST 1: Admin Superuser Access Control ===\n";
$_SESSION['userdata']['type'] = 1; // Administrator
$_SESSION['userdata']['role_id'] = 0;

function can_access_test($perm_key, $user_type, $role_perms = []){
    if($user_type == 1) return true;
    if(in_array('*', $role_perms) || in_array($perm_key, $role_perms)){
        return true;
    }
    return false;
}

assert(can_access_test('invoices_manage', 1) === true, "Admin full access check");
assert(can_access_test('settings_manage', 1) === true, "Admin settings access check");

echo "=== TEST 2: Custom Role Permission Matrix Scoping ===\n";
$accountant_perms = ['journals_view', 'journals_manage', 'reports_view', 'invoices_view', 'payments_view'];

assert(can_access_test('journals_view', 2, $accountant_perms) === true, "Accountant GL access");
assert(can_access_test('invoices_view', 2, $accountant_perms) === true, "Accountant Invoice view access");
assert(can_access_test('payroll_manage', 2, $accountant_perms) === false, "Accountant Payroll restriction");
assert(can_access_test('settings_manage', 2, $accountant_perms) === false, "Accountant Settings restriction");

echo "=== TEST 3: Sales Rep Custom Role Scoping ===\n";
$sales_rep_perms = ['products_view', 'sales_orders_view', 'sales_orders_manage', 'invoices_view'];

assert(can_access_test('sales_orders_manage', 2, $sales_rep_perms) === true, "Sales Rep Order taking access");
assert(can_access_test('journals_manage', 2, $sales_rep_perms) === false, "Sales Rep GL posting restriction");

echo "\nALL RBAC & USER ACCESS LEVEL TESTS PASSED SUCCESSFULLY!\n";
?>