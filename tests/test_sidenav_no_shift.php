<?php
// Collapsed sidebar must not expand on hover/focus, and submenu links must
// not carry the nav-flat left border that rendered as "(" marks.
$root = dirname(__DIR__);
$thm = file_get_contents($root . '/dist/css/nuvis-theme.css');
$js  = file_get_contents($root . '/dist/js/adminlte.js');
$nav = file_get_contents($root . '/admin/inc/navigation.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('the aside actually carries sidebar-no-expand', strpos($nav, 'main-sidebar elevation-1 sidebar-no-expand') !== false);
check('collapsed rail width pinned on hover and focus', (bool)preg_match('/sidebar-no-expand:hover,[^{]*sidebar-no-expand\.sidebar-focused,[^{]*\{\s*width: 4\.6rem !important/s', $thm));
check('labels stay hidden while the rail is narrow', (bool)preg_match('/sidebar-no-expand\.sidebar-focused \.nav-sidebar \.nav-link p[^{]*\{\s*display: none !important/s', $thm));
check('focus no longer marks a no-expand sidebar as focused', strpos($js, "hasClass('sidebar-no-expand')) { return; }") !== false);
check('hover search-reveal rule removed with the expansion', strpos($thm, ':hover .nv-side-search { display: block') === false);
check('submenu left border ("(" marks) removed', strpos($thm, '.nav-treeview .nav-item > .nav-link { border-left: 0 !important; }') !== false);
check('icon offset compensation undone with the border', strpos($thm, '.nav-flat .nav-treeview .nav-icon { margin-left: 0 !important; }') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
