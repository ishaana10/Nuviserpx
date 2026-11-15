<?php
// Dashboard header must not push the phone page sideways (tests pin the CSS).
$css = file_get_contents(dirname(__DIR__) . '/dist/css/nuvis-dashboard.css');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
preg_match('~@media \(max-width: 575\.98px\) \{(.*?)\n\}\n~s', $css, $m);
$mob = $m[1] ?? '';
check('phone block found', $mob !== '');
check('action grid columns can shrink', strpos($mob, 'minmax(0, 1fr) minmax(0, 1fr)') !== false && !preg_match('~grid-template-columns: 44px 44px 1fr 1fr~', $css));
check('dashboard clips sideways overflow', strpos($mob, 'overflow-x: clip') !== false);
check('buttons ellipsise instead of widening', strpos($mob, 'text-overflow: ellipsis') !== false);
check('tabs stay a scrollable row', strpos($mob, 'flex: 0 0 auto') !== false && strpos($css, 'overflow-x: auto') !== false);
check('header children can shrink', strpos($css, '.nv-dash-head > * { min-width: 0; }') !== false);
exit($fail ? 1 : 0);
