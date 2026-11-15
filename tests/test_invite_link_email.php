<?php
// Employee invitation: the email is really attempted, and a failed email gives HR a copyable link.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$svc = file_get_contents("$root/src/Services/EmployeePortalService.php");
check('invite loads Emailer itself (not class_exists)', strpos($svc, "require_once(base_app . 'classes/Emailer.php')") !== false && strpos($svc, "class_exists('Emailer')") === false);
check('failed email returns the link as data', strpos($svc, "'link' => \$sent ? '' : \$url") !== false);
$cp = file_get_contents("$root/src/Services/CustomerPortalService.php");
check('customer portal invite requires the real Emailer path', strpos($cp, "require_once(base_app . 'classes/Emailer.php')") !== false && strpos($cp, "__DIR__ . '/Emailer.php'") === false);
$ui = file_get_contents("$root/admin/employees/index.php");
check('link shown in a copyable box with a Copy button', strpos($ui, 'inviteLinkInput') !== false && strpos($ui, 'readonly') !== false && strpos($ui, 'inviteLinkCopy') !== false);
check('invite result no longer an alert() of the link', strpos($ui, 'alert(resp.msg); location.reload()') === false);
exit($fail ? 1 : 0);
