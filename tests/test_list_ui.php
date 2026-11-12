<?php
// List-page UI layer: breadcrumb/title, summary cards, filters, status pills.
$root = dirname(__DIR__);
$idx = file_get_contents($root . '/admin/index.php');
$js  = file_get_contents($root . '/dist/js/nuvis-ui.js');
$css = file_get_contents($root . '/dist/css/nuvis-ui.css');
$thm = file_get_contents($root . '/dist/css/nuvis-theme.css');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// Page heading
check('index.php renders breadcrumb + page title', strpos($idx, 'nv-breadcrumb') !== false && strpos($idx, 'nv-page-title') !== false);
check('heading is skipped on the dashboard', (bool)preg_match('/if\(\$page !== \'home\'\)/', $idx));
check('breadcrumb labels and links are HTML-escaped', substr_count($idx, 'htmlspecialchars') >= 4);
check('heading renders before the view loads', strpos($idx, 'nv-page-head') < strpos($idx, "\$view_file = \$page . '.php'"));

// Summary cards
check('summary cards built for the main list table', strpos($js, 'function buildSummary') !== false && (bool)preg_match('/attr\(\'id\'\)\s*!==\s*\'list\'/', $js));
check('cards show total plus top statuses', strpos($js, 'Total records') !== false && strpos($js, 'counts.slice(0, 3)') !== false);
check('clicking a status card filters the table', strpos($js, "\$stats.on('click', '.nv-stat-click'") !== false);
check('card labels are HTML-escaped', strpos($js, 'escHtml(c.label)') !== false);

// Filters
check('filter panel built from repeating columns', strpos($js, 'function buildFilters') !== false && strpos($js, 'count >= 2 && count < nodes.length') !== false);
check('filter values match exactly, regex-escaped', strpos($js, 'function escRegex') !== false && strpos($js, 'exactSearch') !== false);
check('clear resets every column filter', strpos($js, "api.columns().search('')") !== false);
check('enhanceTable wires up cards and filters', (bool)preg_match('/buildSummary\(api\);\s*buildFilters\(api\);/', $js));

// CSS
check('styles for heading, cards, filters and pills exist',
    strpos($css, '.nv-page-stats') !== false && strpos($css, '.nv-dt-filters') !== false
    && strpos($css, '.nv-page-title') !== false && strpos($css, 'table.nv-table .badge::before') !== false);
check('pill colours cover the badge palette', substr_count($css, 'table.nv-table .badge.badge-') >= 8);
check('dark mode covers the new elements', strpos($thm, 'html[data-nv-eff="dark"] .nv-stat ') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
