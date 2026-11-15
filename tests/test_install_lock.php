<?php
$root = dirname(__DIR__);
$f = 0; function check($n, $c) { global $f; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $f++; }
check('the install lock is not shipped in the repository', !file_exists("$root/install/installed.lock") || trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files install/installed.lock 2>/dev/null')) === '');
check('and is ignored by git so it can never be committed again', (bool)preg_match('/^install\/installed\.lock$/m', file_get_contents("$root/.gitignore")));
$i = file_get_contents("$root/initialize.php");
check('initialize.php recreates the lock for an existing system before redirecting', strpos($i, 'InstallState::adoptExisting') !== false && strpos($i, 'InstallState::adoptExisting') < strpos($i, 'install/index.php'));
check('the adoption only runs on the web, not in CLI scripts', (bool)preg_match("/adoptExisting|php_sapi_name\(\) !== 'cli'/", $i));
exit($f ? 1 : 0);
