<?php
/**
 * FRCS VMS v3.0 is deferred to a later version: nothing for it appears in the menus, settings screens or
 * request handlers, even for the developer, until Features::vms() is switched on.
 *
 * Run: php tests/test_vms_not_in_release.php
 */
require_once(__DIR__ . '/../vendor/autoload.php');
if (!defined('base_url')) define('base_url', 'http://localhost/');
if (!defined('base_app')) define('base_app', dirname(__DIR__) . '/');
$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $name\n"; if (!$ok) $fail++; }

class Stub {
    public function userdata($f = '') { return $f === 'type' ? 1 : null; }
    public function is_super_admin() { return true; }
    public function can_access($k) { return true; }
    public function info($k = '') { return ''; }
    public function csrf_token() { return 't'; }
}
function render(string $rel): string {
    $_settings = new Stub(); $conn = null;
    ob_start();
    include dirname(__DIR__) . '/' . $rel;
    return ob_get_clean();
}
check('the feature switch exists and is off', class_exists('App\Support\Features') && \App\Support\Features::vms() === false);
$s = render('admin/inc/menus/10_settings.php');
check('no VMS entry in the Settings menu', stripos($s, 'vms') === false);
check('no Developer menu entry for the VMS inspector', stripos(render('admin/inc/menus/11_developer.php'), 'vms') === false);
$si = file_get_contents(dirname(__DIR__) . '/admin/system_info/index.php');
check('System & Branding has no VMS card or link while off', (bool)preg_match('/Features::vms\(\)/', $si));
$m = file_get_contents(dirname(__DIR__) . '/classes/Master.php');
check('every VMS action refuses while the feature is off', substr_count($m, 'Features::vms()') >= 4);
$idx = file_get_contents(dirname(__DIR__) . '/admin/index.php');
check('the VMS pages are not served by direct URL', strpos($idx, 'Features::pageAvailable') !== false);
check('page helper blocks both VMS pages', !\App\Support\Features::pageAvailable('settings/vms_settings') && !\App\Support\Features::pageAvailable('developer/vms_inspector') && \App\Support\Features::pageAvailable('settings/currencies'));
exit($fail ? 1 : 0);
