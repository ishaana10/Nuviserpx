<?php
// Developer error logs: capture, parsing, grouping, clearing, wiring.
$tmp = sys_get_temp_dir() . '/nv_errlog_' . getmypid() . '/';
@mkdir($tmp, 0775, true);
define('base_app', $tmp);
require_once __DIR__ . '/../src/Support/ErrorLog.php';

use App\Support\ErrorLog;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// PHP log parsing: severity, file:line, stack continuation, grouping, newest first.
file_put_contents(ErrorLog::path('php'),
"[11-Nov-2026 09:00:00 Pacific/Fiji] PHP Warning: Undefined array key \"x\" in /app/admin/foo.php on line 10\n" .
"[11-Nov-2026 09:05:00 Pacific/Fiji] PHP Warning: Undefined array key \"x\" in /app/admin/foo.php on line 10\n" .
"[12-Nov-2026 14:00:00 Pacific/Fiji] PHP Fatal error: Uncaught Error: Call to a member function f() on null in /app/classes/Bar.php:55\n" .
"Stack trace:\n#0 /app/index.php(3): run()\n#1 {main}\n");
$php = ErrorLog::read('php');
check('identical PHP errors group with a count', count($php) === 2 && $php[1]['count'] === 2);
check('newest error listed first', strpos($php[0]['message'], 'Fatal error') !== false);
check('severity recognised', $php[0]['severity'] === 'Fatal error' && $php[1]['severity'] === 'Warning');
check('file and line parsed', $php[0]['file'] === '/app/classes/Bar.php' && $php[0]['line'] === 55 && $php[1]['line'] === 10);
check('stack trace kept as detail', strpos($php[0]['detail'], '#0 /app/index.php(3)') !== false);

// JS log: JSON lines round-trip.
ErrorLog::append('js', ['time' => '2026-11-12 14:01:00', 'message' => 'x is not defined', 'source' => '/admin/?page=crm', 'line' => 12, 'stack' => 'ReferenceError: x', 'page' => 'http://t/admin/?page=crm', 'user' => 'admin', 'agent' => 'UA']);
ErrorLog::append('js', ['time' => '2026-11-12 14:02:00', 'message' => 'x is not defined', 'source' => '/admin/?page=crm', 'line' => 12, 'stack' => '', 'page' => '', 'user' => '', 'agent' => '']);
$js = ErrorLog::read('js');
check('JS errors parsed and grouped', count($js) === 1 && $js[0]['count'] === 2 && $js[0]['message'] === 'x is not defined');

ErrorLog::clear('php');
check('clear empties the log', ErrorLog::read('php') === []);

// Wiring.
$root = dirname(__DIR__);
$cfg = file_get_contents($root . '/config.php');
$ftr = file_get_contents($root . '/admin/inc/footer.php');
$mst = file_get_contents($root . '/classes/Master.php');
$prm = file_get_contents($root . '/src/Support/Permissions.php');
$nav = file_get_contents($root . '/admin/inc/navigation.php') . implode('', array_map('file_get_contents', glob($root . '/admin/inc/menus/*.php')));
check('PHP errors routed to logs/php_errors.log before DB connect', strpos($cfg, "ini_set('error_log'") !== false && strpos($cfg, 'php_errors.log') < strpos($cfg, "require_once('classes/DBConnection.php')"));
check('log folder blocked from the web', strpos($cfg, 'Require all denied') !== false);
check('browser errors reported from every admin page', strpos($ftr, 'f=log_js_error') !== false && strpos($ftr, 'unhandledrejection') !== false);
check('reporter is capped and deduplicated', strpos($ftr, 'sent >= 10 || seen[p.message]') !== false);
check('endpoints exist', strpos($mst, "'log_js_error',") !== false && strpos($mst, "'clear_error_log',") !== false);
check('clearing is developer-only', (bool)preg_match("/'clear_error_log'\s*=>\s*\[self::SUPER_ONLY\]/", $prm));
check('Error Logs page in the developer menu', strpos($nav, 'developer/error_logs') !== false);
check('page shows both logs with clear and copy', ($p = file_get_contents($root . '/admin/developer/error_logs.php')) && strpos($p, 'nv-clear-log') !== false && strpos($p, 'nv-copy-err') !== false && strpos($p, "ErrorLog::read('js')") !== false);

array_map('unlink', glob($tmp . 'logs/*') ?: []);
@rmdir($tmp . 'logs'); @rmdir($tmp);
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
