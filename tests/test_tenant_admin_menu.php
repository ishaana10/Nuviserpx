<?php
/**
 * A company (tenant) administrator is a staff user whose role carries the administration permissions.
 * They must see their own company's settings, and none of the developer's: no Developer menu, no Tenants,
 * no company switcher, no installation-wide settings. No database needed.
 *
 * Run: php tests/test_tenant_admin_menu.php
 */
require_once(__DIR__ . '/../vendor/autoload.php');
if (!defined('base_url')) define('base_url', 'http://localhost/');
if (!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

use App\Support\Permissions as P;

$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $name\n"; if (!$ok) $fail++; }

class Stub {
    public function __construct(public int $type, public array $perms = []) {}
    public function userdata($f = '') { return $f === 'type' ? $this->type : null; }
    public function is_super_admin() { return $this->type == 1; }
    public function can_access($k) { return $this->type == 1 || in_array($k, $this->perms, true); }
}
function render(string $file, Stub $s): string {
    $_settings = $s; $conn = null;
    ob_start();
    include dirname(__DIR__) . '/admin/inc/menus/' . $file;
    return ob_get_clean();
}

$admin = new Stub(2, ['settings_manage', 'users_manage', 'webhooks_manage', 'workflows_manage']);
$staff = new Stub(2, []);
$dev = new Stub(1);

$m = render('10_settings.php', $admin);
foreach (['My Plan', 'Invoice Templates', 'Financial Settings', 'Import Data', 'Currencies', 'Exchange Rates', 'Custom Extrafields', 'Audit Trail', 'Webhooks', 'Automation Workflows'] as $item) {
    check("company administrator sees: $item", strpos($m, $item) !== false);
}
foreach (['Tenants / Companies', 'System &amp; Branding', 'System & Branding', 'DB Backup', 'Security &', 'Email Templates', 'Active User Sessions'] as $item) {
    check("company administrator does not see: $item", strpos($m, $item) === false);
}
check('company administrator gets no Developer menu', trim(render('11_developer.php', $admin)) === '' || strpos(render('11_developer.php', $admin), 'Developer Dashboard') === false);
check('a plain staff user gets no Settings menu at all', strpos(render('10_settings.php', $staff), 'nav-is-tree') === false);

$d = render('10_settings.php', $dev);
foreach (['Tenants / Companies', 'System &amp; Branding', 'System & Branding', 'DB Backup', 'Security &', 'Email Templates', 'Active User Sessions', 'Currencies'] as $item) {
    if ($item === 'System &amp; Branding') continue; // only one spelling exists in the file
    check("developer sees: $item", strpos($d, $item) !== false);
}

// page-level gates (a hand-typed URL is refused too)
foreach (['tenants', 'developer/dashboard', 'system_info', 'roles', 'settings/security', 'settings/email_templates', 'settings/backup', 'settings/active_sessions'] as $pg) {
    check("page is developer-only: $pg", P::forPage($pg) === P::SUPER_ONLY);
}
foreach (['settings/currencies', 'settings/financial_settings', 'settings/audit_logs', 'settings/extrafields', 'templates/manage_template', 'billing/my_plan'] as $pg) {
    check("page needs settings_manage: $pg", P::forPage($pg) === 'settings_manage');
}
check('webhooks need webhooks_manage', P::forPage('webhooks') === 'webhooks_manage');

// the company switcher stays behind the developer check
$top = file_get_contents(dirname(__DIR__) . '/admin/inc/topBarNav.php');
check('company switcher is for super administrators only', (bool)preg_match('/Tenant Switcher[^\n]*\n\s*<\?php if\(\$_settings->is_super_admin\(\)\)/', $top));

// the top bar names the company the user is working in, not a fixed word
check('top bar shows the company name instead of "Admin"', strpos($top, '- Admin</b>') === false && strpos($top, 'tenant_branding()') !== false);

// a company administrator's views never include other companies' or platform records
$al = file_get_contents(dirname(__DIR__) . '/admin/settings/audit_logs.php');
check('audit trail hides platform-level entries from company administrators', strpos($al, '$nv_platform') !== false);
$as = file_get_contents(dirname(__DIR__) . '/admin/settings/active_sessions.php');
check('session list hides developer accounts from company administrators', strpos($as, 'u.type != 1') !== false);

exit($fail ? 1 : 0);
