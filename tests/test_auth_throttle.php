<?php
// Sign-in and password-reset hardening.
$root = dirname(__DIR__);
require_once "$root/src/Services/AuthThrottleService.php";
use App\Services\AuthThrottleService as T;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$now = 1_000_000;
check('under the limit is allowed', T::retryAfter([$now - 10, $now - 20, $now - 30, $now - 40], $now, 5) === 0);
check('at the limit is blocked', T::retryAfter([$now - 10, $now - 20, $now - 30, $now - 40, $now - 50], $now, 5) > 0);
check('wait runs until the oldest failure ages out', T::retryAfter([$now - 800, $now - 20, $now - 30, $now - 40, $now - 50], $now, 5) === 100);
check('old failures outside the window do not count', T::retryAfter([$now - 2000, $now - 1900, $now - 1800, $now - 1700, $now - 1600], $now, 5) === 0);
check('one extra failure needs the 2nd oldest to age out', T::retryAfter([$now - 800, $now - 700, $now - 30, $now - 40, $now - 50, $now - 60], $now, 5) === 200);
check('blocked for at least a second', T::retryAfter(array_fill(0, 5, $now), $now, 5) >= 1);
check('no failures, no wait', T::retryAfter([], $now, 5) === 0);
check('identifier is case-insensitive and trimmed', T::identifier('  Admin@X.com ') === 'admin@x.com');
check('wait text', T::waitText(30) === '30 seconds' && T::waitText(60) === '1 minute' && T::waitText(901) === '16 minutes');
check('scopes have three tiers, user limit above pair limit', count(T::LIMITS) === 3 && T::LIMITS['login']['user'] > T::LIMITS['login']['pair']);

$login = file_get_contents("$root/classes/Login.php");
check('login is throttled and records failures', strpos($login, "check('login'") !== false && strpos($login, "record('login'") !== false && strpos($login, "clear('login'") !== false);
check('login no longer leaks the database error', strpos($login, "'error'=>\$this->conn->error") === false);
check('reset code uses a secure generator', strpos($login, 'random_int(0, 999999)') !== false && !preg_match('/rand\(\s*100000/', $login));
check('reset code stored hashed and compared in constant time', strpos($login, "hash('sha256', \$otp_code)") !== false && strpos($login, 'hash_equals') !== false);
check('reset code dies after repeated wrong guesses', strpos($login, '`attempts` >= 5') !== false);
check('code request does not reveal whether the account exists', substr_count($login, 'If an account exists') === 1 && strpos($login, 'No user account found') === false);
check('reset does not trust a user id from the browser', strpos($login, "\$_POST['user_id']") === false);
check('reset and request are throttled', strpos($login, "check('otp_request'") !== false && strpos($login, "check('otp_verify'") !== false);
check('new password length enforced on reset', strpos($login, 'strlen($password) < 8') !== false);
check('code request no longer returns user_id', strpos($login, "'user_id' => \$user_id") === false);
$ui = file_get_contents("$root/admin/login.php");
check('login page handles the locked response', strpos($ui, "resp.status == 'locked'") !== false);
check('reset form sends the username, not a user id', strpos($ui, 'otp_user_id') === false && strpos($ui, 'id="otp_email"') !== false);
$mig = file_get_contents("$root/database/migrations/022_auth_throttle.sql");
check('migration: attempts table, wider code column, attempt counter', strpos($mig, 'auth_attempts') !== false && strpos($mig, 'varchar(64)') !== false && strpos($mig, 'ADD COLUMN `attempts`') !== false && !preg_match('/^--.*;/m', $mig));
exit($fail ? 1 : 0);
