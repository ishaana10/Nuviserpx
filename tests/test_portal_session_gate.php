<?php
// admin/inc/sess_auth.php must not send portal pages to the staff login (or bounce a signed-in admin).
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
function run(string $uri, bool $admin): string {
    global $root;
    $code = '$_SERVER["HTTP_HOST"]="x.test";$_SERVER["REQUEST_URI"]=' . var_export($uri, true) . ';'
        . 'function redirect($u=""){echo "REDIRECT:$u";} define("base_url","http://x.test/");'
        . ($admin ? '@session_name("NUVISERPXSESSID");@session_start();$_SESSION["userdata"]=["login_type"=>1];' : '')
        . 'require ' . var_export("$root/admin/inc/sess_auth.php", true) . ';echo "CONTINUED";';
    return (string)shell_exec('php -d session.save_path=/tmp -r ' . escapeshellarg($code) . ' 2>&1');
}
check('employee portal page, no staff session: not redirected', strpos(run('/nuviserpx/portal/employee.php', false), 'REDIRECT') === false);
check('employee login, signed-in admin: not bounced to admin', strpos(run('/nuviserpx/portal/employee_login.php?token=abc', true), 'REDIRECT') === false);
check('customer portal page: not redirected', strpos(run('/portal/index.php', false), 'REDIRECT') === false);
check('portal folder URL (/portal/), the dashboard address, is not redirected', strpos(run('/portal/', false), 'REDIRECT') === false);
check('portal folder URL with a nested base path is not redirected', strpos(run('/nuviserpx/portal/', false), 'REDIRECT') === false);
check('staff page without session still goes to staff login', strpos(run('/nuviserpx/admin/index.php', false), 'REDIRECT:admin/login.php') !== false);
check('a path merely containing "portal" is not exempt', strpos(run('/nuviserpx/admin/portal_settings.php', false), 'REDIRECT:admin/login.php') !== false);
exit($fail ? 1 : 0);
