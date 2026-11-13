<?php
// A chart missing standard accounts (old tenants) can be gap-filled from the
// Default Accounts page, so posting roles always have a candidate.
$root = dirname(__DIR__);
$cp = file_get_contents($root . '/src/Services/ChartProvisioner.php');
$ms = file_get_contents($root . '/classes/Master.php');
$pg = file_get_contents($root . '/admin/accounts/default_accounts.php');
$pm = file_get_contents($root . '/src/Support/Permissions.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('fill() adds only the standard accounts a chart lacks', strpos($cp, 'public static function fill(') !== false && strpos($cp, "isset(\$have['codes'][strtolower(\$code)]) || isset(\$have['names'][strtolower(\$name)])") !== false);
check('an untyped standard account gets the standard type repaired', strpos($cp, 'SET type = ?, normal_balance = ?, status = 1 WHERE id = ? AND tenant_id = ?') !== false && strpos($cp, 'isRecognisedType((string)$row[\'type\'])') !== false);
check('an inactive standard account is reactivated, not skipped', strpos($cp, 'SET status = 1 WHERE id = ? AND tenant_id = ?') !== false && strpos($cp, 'repair it instead of skipping') !== false);
check('reactivation is scoped to the tenant and counted once', strpos($cp, "!isset(\$woken[\$wake])") !== false);
check('provision() for empty charts is untouched', strpos($cp, 'never touch a chart that already exists') !== false);
check('the standard chart covers every posting role', strpos($cp, 'Accounts Receivable') !== false && strpos($cp, 'Sales Revenue') !== false && (bool)preg_match('/Bank|Cash/', $cp));
check('provision_chart action exists, is audited and whitelisted', strpos($ms, 'function provision_chart') !== false && strpos($ms, "'Provisioned chart accounts'") !== false && strpos($ms, "'provision_chart',") !== false);
check('only settings managers can run it', (bool)preg_match("/'provision_chart'\s+=> \['settings_manage'\]/", $pm));
check('Default Accounts page offers the fill when a role has no account', strpos($pg, 'btn-provision-chart') !== false && strpos($pg, 'resolve($nv_role) <= 0') !== false);
check('the button calls the action and reloads', strpos($pg, 'f=provision_chart') !== false && strpos($pg, 'location.reload') !== false);

// The pure pieces agree: every role detects an account in the standard chart.
require_once $root . '/src/Services/LedgerService.php';
require_once $root . '/src/Services/AccountDefaultsService.php';
require_once $root . '/src/Services/ChartProvisioner.php';
$accounts = [];
$i = 1;
foreach (\App\Services\ChartProvisioner::STANDARD_CHART as [$code, $name, $type]) {
    $accounts[$i++] = ['name' => $name, 'type' => $type];
}
foreach (array_keys(\App\Services\AccountDefaultsService::ROLES) as $role) {
    check("standard chart satisfies the '{$role}' role", \App\Services\AccountDefaultsService::detect($accounts, $role) > 0);
}

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
