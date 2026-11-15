<?php
$root = dirname(__DIR__);
$f = 0; function check($n, $c) { global $f; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $f++; }
$cfg = file_get_contents("$root/config.php"); $ht = file_get_contents("$root/.htaccess");
check('PHP responses send a Content-Security-Policy that blocks plugins and foreign framing', strpos($cfg, "Content-Security-Policy: object-src 'none'; frame-ancestors 'self'") !== false);
check('Apache sends the same policy', strpos($ht, "Content-Security-Policy \"object-src 'none'; frame-ancestors 'self'\"") !== false);
$au = file_get_contents("$root/api/v1/auth.php");
check('the API looks keys up through ApiKey (hash), not the plain column', strpos($au, 'ApiKey::find(') !== false && strpos($au, 'WHERE api_key =') === false);
$rs = file_get_contents("$root/src/Services/RoleService.php");
check('new keys are stored hashed and returned once', strpos($rs, 'ApiKey::generate()') !== false && strpos($rs, "`key_hash` = '{\$newKey['hash']}'") !== false && strpos($rs, "\$resp['api_key'] = \$newKey['key']") !== false);
check('the unused API secret is no longer generated or shown', strpos($rs, 'api_secret = bin2hex') === false && strpos(file_get_contents("$root/admin/api_keys/manage_api_key.php"), 'API Secret') === false);
check('the key list never prints the stored plain key directly', strpos(file_get_contents("$root/admin/api_keys/index.php"), "htmlspecialchars(\$row['api_key'])") === false);
check('the migration is additive (no DROP, nothing wiped)', !preg_match('/\bDROP\b|api_key`\s*=\s*\'\'/i', file_get_contents("$root/database/migrations/046_api_key_hash.sql")));
check('the scrub is a separate command that needs --yes', strpos(file_get_contents("$root/bin/scrub-api-keys.php"), "'--yes'") !== false);
exit($f ? 1 : 0);
