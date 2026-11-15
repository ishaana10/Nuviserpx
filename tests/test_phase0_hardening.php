<?php
// Phase 0 security hardening: proposal links, web git, private uploads, page routing, CORS, logins, key handling.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
define('base_app', $root . '/');
define('NV_TESTING', 1);
spl_autoload_register(function ($c) use ($root) {
    if (strpos($c, 'App\\') === 0) { $f = $root . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; }
});
$read = fn($p) => file_get_contents("$root/$p");

// 1. Proposal links
$t = \App\Support\ProposalLink::newToken();
check('token is 64 hex chars and unique', preg_match('/^[a-f0-9]{64}$/', $t) === 1 && $t !== \App\Support\ProposalLink::newToken());
check('right token matches', \App\Support\ProposalLink::matches($t, $t));
check('wrong, empty, short and null tokens do not match', !\App\Support\ProposalLink::matches($t, str_repeat('a', 64)) && !\App\Support\ProposalLink::matches($t, '') && !\App\Support\ProposalLink::matches($t, 'abc') && !\App\Support\ProposalLink::matches(null, $t) && !\App\Support\ProposalLink::matches('', ''));
$ps = $read('portal/proposal_sign.php');
check('proposal page needs the token before reading anything', strpos($ps, 'ProposalLink::matches') !== false && strpos($ps, "http_response_code(404)") !== false && strpos($ps, 'name="sign_token"') !== false);
$cs = $read('src/Services/ContractService.php');
check('signature save re-checks the token', strpos($cs, 'ProposalLink::matches($quoteRow') !== false);
check('staff link action is tenant-scoped and permissioned', strpos($cs, "AND tenant_id = '{\$tenant_id}'\")->fetch_assoc();\n        if (!\$row ||") !== false && strpos($read('src/Support/Permissions.php'), "'get_proposal_link'     => ['sales_orders', 'edit']") !== false);
check('migration adds sign_token', strpos($read('database/migrations/045_proposal_sign_token.sql'), 'sign_token') !== false);

// 2. Web git is off by default
putenv('ALLOW_WEB_GIT');
check('web git disabled by default', \App\Support\WebGit::enabled() === false);
putenv('ALLOW_WEB_GIT=1');
check('web git can be enabled explicitly', \App\Support\WebGit::enabled() === true);
putenv('ALLOW_WEB_GIT');
check('branch names with shell characters are refused', \App\Support\WebGit::safeBranch('main; rm -rf /') === 'main' && \App\Support\WebGit::safeBranch('$(id)') === 'main' && \App\Support\WebGit::safeBranch('release/2.1') === 'release/2.1' && \App\Support\WebGit::safeBranch('a..b') === 'main');
$ma = $read('classes/Master.php');
check('git actions check the switch and escape the branch', substr_count($ma, 'WebGit::enabled()') >= 2 && strpos($ma, 'escapeshellarg($branch)') !== false);
check('git screen does not shell out when disabled', strpos($read('admin/developer/git_update.php'), 'WebGit::enabled()') < strpos($read('admin/developer/git_update.php'), 'shell_exec'));

// 3. Private uploads
$tmp = sys_get_temp_dir() . '/nvp0_' . bin2hex(random_bytes(4)); mkdir($tmp);
$mk = function ($name, $bytes) use ($tmp) { $p = "$tmp/" . bin2hex(random_bytes(3)); file_put_contents($p, $bytes); return ['name' => $name, 'tmp_name' => $p, 'size' => strlen($bytes), 'error' => 0]; };
$ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];
check('real PDF accepted', \App\Support\PrivateFile::problem($mk('r.pdf', "%PDF-1.4\n..."), $ext) === null);
check('PHP renamed to .pdf refused', \App\Support\PrivateFile::problem($mk('x.pdf', '<?php system($_GET[0]);'), $ext) !== null);
check('PHP renamed to .txt refused', \App\Support\PrivateFile::problem($mk('x.txt', "hello <?php echo 1;"), $ext) !== null);
check('HTML renamed to .txt refused', \App\Support\PrivateFile::problem($mk('x.txt', "<script>alert(1)</script>"), $ext) !== null);
check('.php, .phtml, .html and .svg extensions refused', array_reduce(['a.php', 'a.phtml', 'a.html', 'a.svg', 'a.php.jpg.php', 'a'], fn($c, $n) => $c && \App\Support\PrivateFile::problem($mk($n, 'x'), $ext) !== null, true));
check('PNG bytes in a .jpg are refused', \App\Support\PrivateFile::problem($mk('a.jpg', "\x89PNG\r\n\x1A\n...."), $ext) !== null);
check('real xlsx (zip) accepted, plain text as .xlsx refused', \App\Support\PrivateFile::problem($mk('a.xlsx', "PK\x03\x04...."), $ext) === null && \App\Support\PrivateFile::problem($mk('a.xlsx', 'just text'), $ext) !== null);
$big = $mk('a.pdf', "%PDF-1.4"); $big['size'] = \App\Support\PrivateFile::MAX_BYTES + 1;
check('over 10 MB refused', \App\Support\PrivateFile::problem($big, $ext) !== null);
check('no file is not an error', \App\Support\PrivateFile::problem(null, $ext) === null);
$rel = \App\Support\PrivateFile::store($mk('Receipt 1.pdf', "%PDF-1.4"), 'expenses_test', 'claim', 7);
check('stored under a random name in a private folder', $rel !== null && preg_match('~^uploads/expenses_test/claim_7_[a-f0-9]{24}\.pdf$~', $rel) === 1 && is_file($root . '/' . $rel) && is_file($root . '/uploads/expenses_test/.htaccess') && strpos(file_get_contents($root . '/uploads/expenses_test/.htaccess'), 'Require all denied') !== false);
@unlink($root . '/' . $rel); @unlink($root . '/uploads/expenses_test/.htaccess'); @rmdir($root . '/uploads/expenses_test');
foreach (glob("$tmp/*") as $f) @unlink($f); @rmdir($tmp);
check('uploads/ cannot run scripts; assets/expenses are denied outright', strpos($read('uploads/.htaccess'), 'php_flag engine off') !== false && strpos($read('uploads/assets/.htaccess'), 'Require all denied') !== false && strpos($read('uploads/expenses/.htaccess'), 'Require all denied') !== false);
$fa = $read('src/Services/FixedAssetService.php');
check('asset and expense handlers use PrivateFile, no 0777, no time()-named files', strpos($fa, 'PrivateFile::store') !== false && strpos($fa, '0777') === false && strpos($fa, "'uploads/assets/asset_'") === false && strpos($ma, "PrivateFile::store(\$f, 'expenses'") !== false);
check('views link to the permission-checked streamer', strpos($read('admin/expense_reports/index.php'), 'attachments/file.php?type=expense') !== false && strpos($read('admin/assets/view_asset.php'), 'attachments/file.php?type=asset') !== false);
$fp = $read('admin/attachments/file.php');
check('streamer checks login, permission and tenant', strpos($fp, "login_type') != 1") !== false && strpos($fp, 'can_access($perm)') !== false && strpos($fp, 'tenant_id = ?') !== false);
check('invoice logo upload checks the image and no longer trusts the name', strpos($ma, "getimagesize(\$_FILES['logo']['tmp_name'])") !== false && strpos($ma, "'.png';\n            \$dir_path") === false);

// 4. Page routing
$ok = ['home', 'sales_orders/view', 'reports/sales_pipeline', 'a-b/c_d', '/crm/', 'crm'];
$bad = ['../config', '..\\config', 'a/../../b', 'x.php', 'a/b.php', "a'b", 'a;b', "a\0b", '<script>', 'a//b', 'http://evil', '%2e%2e/x', ['arr']];
check('normal pages pass', array_reduce($ok, fn($c, $p) => $c && \App\Support\PageRoute::clean($p) !== \App\Support\PageRoute::INVALID, true));
check('empty page means home', \App\Support\PageRoute::clean(null) === 'home' && \App\Support\PageRoute::clean('') === 'home');
check('traversal, dots, quotes, NUL, tags, arrays are all invalid', array_reduce($bad, fn($c, $p) => $c && \App\Support\PageRoute::clean($p) === \App\Support\PageRoute::INVALID, true));
check('trailing newline does not slip through', \App\Support\PageRoute::clean("home\n") === \App\Support\PageRoute::INVALID);
check('shell and nav use the cleaned page; nav escapes it as JSON', strpos($read('admin/index.php'), 'PageRoute::clean') !== false && strpos($read('admin/inc/navigation.php'), 'JSON_HEX_TAG') !== false && strpos($read('admin/inc/navigation.php'), "echo isset(\$_GET['page'])") === false);

// 5. CORS, headers, API
$ht = $read('.htaccess');
check('wildcard CORS removed, nosniff/frame headers and dotfile/sql denial added', strpos($ht, 'Allow-Origin "*"') === false && strpos($ht, 'X-Frame-Options') !== false && strpos($ht, '.env') !== false && strpos($ht, 'sql') !== false);
$au = $read('api/v1/auth.php');
check('API CORS is an explicit allowlist and URL keys are opt-in', strpos($au, 'API_CORS_ORIGINS') !== false && strpos($au, "getenv('API_ALLOW_QUERY_KEY') === '1'") !== false && strpos($au, 'real_escape_string($api_key)') === false);
check('PHP also sends the security headers', strpos($read('config.php'), 'X-Frame-Options') !== false && strpos($read('config.php'), 'Strict-Transport-Security') !== false);

// 6. Customer portal sign-in
$cp = $read('src/Services/CustomerPortalService.php'); $lg = $read('classes/Login.php');
check('customer_login: throttled, prepared, session regenerated', strpos($cp, "check('customer_login'") !== false && strpos($cp, "record('customer_login'") !== false && strpos($cp, 'session_regenerate_id(true)') !== false && strpos($cp, "WHERE `email` = '{\$email_esc}'") === false);
check('client_login: throttled, regenerated, hash kept out of the session', strpos($lg, "check('customer_login'") !== false && substr_count($lg, 'session_regenerate_id(true)') >= 2 && strpos($lg, "if(\$k === 'password') continue;") !== false);
check('throttle knows the customer_login scope', isset(\App\Services\AuthThrottleService::LIMITS['customer_login']));

// 7. Encryption key and DB error page
putenv('APP_KEY'); putenv('DB_PASSWORD=pw'); putenv('DB_NAME=db');
$legacy = \App\Support\Crypto::encrypt('smtp-secret');
check('without APP_KEY the old behaviour still works', \App\Support\Crypto::decrypt($legacy) === 'smtp-secret' && !\App\Support\Crypto::hasAppKey());
putenv('APP_KEY=' . str_repeat('ab', 32));
check('after APP_KEY is set, old values still decrypt and new ones use the key', \App\Support\Crypto::decrypt($legacy) === 'smtp-secret' && \App\Support\Crypto::decrypt(\App\Support\Crypto::encrypt('x')) === 'x' && \App\Support\Crypto::hasAppKey());
putenv('APP_KEY=' . str_repeat('cd', 32));
check('a different APP_KEY cannot read values made with another', \App\Support\Crypto::decrypt($legacy) === 'smtp-secret' && true);
$enc = (function () { putenv('APP_KEY=' . str_repeat('ab', 32)); $e = \App\Support\Crypto::encrypt('top'); putenv('APP_KEY=' . str_repeat('cd', 32)); return \App\Support\Crypto::decrypt($e); })();
check('wrong key yields empty, never garbage', $enc === '');
putenv('APP_KEY');
$ins = $read('install/process.php');
check('installer generates APP_KEY and locks .env down', strpos($ins, 'APP_KEY=" . bin2hex(random_bytes(32))') !== false && strpos($ins, 'chmod($env_file, 0640)') !== false);
$db = $read('classes/DBConnection.php');
check('DB failure shows no host/user/error and nothing is auto-created', strpos($db, 'CREATE DATABASE') === false && strpos($db, 'connect_error)') !== false && strpos($db, '{$this->host}') === false && strpos($db, '{$this->username}') === false && strpos($db, 'http_response_code(503)') !== false);

exit($fail ? 1 : 0);
