<?php
// Client record access rules, the field allow-list, and the guards wired into Users.php.
$root = dirname(__DIR__);
require_once "$root/src/Support/ClientAccess.php";
use App\Support\ClientAccess as C;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('signed-out is refused', C::error(false, false, false, 0, 5) !== null);
check('staff with users_manage may change any client', C::error(true, true, true, 1, 5) === null);
check('staff without users_manage is refused', C::error(true, true, false, 1, 5) !== null);
check('a client may change their own record', C::error(true, false, false, 5, 5) === null);
check("a client may not change someone else's", C::error(true, false, false, 5, 6) !== null);
check('a client may not create a record', C::error(true, false, false, 5, 0) !== null);

$p = C::pick(['firstname' => 'A', 'email' => 'a@x.com', 'status' => '1', 'type' => '1', 'avatar' => 'x', 'delete_flag' => '1', 'id' => '9', 'password' => 'p']);
check('only profile fields are kept', array_keys($p) === ['firstname', 'email']);
check('values come back as strings', C::pick(['contact' => 123])['contact'] === '123');
check('crafted keys are ignored', C::pick(['x` = 1, `status' => '1']) === []);

$u = file_get_contents("$root/classes/Users.php");
$sc = substr($u, strpos($u, 'public function save_client'), strpos($u, 'public function delete_client') - strpos($u, 'public function save_client'));
check('profile save no longer writes every posted field', strpos($sc, 'foreach($_POST') === false);
check('session is not filled from every posted field', substr_count($u, 'ClientAccess::pick($_POST)') === 2);
$del = substr($u, strpos($u, 'public function delete_client'), 400);
$ver = substr($u, strpos($u, 'public function verify_client'), 400);
check('delete_client needs users_manage and a CSRF token', strpos($del, "require_permission('users_manage')") !== false && strpos($del, 'require_csrf()') !== false);
check('verify_client needs users_manage and a CSRF token', strpos($ver, "require_permission('users_manage')") !== false && strpos($ver, 'require_csrf()') !== false);
check('save_client is guarded before reading data', strpos($u, '$this->guardClient($id);') !== false);
check('changing your own password needs the old one', strpos($u, 'empty($oldpassword)') !== false);

// No extract() anywhere in controllers or services.
$hits = [];
foreach (array_merge(glob("$root/classes/*.php"), glob("$root/src/Services/*.php")) as $f) {
    foreach (token_get_all(file_get_contents($f)) as $t) {
        if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'extract') { $hits[] = basename($f); break; }
    }
}
check('no extract() in controllers or services' . ($hits ? ' (' . implode(', ', $hits) . ')' : ''), !$hits);
exit($fail ? 1 : 0);
