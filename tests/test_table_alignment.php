<?php
// Table polish: status pills can't overlap their dot; numeric columns align
// right with their headings; index column centred.
$root = dirname(__DIR__);
$css = file_get_contents($root . '/dist/css/nuvis-ui.css');
$js  = file_get_contents($root . '/dist/js/nuvis-ui.js');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('pill is flex with the dot in layout, not absolute', strpos($css, 'table.nv-table .badge { display: inline-flex') !== false && (bool)preg_match('/table\.nv-table \.badge::before \{ content: \'\'; width: 7px/', $css));
check('pill padding outranks the global badge override', strpos($css, 'padding: .32em .7em !important') !== false);
check('numeric heading and cells right-aligned together', strpos($css, 'table.nv-table th.nv-num, table.nv-table td.nv-num { text-align: right; }') !== false);
check('numbers use tabular figures', strpos($css, 'font-variant-numeric: tabular-nums') !== false);
check('index column centred', strpos($css, 'table.nv-table th.nv-idx, table.nv-table td.nv-idx { text-align: center; }') !== false);
check('alignColumns wired into table setup', strpos($js, 'function alignColumns') !== false && (bool)preg_match('/buildFilters\(api\);\s*alignColumns\(api\);/', $js));
check('badge/button columns never treated as numeric', strpos($js, ".find('.badge, .btn, .dropdown').length) { seen = 0; break; }") !== false);
check('currency prefixes stripped before the numeric test', strpos($js, "replace(/^[A-Z]{1,3}\\\$?\\s*|^[\$\\u20ac\\u00a3]\\s*/, '')") !== false);
check('column counts as numeric only at 80 percent or more', strpos($js, 'numeric / seen >= 0.8') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
