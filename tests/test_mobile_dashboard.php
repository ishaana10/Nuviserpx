<?php
// Source-wiring checks for the phone dashboard layout and widget rearranging. Run: php tests/test_mobile_dashboard.php
$root = dirname(__DIR__);
$fail = 0;
function check($ok, $msg) { global $fail; if (!$ok) { $fail++; echo "FAIL: $msg\n"; } else { echo "ok: $msg\n"; } }
$js  = file_get_contents("$root/dist/js/nuvis-dashboard.js");
$css = file_get_contents("$root/dist/css/nuvis-dashboard.css");
$ui  = file_get_contents("$root/dist/css/nuvis-ui.css");
$top = file_get_contents("$root/admin/inc/topBarNav.php");
$home = file_get_contents("$root/admin/home.php");

check(strpos($js, 'data-act="move" data-val="-1"') !== false && strpos($js, 'data-act="move" data-val="1"') !== false, 'widgets have up/down move buttons in edit mode');
check(strpos($js, 'function enableTouchDrag') !== false && strpos($js, 'enableTouchDrag();') !== false, 'touch drag shim is defined and started');
check(strpos($js, "sm: 'col-6 col-sm-6 col-xl-3'") !== false, 'small widgets are two per row on phones');
check(strpos($js, 'function syncOrderButtons') !== false, 'first/last move buttons are disabled');
check(strpos($css, 'touch-action: none') !== false, 'drag handle blocks page scroll while dragging');
check(strpos($css, 'grid-template-columns: 44px 44px 1fr 1fr') !== false, 'dashboard controls use a phone grid');
check(strpos($css, 'position: sticky') !== false, 'edit banner stays visible while customising');
check(strpos($ui, '.sidebar-mini-md .content-wrapper') !== false && strpos($ui, 'margin-left: 0 !important') !== false, 'no empty left gutter on phones');
check(strpos($top, 'user-name') !== false && strpos($ui, '.main-header .user-name { display: none; }') !== false, 'top bar user name hidden on phones');
check(strpos($home, 'arrows') !== false, 'customise banner mentions arrows');
exit($fail ? 1 : 0);
