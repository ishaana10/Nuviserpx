<?php
// Security round 2: no SQL errors to the browser, CSRF everywhere, guarded
// integer lists, hardened session cookies, CI with static analysis.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// 3. No raw mysqli errors in any response path
$bad = [];
foreach (array_merge(glob($root.'/src/Services/*.php'), glob($root.'/src/Support/*.php'), glob($root.'/src/Database/*.php'), glob($root.'/classes/*.php')) as $f) {
    if (basename($f) === 'Db.php') continue;
    $s = file_get_contents($f);
    if (preg_match("/(?:'msg'|'err'|'error'|\\\$resp\\[)[^\\n]*->error\\b/", $s) || preg_match('/RuntimeException\((?:\$this->conn|\$conn)->error/', $s)) {
        $bad[] = basename($f);
    }
}
check('no endpoint returns or throws raw mysqli errors', $bad === [] || print_r($bad, true) === '');
$db = file_get_contents($root.'/src/Support/Db.php');
check('Db helper logs the real error server-side', strpos($db, 'error_log') !== false && strpos($db, 'public static function fail') !== false && strpos($db, 'public static function oops') !== false);

// 1. CSRF coverage
$us = file_get_contents($root.'/classes/Users.php');
check('save_client and change_password verify CSRF', preg_match('/function save_client\(\)\{\s*\$this->settings->require_csrf\(\);/', $us) && preg_match('/function change_password\(\)\{\s*\$this->settings->require_login\(\);\s*\$this->settings->require_csrf\(\);/', $us));
$ma = file_get_contents($root.'/classes/Master.php');
check('Master dispatch verifies CSRF centrally on POST', strpos($ma, "REQUEST_METHOD'] === 'POST'") !== false && strpos($ma, 'require_csrf') !== false);
foreach (['Dashboard', 'UiPrefs'] as $f) {
    $s = file_get_contents($root."/classes/{$f}.php");
    check("{$f} endpoint verifies CSRF on POST", strpos($s, 'verify_csrf') !== false || strpos($s, 'require_csrf') !== false);
}

// 2. Integer lists are cast before interpolation
$pa = file_get_contents($root.'/src/Services/PayrollApprovalService.php');
$cn = file_get_contents($root.'/src/Services/CreditNoteService.php');
check('bulk approval/credit-note id lists are intval-mapped', substr_count($pa, "array_map('intval'") >= 4 && strpos($cn, "implode(',', array_map('intval', \$ids))") !== false && strpos($pa, "\$in = implode(',', \$ids);") === false);

// 4. Session hardening
$cf = file_get_contents($root.'/config.php');
check('secure-only cookies on HTTPS, proxy-aware', strpos($cf, "session.cookie_secure") !== false && strpos($cf, 'HTTP_X_FORWARDED_PROTO') !== false);
check('strict mode, idle lifetime and httponly set', strpos($cf, 'session.use_strict_mode') !== false && strpos($cf, 'session.gc_maxlifetime') !== false && strpos($cf, 'session.cookie_httponly') !== false);

// 5 & 6. CI, PHPStan, PHPUnit bridge
$ci = @file_get_contents($root.'/.github/workflows/ci.yml');
check('CI lints the repo and runs the suite via PHPUnit', $ci && strpos($ci, 'php -l') !== false && strpos($ci, 'phpunit -c phpunit.xml') !== false);
check('PHPStan level 5 configured for src/', $ci && strpos($ci, 'phpstan analyse') !== false && strpos((string)@file_get_contents($root.'/phpstan.neon'), 'level: 5') !== false);
check('PHPUnit bridge runs every legacy script as a test case', strpos((string)@file_get_contents($root.'/tests/phpunit/LegacySuiteTest.php'), "glob(dirname(__DIR__) . '/test_*.php')") !== false && file_exists($root.'/phpunit.xml'));

// Changelog
$cl = (string)@file_get_contents($root.'/CHANGELOG.md');
check('changelog exists and covers the current work', strpos($cl, '## [Unreleased]') !== false && strpos($cl, 'CSRF') !== false && strpos($cl, '2026-11-13') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
