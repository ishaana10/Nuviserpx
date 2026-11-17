<?php
// Portals show the company's own logo when it has one, otherwise the NuvisERPX logo (never a broken placeholder).
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Support\PortalBranding as B;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$has = fn(array $files) => fn(string $p) => in_array($p, $files, true);

check('default logo file ships with the app', is_file(base_app . B::DEFAULT_LOGO) && strncmp((string)file_get_contents(base_app . B::DEFAULT_LOGO), "\x89PNG", 4) === 0);
check('no logo set: NuvisERPX logo', B::pick([null, null], $has([])) === B::DEFAULT_LOGO);
check('empty strings: NuvisERPX logo', B::pick(['', '  '], $has([])) === B::DEFAULT_LOGO);
check("company logo is used when the file exists", B::pick(['uploads/tenants/1.png', null], $has(['uploads/tenants/1.png'])) === 'uploads/tenants/1.png');
check('a version suffix (?v=) is kept on the URL but does not break the file check', B::pick(['uploads/t.png?v=123'], $has(['uploads/t.png'])) === 'uploads/t.png?v=123');
check('logo pointing at a missing file: NuvisERPX logo, not a broken image', B::pick(['uploads/gone.png'], $has([])) === B::DEFAULT_LOGO);
check("falls to the invoice-template logo when the company logo is missing", B::pick(['uploads/gone.png', 'uploads/tpl.png'], $has(['uploads/tpl.png'])) === 'uploads/tpl.png');
check('path traversal is not accepted', B::pick(['../config.php'], fn() => true) === B::DEFAULT_LOGO);
foreach (['login', 'register', 'employee_login', 'index', 'view_invoice', '_employee_layout'] as $f) {
    $src = file_get_contents(base_app . "portal/$f.php");
    check("portal/$f.php uses PortalBranding (not the generic placeholder)", strpos($src, 'PortalBranding::logoUrl') !== false || strpos($src, '$portal_logo') !== false);
}
foreach (['login', 'register'] as $f) {
    $src = file_get_contents(base_app . "portal/$f.php");
    check("portal/$f.php keeps message and alert text readable on the white card", strpos($src, '.login-page .login-box-msg') !== false && strpos($src, '.alert-danger { color:') !== false);
}
exit($fail ? 1 : 0);
