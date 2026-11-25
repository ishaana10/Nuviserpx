<?php
/**
 * Redis is kept running automatically and any trouble goes to the application's error log
 * (logs/php_errors.log, shown on Developer > Error Logs). Run: php tests/test_redis_watch.php
 */
require_once(__DIR__ . '/../vendor/autoload.php');
$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $name\n"; if (!$ok) $fail++; }
use App\Services\RedisWatch as W;

$tmp = sys_get_temp_dir() . '/nvwatch_' . bin2hex(random_bytes(4));
mkdir("$tmp/home/.redis", 0777, true);
$state = "$tmp/state.json"; $log = "$tmp/php_errors.log";
ini_set('log_errors', '1'); ini_set('error_log', $log);
$opt = ['homes' => ["$tmp/home"], 'state' => $state, 'tcp' => false, 'socket' => ''];
$logText = fn() => is_file($log) ? (string)file_get_contents($log) : '';
$t = 1000000;

$r1 = W::tick(null, $t, $opt);
check('first run creates the start flag', is_file("$tmp/home/.redis.on") && is_array($r1) && $r1['state'] === 'starting');
check('first run logs what it did', strpos($logText(), '[Redis]') !== false && strpos($logText(), '.redis.on') !== false);
check('runs again within five minutes do nothing', W::tick(null, $t + 120, $opt) === null);

$r2 = W::tick(null, $t + 400, $opt);
check('still waiting after a normal start delay is not an error yet', $r2['state'] === 'starting' && strpos($logText(), 'still not running') === false);
$r3 = W::tick(null, $t + 1200, $opt);
check('not running 20 minutes after the flag is an error with a reason', $r3['state'] === 'not_running' && strpos($logText(), 'still not running') !== false && stripos($logText(), 'Turbo') !== false);
$n = substr_count($logText(), 'still not running');
W::tick(null, $t + 1800, $opt);
check('the same error is not repeated every five minutes', substr_count($logText(), 'still not running') === $n);
W::tick(null, $t + 1200 + 22000, $opt);
check('it is repeated after six hours', substr_count($logText(), 'still not running') === $n + 1);

// a live socket: recovery is logged and nothing is touched
$sock = "$tmp/home/.redis/redis.sock";
$srv = @stream_socket_server("unix://$sock", $e1, $e2);
check('test can listen on a socket', $srv !== false);
$r4 = W::tick(null, $t + 60000, $opt);
check('a live Redis is reported up', $r4['state'] === 'up');
check('recovery is logged', strpos($logText(), 'recovered') !== false);
check('after recovery the state is clean', empty(json_decode(file_get_contents($state), true)['down_since']));

// switched off
@fclose($srv); @unlink($sock);
class OffSettings { public function info($k = '') { return $k === 'redis_auto' ? '0' : false; } }
check('can be switched off in settings', W::tick(new OffSettings(), $t + 90000, $opt) === null);

// an account folder that cannot be written is logged, not hidden
$bad = ['homes' => ["$tmp/nope/none"], 'state' => "$tmp/state2.json", 'tcp' => false, 'socket' => ''];
file_put_contents($log, '');
$r5 = W::tick(null, $t, $bad);
check('a start flag that cannot be created is logged', strpos($logText(), 'Could not create') !== false);
check('the watcher runs from config.php on normal requests', strpos(file_get_contents(dirname(__DIR__) . '/config.php'), 'RedisWatch::tick') !== false);
check('the Developer page points to the error log', strpos(file_get_contents(dirname(__DIR__) . '/admin/developer/git_update.php'), 'developer/error_logs') !== false);
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
