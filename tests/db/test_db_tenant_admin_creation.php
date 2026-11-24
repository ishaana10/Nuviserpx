<?php
/**
 * Creating a company gives it its own administrator: a Staff User (never the developer tier) with a
 * "Company Administrator" role in that company only.
 */
require __DIR__ . '/_harness.php';
if (!defined('base_url')) define('base_url', 'http://localhost/');

class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($f = '') { return null; }
    public function is_super_admin() { return true; }
    public function log_audit($a, $m, $d = '') {}
    public function set_flashdata($f = '', $v = '') {}
}
[$c, $db] = nv_fresh_db();
(new \App\Database\LegacySchemaCatchup($c))->run();
$svc = new \App\Services\TenantService($c, new SystemSettings());
$j = fn($s) => json_decode($s, true);

$base = ['tenant_code' => 'DEMO-1', 'name' => 'Demo Co', 'email' => 'info@demo.fj', 'status' => 1, 'modules_submitted' => 1, 'modules' => []];
$admin = ['admin_firstname' => 'Ana', 'admin_lastname' => 'Tui', 'admin_email' => 'ana@demo.fj', 'admin_username' => 'ana', 'admin_password' => 'Str0ng#Pass1'];

$bad = $j($svc->saveTenant($base));
check('a new company without an administrator is refused', ($bad['status'] ?? '') === 'failed' && (int)$c->query("SELECT COUNT(*) n FROM tenants WHERE tenant_code='DEMO-1'")->fetch_assoc()['n'] === 0);
$weak = $j($svc->saveTenant($base + array_merge($admin, ['admin_password' => 'abc'])));
check('a weak administrator password is refused and nothing is created', ($weak['status'] ?? '') === 'failed' && (int)$c->query("SELECT COUNT(*) n FROM tenants WHERE tenant_code='DEMO-1'")->fetch_assoc()['n'] === 0);
$c->query("INSERT INTO users (tenant_id,firstname,lastname,username,password,type,status) VALUES (1,'X','Y','ana','x',2,1)");
$dup = $j($svc->saveTenant($base + $admin));
check('a taken username is refused before the company is created', ($dup['status'] ?? '') === 'failed' && (int)$c->query("SELECT COUNT(*) n FROM tenants WHERE tenant_code='DEMO-1'")->fetch_assoc()['n'] === 0);
$c->query("DELETE FROM users WHERE username='ana'");

$ok = $j($svc->saveTenant($base + $admin));
check('company is created', ($ok['status'] ?? '') === 'success' && !empty($ok['tenant_id']));
$t = (int)$ok['tenant_id'];
$u = $c->query("SELECT * FROM users WHERE username='ana'")->fetch_assoc();
check('administrator belongs to the new company', (int)$u['tenant_id'] === $t);
check('administrator is NOT a super administrator', (int)$u['type'] === 2);
check('password is stored hashed', password_verify('Str0ng#Pass1', $u['password']));
$role = $c->query("SELECT * FROM roles WHERE id=" . (int)$u['role_id'])->fetch_assoc();
check('administrator holds a Company Administrator role of that company', $role && (int)$role['tenant_id'] === $t && $role['name'] === 'Company Administrator');
$perms = \App\Support\Permissions::parse($role['permissions']);
check('role carries the company administration permissions', !array_diff(['users_manage', 'settings_manage', 'webhooks_manage', 'workflows_manage', 'api_keys_manage', 'invoices_view', 'payroll_view'], $perms));
check('role carries no wildcard', strpos($role['permissions'], '*') === false);
check('creating a company does not touch other companies', (int)$c->query("SELECT COUNT(*) n FROM users WHERE tenant_id<>$t AND username='ana'")->fetch_assoc()['n'] === 0);

// the role helper is safe to repeat and fills in older companies
$r1 = \App\Services\CompanyAdminService::ensureRole($c, $t);
check('ensuring the role again reuses it', $r1 === (int)$u['role_id'] && (int)$c->query("SELECT COUNT(*) n FROM roles WHERE tenant_id=$t AND name='Company Administrator'")->fetch_assoc()['n'] === 1);
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (50, 'OLD', 'Old Co')");
check('an older company gets the role on demand', \App\Services\CompanyAdminService::ensureRole($c, 50) > 0);

// editing a company never creates another administrator
$edit = $j($svc->saveTenant(['id' => $t, 'tenant_code' => 'DEMO-1', 'name' => 'Demo Co 2', 'status' => 1] + $admin));
check('editing a company leaves its users alone', ($edit['status'] ?? '') === 'success' && (int)$c->query("SELECT COUNT(*) n FROM users WHERE tenant_id=$t")->fetch_assoc()['n'] === 1);
nv_done();
