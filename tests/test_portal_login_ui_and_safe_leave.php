<?php
// Employee portal: readable branded sign-in with a way back, and leave actions that always answer in JSON.
$root = dirname(__DIR__) . '/';
$fail = 0;
function check($label, $ok) { global $fail; echo ($ok ? "PASS" : "FAIL") . ": $label\n"; if (!$ok) { $fail++; } }
$r = fn($f) => file_get_contents($root . $f);

$l = $r('portal/employee_login.php');
check('login shows the company logo', strpos($l, "validate_image(\$_settings->info('logo'))") !== false);
check('title has its own dark colour (not a theme link colour)', strpos($l, '.ep-title { color: #0f172a') !== false && strpos($l, 'class="h1"') === false);
check('link back to the main portal', strpos($l, 'admin/login.php') !== false && strpos($l, 'Back to main portal') !== false);
check('both views still wired', strpos($l, "wire('#emp-login-frm'") !== false && strpos($l, "wire('#activate-frm'") !== false);
check('page markup is balanced', substr_count($l, '<div') === substr_count($l, '</div>'));

$m = $r('classes/Master.php');
check('safe wrapper discards stray output and catches Throwable', strpos($m, 'function employee_portal_safely(callable $run)') !== false && strpos($m, 'ob_end_clean()') !== false && strpos($m, 'catch (\Throwable $e)') !== false);
foreach (['employee_portal_leave_apply', 'employee_portal_leave_preview', 'employee_portal_leave_cancel'] as $a) {
    preg_match('~function ' . $a . '\(\)\s*\{(.*?)\n    \}\n~s', $m, $mm);
    check("$a runs inside the safe wrapper", isset($mm[1]) && strpos($mm[1], 'employee_portal_safely(') !== false);
}
$js = $r('dist/js/nuvis-portal-leave.js');
check('form shows HTTP status and server detail on failure', strpos($js, 'xhr.status') !== false && strpos($js, 'r.debug') !== false);

check('changelog mentions the sign-in page', stripos($r('CHANGELOG.md'), 'Back to main portal') !== false);
exit($fail ? 1 : 0);
