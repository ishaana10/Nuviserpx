<?php
// The in-app user guide and per-page helper cover the whole application.
$root = dirname(__DIR__);
require_once $root . '/src/Support/Permissions.php';
require_once $root . '/src/Support/HelpContent.php';

use App\Support\Permissions;
use App\Support\HelpContent;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$topics = HelpContent::topics();

// Structure: every topic is complete
$ok = true;
foreach ($topics as $slug => $t) {
    $ok = $ok && !empty($t['title']) && !empty($t['icon']) && !empty($t['intro']) && !empty($t['pages']) && !empty($t['sections']);
    foreach ($t['sections'] as $s) { $ok = $ok && !empty($s['h']) && !empty($s['steps']) && isset($s['tips']); }
}
check('every topic has a title, icon, intro, pages and stepped sections', $ok);

// Coverage: every tenant module has a guide topic
$covered_modules = [];
foreach ($topics as $t) { if (!empty($t['module'])) { $covered_modules[] = $t['module']; } }
check('every tenant module is documented', count(array_diff(array_keys(Permissions::TENANT_MODULES), $covered_modules)) === 0);

// Coverage: every page linked in the navigation resolves to a topic
$menu_pages = [];
foreach (glob($root . '/admin/inc/menus/*.php') as $mf) {
    if (preg_match_all('/page=([a-z_\/]+)/', file_get_contents($mf), $m)) {
        $menu_pages = array_merge($menu_pages, $m[1]);
    }
}
$menu_pages = array_unique($menu_pages);
$uncovered = [];
foreach ($menu_pages as $p) {
    if ($p !== 'help' && HelpContent::topicForPage($p) === null) { $uncovered[] = $p; }
}
check('every navigation page has a help topic (' . count($menu_pages) . ' pages)', $uncovered === [] || print_r($uncovered, true) === '');

// Longest-prefix matching works
check('user -> account topic, user/list -> users_roles topic', HelpContent::topicForPage('user') === 'account' && HelpContent::topicForPage('user/list') === 'users_roles');
check('subpages inherit their parent topic', HelpContent::topicForPage('payroll/payrun_engine') === 'payroll' && HelpContent::topicForPage('reports/balance_sheet') === 'reports');

// Visibility filtering hooks
$hc = file_get_contents($root . '/src/Support/HelpContent.php');
check('topics filter by tenant module, permission and super tier', strpos($hc, "module_enabled(\$t['module'])") !== false && strpos($hc, "can_access(\$t['perm'])") !== false && strpos($hc, "is_super_admin()") !== false);

// Wiring
$gi = file_get_contents($root . '/admin/help/index.php');
$hw = file_get_contents($root . '/admin/inc/help_widget.php');
$ai = file_get_contents($root . '/admin/index.php');
check('guide page renders visible topics with search and print', strpos($gi, 'visibleTopics') !== false && strpos($gi, 'guide-search') !== false && strpos($gi, 'window.print()') !== false);
check('floating helper is included on every admin page', strpos($ai, "help_widget.php") !== false);
check('helper shows the topic for the current page and links the full guide', strpos($hw, 'topicForPage') !== false && strpos($hw, 'page=help') !== false);
check('helper hides on the guide itself', strpos($hw, "!== 'help'") !== false);
check('guide is open to any logged-in admin and in the menu', Permissions::forPage('help') === null && strpos(file_get_contents($root . '/admin/inc/menus/12_help.php'), 'page=help') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
