<?php
// High-priority security items: .env out of the repo, no documented default
// credentials, prepared statements in Login.php, installer password policy.
$root = dirname(__DIR__);
$gi = file_get_contents($root . '/.gitignore');
$rm = file_get_contents($root . '/README.md');
$lg = file_get_contents($root . '/classes/Login.php');
$ip = file_get_contents($root . '/install/process.php');
$ex = file_get_contents($root . '/.env.example');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('.env ignored (not commented out)', (bool)preg_match('/^\.env$/m', $gi));
check('logs folder ignored', (bool)preg_match('#^/logs/$#m', $gi));
check('.env no longer tracked by git', trim(shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files .env')) === '');
check('.env.example holds placeholders, not the real deployment', strpos($ex, 'change_me') !== false && strpos($ex, 'ict-fj.com') === false);
check('default credentials gone from the README', strpos($rm, 'admin123') === false && strpos($rm, 'no default credentials') !== false);
check('clone URL points at this repository', strpos($rm, 'Nuvis-payrollx') === false && strpos($rm, 'github.com/ishaana10/nuviserpx.git') !== false);
check('no interpolated queries left in Login.php', !preg_match('/->query\("[^"]*\{\$/', $lg));
check('last_login and OTP statements are prepared', substr_count($lg, '->prepare(') >= 10);
check('installer demands 8-72 chars with letters and numbers', strpos($ip, "strlen(\$admin_password) < 8") !== false && strpos($ip, "preg_match('/[A-Za-z]/'") !== false && strpos($ip, '8-72 characters') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
