<?php
/**
 * The Redis socket and the A2 Hosting start flag follow the account the app is running under,
 * not a fixed account name. Run: php tests/test_redis_paths.php
 */
require_once(__DIR__ . '/../vendor/autoload.php');
$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $name\n"; if (!$ok) $fail++; }
use App\Services\RedisCacheService as R;

$tmp = sys_get_temp_dir() . '/nvredis_' . bin2hex(random_bytes(4));
mkdir("$tmp/homeA/.redis", 0777, true); mkdir("$tmp/homeB/.redis", 0777, true);
touch("$tmp/homeB/.redis/redis.sock");

check('a path given in settings wins', R::resolveSocketPath(["$tmp/homeA"], '/custom/r.sock') === '/custom/r.sock');
check('the live socket among the candidate homes is used', R::resolveSocketPath(["$tmp/homeA", "$tmp/homeB"], '') === "$tmp/homeB/.redis/redis.sock");
check('with no live socket the first home is used', R::resolveSocketPath(["$tmp/homeA", "$tmp/homeB-x"], '') === "$tmp/homeA/.redis/redis.sock");
check('with nothing known there is still a sensible path', R::resolveSocketPath([], '') === '');
check('home folders are found from the application folder', R::homeFromAppPath('/home/newacct/public_html/nuviserpx/') === '/home/newacct' && R::homeFromAppPath('/var/www/x/') === null);

$r = R::touchStartFlag(["$tmp/homeA", "$tmp/none/at/all", "$tmp/homeA"]);
check('the start flag is created in the real home', is_file("$tmp/homeA/.redis.on") && $r['created'] === ["$tmp/homeA/.redis.on"]);
check('a home that cannot be written is reported, not hidden', in_array("$tmp/none/at/all/.redis.on", $r['failed'], true));
check('no fixed old account name remains in the service', strpos(file_get_contents(dirname(__DIR__) . '/src/Services/RedisCacheService.php'), 'ictfjcom') === false);
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
