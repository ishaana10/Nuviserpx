<?php
// App runs on Fiji time end to end, and the duplicate login log is merged
// into the audit trail.
$root = dirname(__DIR__);
$cfg = file_get_contents($root . '/config.php');
$dbc = file_get_contents($root . '/classes/DBConnection.php');
$la  = file_get_contents($root . '/admin/settings/login_activity.php');
$nav = file_get_contents($root . '/admin/inc/navigation.php');
$rep = file_get_contents($root . '/admin/reports/index.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('PHP runs on Pacific/Fiji', substr_count($cfg, 'Pacific/Fiji') === 2 && strpos($cfg, 'Asia/Manila') === false);
check('MySQL session follows the app timezone', (bool)preg_match('/SET time_zone = \'" \. date\(\'P\'\)/', $dbc));
check('session timezone is set right after connecting', strpos($dbc, "SET time_zone") > strpos($dbc, 'SqlMode::apply'));
check('old login-activity links open the filtered audit trail', strpos($la, "\$_GET['module'] = 'Authentication'") !== false && strpos($la, "include __DIR__ . '/audit_logs.php'") !== false);
check('duplicate nav entry is gone', strpos($nav, 'Login Security Log') === false && strpos($nav, 'settings/login_activity') === false);
check('audit trail still in the nav', strpos($nav, 'settings/audit_logs') !== false);
check('reports hub points at the audit trail', strpos($rep, "'settings/audit_logs', 'Audit Trail'") !== false && strpos($rep, 'settings/login_activity') === false);

// Sanity: Fiji offset is what MySQL will be given.
date_default_timezone_set('Pacific/Fiji');
check('Fiji offset renders as a valid MySQL offset', (bool)preg_match('/^[+-]\d{2}:\d{2}$/', date('P')));

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
