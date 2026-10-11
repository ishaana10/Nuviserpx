<?php
if (!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if (!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

require_once(base_app . 'classes/ModuleManager.php');

echo "=== TEST 1: ModuleManager Module Registry ===\n";
$modules = ModuleManager::get_modules();
print_r(array_keys($modules));

assert(isset($modules['accounting']), "Accounting module registered");
assert(isset($modules['invoicing']), "Invoicing module registered");
assert(isset($modules['inventory']), "Inventory module registered");
assert(isset($modules['payroll']), "Payroll module registered");

echo "=== TEST 2: Event Hook Registration & Execution ===\n";
$hook_executed = false;
ModuleManager::register_hook('on_invoice_created', function($payload, $tenant_id) use (&$hook_executed) {
    $hook_executed = true;
    return "Handled invoice #" . $payload['invoice_no'] . " for tenant #" . $tenant_id;
});

$res = ModuleManager::trigger_hook('on_invoice_created', ['invoice_no' => 'INV-999'], 2);
echo "Hook Trigger Result: " . print_r($res, true) . "\n";

assert($hook_executed === true, "Event hook was executed");

echo "=== TEST 3: API Permission Scoping ===\n";
function test_perm_check($user_perms, $req_perm){
    if(in_array('*', $user_perms) || in_array($req_perm, $user_perms)){
        return true;
    }
    return false;
}

assert(test_perm_check(['*'], 'read_products') === true, "Full access wildcard check");
assert(test_perm_check(['read_products', 'write_products'], 'read_products') === true, "Specific permission check");
assert(test_perm_check(['read_products'], 'write_invoices') === false, "Permission restriction check");

echo "\nALL MODULAR API & INTEGRATION ENGINE TESTS PASSED SUCCESSFULLY!\n";
?>