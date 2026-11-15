<?php
// Employee portal: cache-busted assets and the employee's own change-password.
$root = dirname(__DIR__) . '/';
$fail = 0;
function check($label, $ok) { global $fail; echo ($ok ? "PASS" : "FAIL") . ": $label\n"; if (!$ok) { $fail++; } }
$r = fn($f) => file_get_contents($root . $f);

$layout = $r('portal/_employee_layout.php');
$leave  = $r('portal/employee_leave.php');
check('portal css versioned by file time, not by date', strpos($layout, "filemtime(base_app . 'dist/css/nuvis-portal.css')") !== false && strpos($layout, "date('Ymd')") === false);
check('leave js versioned by file time', strpos($leave, "filemtime(base_app . 'dist/js/nuvis-portal-leave.js')") !== false && strpos($leave, "date('Ymd')") === false);
check('menu has Change Password', strpos($layout, 'employee_password.php') !== false);

$page = $r('portal/employee_password.php');
check('page posts to the change-password action', strpos($page, 'employee_portal_change_password') !== false);
check('page uses autocomplete hints', strpos($page, 'autocomplete="current-password"') !== false && substr_count($page, 'autocomplete="new-password"') === 2);

$m = $r('classes/Master.php'); $p = $r('src/Support/Permissions.php');
check('action whitelisted', strpos($m, "'employee_portal_change_password'") !== false);
check('action is public (portal session, not staff)', strpos($p, "'employee_portal_change_password'") !== false);
check('action reads identity from portal session only', preg_match('/function employee_portal_change_password\(\)\s*\{\s*\$who = \$this->employee_portal_identity\(\);/', $m) === 1);

$s = $r('src/Services/EmployeePortalService.php');
check('service verifies current password', strpos($s, 'password_verify($current') !== false);
check('wrong current password counts as failed attempt', strpos($s, 'fail_attempt($portalUserId') !== false);
check('new password rules reused', strpos($s, 'self::passwordErrors($new') !== false);
check('new must differ from current', strpos($s, '$new === $current') !== false);
check('bcrypt hash and attempts reset', strpos($s, 'PASSWORD_BCRYPT') !== false && strpos($s, 'failed_attempts = 0') !== false);
check('change is logged', strpos($s, "'password_changed'") !== false);
check('no bind_param on properties', !preg_match('/bind_param\([^)]*\$this->/', $s));

$cl = $r('CHANGELOG.md');
check('changelog mentions change password', stripos($cl, 'change their own password') !== false || stripos($cl, 'change password') !== false);
exit($fail ? 1 : 0);
