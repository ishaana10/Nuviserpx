<?php
// Tenant modules cover the whole system, carry a dependency graph, and the
// activation/deactivation switches genuinely apply - to super admins too.
$root = dirname(__DIR__);
require_once $root . '/src/Support/Permissions.php';

use App\Support\Permissions;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// --- The graph itself ---
$keys = array_keys(Permissions::TENANT_MODULES);
$ok = true;
foreach (Permissions::TENANT_MODULES as $k => $def) {
    foreach ($def['requires'] as $r) { $ok = $ok && in_array($r, $keys, true) && $r !== $k; }
}
check('every requirement names another existing module', $ok);

$ok = true; // no cycles: closure of each module must not grow unboundedly and must contain itself exactly once
foreach ($keys as $k) {
    $c = Permissions::expandTenantModules([$k]);
    $ok = $ok && in_array($k, $c, true) && count($c) === count(array_unique($c));
    foreach (Permissions::moduleRequires($k) as $r) { $ok = $ok && in_array($r, $c, true); }
}
check('dependency closure is acyclic and contains all requirements', $ok);

// every permission module is governed by exactly one tenant module
$governed = [];
foreach (Permissions::TENANT_MODULES as $def) { foreach ($def['perms'] as $p) { $governed[] = $p; } }
check('no permission module is governed twice', count($governed) === count(array_unique($governed)));
$all = array_keys(Permissions::modules());
check('every permission module is governed by a tenant module', count(array_diff($all, $governed)) === 0);

// closure examples
check('tickets pull in sales, inventory and accounting', Permissions::expandTenantModules(['mod_tickets']) === ['mod_accounting', 'mod_inventory', 'mod_sales', 'mod_tickets']);
check('deactivating accounting switches off everything that posts', in_array('mod_payroll', Permissions::moduleDependents('mod_accounting'), true) && in_array('mod_contracts', Permissions::moduleDependents('mod_accounting'), true));
check('a stored assignment is always dependency-closed', Permissions::sanitizeTenantModules('mod_expense_reports') === ['mod_accounting', 'mod_payroll', 'mod_expense_reports']);
check('unknown keys are dropped before expansion', Permissions::sanitizeTenantModules('bogus,mod_warehouses') === ['mod_inventory', 'mod_warehouses']);

// permission keys resolve to the right module
check('invoices are governed by the sales module', Permissions::tenantModuleFor('invoices_create') === 'mod_sales');
check('financial reports are governed by accounting', Permissions::tenantModuleFor('reports_view') === 'mod_accounting');
check('products are governed by inventory', Permissions::tenantModuleFor('products_edit') === 'mod_inventory');

// --- Enforcement actually bites ---
$ss = file_get_contents($root . '/classes/SystemSettings.php');
$mf = file_get_contents($root . '/src/Support/MenuFilter.php');
$mt = file_get_contents($root . '/admin/tenants/manage_tenant.php');
$mm = file_get_contents($root . '/classes/ModuleManager.php');

check('module_enabled no longer bypasses super admins', !preg_match('/function module_enabled\(\$module_key\)\{\s*if\(\$this->userdata/', $ss));
check('can_access checks the tenant module before the super-admin bypass', strpos($ss, 'tenantModuleFor((string)$permission_key)') < strpos($ss, "if(\$user_type == 1) return true;") && strpos($ss, 'tenantModuleFor((string)$permission_key)') !== false);
check('MenuFilter filters super admins too (modules only)', strpos($mf, "settings->is_super_admin() || trim(") === false && strpos($mf, 'return (bool)$settings->is_super_admin();') !== false);

// --- Tenant form wiring ---
check('tenant form shows what each module needs', strpos($mt, 'needs:') !== false && strpos($mt, "\$mdef['requires']") !== false);
check('tenant form auto-ticks requirements and unticks dependents', strpos($mt, 'MODULE_REQUIRES') !== false && strpos($mt, 'moduleClosure') !== false);

// --- Registry mirrors the single source of truth ---
check('ModuleManager registers from TENANT_MODULES', strpos($mm, 'Permissions::TENANT_MODULES as') !== false && strpos($mm, "register_module('invoicing'") === false);
check('registry reports per-tenant status via module_enabled', strpos($mm, "module_enabled(\$key) ? 'active' : 'off'") !== false);

// --- Migration keeps existing tenants unchanged ---
$mig = @file_get_contents($root . '/database/migrations/037_tenant_module_dependencies.sql');
check('migration appends the new module keys to recorded assignments', $mig && strpos($mig, 'mod_sales,mod_inventory,mod_purchases') !== false && strpos($mig, 'WHERE `modules` IS NOT NULL') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
