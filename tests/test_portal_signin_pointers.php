<?php
// Employees must be pointed at the Employee Portal sign-in, not the staff login.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
check('staff login links to the employee portal', strpos(file_get_contents("$root/admin/login.php"), 'portal/employee_login.php') !== false);
check('invitation email gives the portal sign-in address', preg_match('~function send_employee_portal_invitation.*?portal/employee_login\.php~s', file_get_contents("$root/classes/Emailer.php")) === 1);
exit($fail ? 1 : 0);
