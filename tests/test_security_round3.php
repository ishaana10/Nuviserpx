<?php
// Security round 3: admin files need a staff session, no reflected XSS in the legacy nav, certificates are private.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$cfg = file_get_contents($root . '/config.php');
check('config.php denies anonymous requests to admin files (401)', strpos($cfg, 'http_response_code(401)') !== false && strpos($cfg, "'session_ping.php'") !== false);
check('only the sign-in, ping, router and update page are open', preg_match("/\\\$open = \\['index\\.php', 'login\\.php', 'session_ping\\.php', 'update_database\\.php'\\];/", $cfg) === 1);
check('portal (login_type 2) sessions are not staff', strpos($cfg, "['login_type'] ?? 1) == 1") !== false);

$nav = file_get_contents($root . '/inc/navigation.php');
check('legacy navigation does not echo ?page into JavaScript raw', strpos($nav, "'<?php echo isset(\$_GET['page'])") === false && strpos($nav, 'json_encode') !== false);

$fs = file_get_contents($root . '/src/Services/FiscalizationService.php');
check('certificate gets a random name', strpos($fs, "cert_tenant_' . (int)\$tenant_id . '_' . bin2hex(random_bytes(") !== false);
check('certificate folder is closed to the web and not world-writable', strpos($fs, 'mkdir($dir_path, 0750') !== false && strpos($fs, 'Require all denied') !== false);
$ht = file_get_contents($root . '/uploads/.htaccess');
check('uploads/.htaccess refuses key and certificate files', preg_match('/p12\|pfx\|pem\|key/', $ht) === 1);

exit($fail ? 1 : 0);
