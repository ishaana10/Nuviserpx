<?php
// The sidebar must be able to scroll when its menu is longer than the screen.
$root = dirname(__DIR__);
$css = file_get_contents("$root/dist/css/nuvis-theme.css");
$nav = file_get_contents("$root/admin/inc/navigation.php");
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
check('sidebar region scrolls vertically', (bool)preg_match('/\.main-sidebar \.sidebar\s*\{[^}]*overflow-y:\s*auto/', $css));
check('sidebar is a bounded flex column', (bool)preg_match('/\.main-sidebar\s*\{[^}]*display:\s*flex[^}]*top:\s*0[^}]*bottom:\s*0/', $css));
check('scrollbar wrapper layers are released', strpos($css, '.os-viewport') !== false && strpos($css, 'overflow: visible !important') !== false);
check('active link is scrolled into view', strpos($nav, 'scrollIntoView') !== false);
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
