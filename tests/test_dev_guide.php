<?php
// The developer guide lives under the Developer tab, super-admin only.
$root = dirname(__DIR__);
require_once $root . '/src/Support/Permissions.php';
use App\Support\Permissions;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$pg = file_get_contents($root . '/admin/developer/dev_guide.php');
$menu = file_get_contents($root . '/admin/inc/menus/11_developer.php');

check('guide page exists and is gated super-only via the developer segment', $pg !== false && Permissions::forPage('developer/dev_guide') === Permissions::SUPER_ONLY);
check('linked in the Developer menu', strpos($menu, 'page=developer/dev_guide') !== false);
foreach (['Architecture', 'Security', 'Multi-Tenancy', 'Migrations', 'Checklist', 'UI Layer', 'Email', 'Diagnostics', 'Deployment'] as $need) {
    check("covers: $need", stripos($pg, $need) !== false);
}
check('documents the core mechanisms by name', strpos($pg, '$allowed_actions') !== false && strpos($pg, 'TENANT_MODULES') !== false && strpos($pg, 'LegacySchemaCatchup') !== false && strpos($pg, 'SequenceService') !== false && strpos($pg, 'require_csrf') !== false);
check('has search, print and links to the user guide', strpos($pg, 'dev-guide-search') !== false && strpos($pg, 'window.print()') !== false && strpos($pg, 'page=help') !== false);
check('page is read-only', strpos($pg, '<form') === false && strpos($pg, '$_POST') === false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
