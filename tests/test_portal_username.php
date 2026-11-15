<?php
// The employee portal username (the email address) is stated wherever the employee or HR needs it.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$login = file_get_contents("$root/portal/employee_login.php");
check('set-password page shows the username', strpos($login, "htmlspecialchars((string)\$invitation['email'])") !== false && strpos($login, 'username for signing in') !== false);
check('sign-in page explains the username', strpos($login, 'Your username is the email address') !== false);
$em = file_get_contents("$root/classes/Emailer.php");
check('invitation email states the username', preg_match('~function send_employee_portal_invitation.*?username: " \. htmlspecialchars\(\$to\)~s', $em) === 1);
$svc = file_get_contents("$root/src/Services/EmployeePortalService.php");
check('HR fallback message includes the username', strpos($svc, 'username for signing in is their email address: {$email}') !== false);
exit($fail ? 1 : 0);
