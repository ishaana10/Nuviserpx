<?php
// Per-tenant branding: each company's own name and logo on its documents;
// the platform's branding is developer-only.
$root = dirname(__DIR__);
require_once $root . '/src/Support/Permissions.php';
use App\Support\Permissions;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$ss = file_get_contents($root . '/classes/SystemSettings.php');
$ts = file_get_contents($root . '/src/Services/TenantService.php');
$iv = file_get_contents($root . '/admin/invoices/view.php');
$pv = file_get_contents($root . '/admin/payments/view.php');
$ps = file_get_contents($root . '/admin/payroll/view_payslip.php');
$em = file_get_contents($root . '/classes/Emailer.php');
$cp = file_get_contents($root . '/admin/settings/company.php');
$mig = (string)@file_get_contents($root . '/database/migrations/040_tenant_logo.sql');

// Schema + accessor
check('migration and catch-up add tenants.logo', strpos($mig, "ADD COLUMN IF NOT EXISTS `logo`") !== false && strpos(file_get_contents($root.'/src/Database/LegacySchemaCatchup.php'), "ADD COLUMN `logo` varchar(255)") !== false);
check('tenant_branding() reads the tenants row with invoice-template logo fallback', strpos($ss, 'function tenant_branding()') !== false && strpos($ss, 'invoice_templates') !== false);
check('platform branding is never a fallback for tenant documents', strpos($ss, "is NOT used as a fallback") !== false && !preg_match("/branding_cache[^}]*info\('logo'\)/s", $ss));

// Documents
check('invoice header carries the company identity', strpos($iv, "tenant_branding()['logo']") !== false && strpos($iv, "htmlspecialchars(\$nv_brand['name'])") !== false && strpos($iv, "\$sys['name'] ?? 'Nuvis ERPX'") === false);
check('receipt header carries the company identity', strpos($pv, 'tenant_branding()') !== false && strpos($pv, "\$sys['name'] ?? 'Nuvis ERPX'") === false);
check('payslip carries the company name', strpos($ps, 'tenant_branding()') !== false);
check('emails fall back to the company name, not the platform name', strpos($em, '$brand_name') !== false && strpos($em, "\$brand_name !== '' ? \$brand_name") !== false);

// Profile editing
check('profile save validates, stores one logo per company and audits without credentials', strpos($ts, 'function saveProfile') !== false && strpos($ts, 'getimagesize') !== false && strpos($ts, "2 * 1024 * 1024") !== false && strpos($ts, "log_audit('Updated Company Profile'") !== false);
check('remove-logo deletes the stored file', strpos($ts, 'remove_logo') !== false && strpos($ts, '@unlink(base_app . $cur)') !== false);
check('action wired and gated by settings_manage', Permissions::forAction('save_company_profile') === 'settings_manage' && strpos(file_get_contents($root.'/classes/Master.php'), "'save_company_profile'") !== false);
check('Company Profile page uploads via multipart with preview', strpos($cp, 'enctype="multipart/form-data"') !== false && strpos($cp, 'save_company_profile') !== false && strpos($cp, 'Current Logo') !== false);
check('menu links the page for settings managers', strpos(file_get_contents($root.'/admin/inc/menus/09_access_users.php'), 'page=settings/company') !== false);
check('help topic covers it', strpos(file_get_contents($root.'/src/Support/HelpContent.php'), "'settings/company'") !== false);

// The platform's branding is the developer's
check('system_info page and its save/test actions are developer-only', Permissions::forPage('system_info') === Permissions::SUPER_ONLY && preg_match("/case 'update_settings':\s*\\\$sysset->require_super_admin\(\);/", $ss) === 1);

check('changelog records the feature', strpos(file_get_contents($root.'/CHANGELOG.md'), 'Per-tenant branding') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
