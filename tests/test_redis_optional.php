<?php
/**
 * Redis is optional: the app runs on file sessions without it. When a hosting plan does not provide it the Redis tab
 * says so plainly and Redis checks can be switched off. Run: php tests/test_redis_optional.php
 */
require_once(__DIR__ . '/../vendor/autoload.php');
$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $name\n"; if (!$ok) $fail++; }

class SystemSettings { public $v = []; public function info($k = '') { return $this->v[$k] ?? false; } }
$on = new SystemSettings();
$off = new SystemSettings(); $off->v['redis_auto'] = '0';
$sOn = (new \App\Services\RedisCacheService($on))->getStatus();
$sOff = (new \App\Services\RedisCacheService($off))->getStatus();
check('status says the checks are on by default', ($sOn['auto'] ?? null) === true);
check('status says the checks are off when switched off', ($sOff['auto'] ?? null) === false);

$ui = file_get_contents(dirname(__DIR__) . '/admin/developer/git_update.php');
check('the tab explains Redis is optional and the app uses file sessions', stripos($ui, 'file sessions') !== false);
check('the tab can switch Redis checks off and on', strpos($ui, 'redis_auto') !== false && strpos($ui, 'setRedisAuto') !== false);
check('a switched-off Redis is shown as a calm info state, not a warning', strpos($ui, 'Redis checks are off') !== false);
exit($fail ? 1 : 0);
