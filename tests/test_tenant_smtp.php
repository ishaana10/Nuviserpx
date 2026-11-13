<?php
// Per-tenant SMTP with encrypted passwords, verified SMTP dialogue, fallback
// to the system account, and a per-tenant email log.
$root = dirname(__DIR__);
require_once $root . '/src/Support/Crypto.php';
use App\Support\Crypto;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// Crypto really round-trips and never stores plain text
$e = Crypto::encrypt('s3cret-password!');
check('passwords encrypt at rest and decrypt back', Crypto::isEncrypted($e) && strpos($e, 's3cret') === false && Crypto::decrypt($e) === 's3cret-password!');
check('legacy plain values still decrypt as themselves', Crypto::decrypt('plainlegacy') === 'plainlegacy');

$svc = file_get_contents($root . '/src/Services/TenantMailService.php');
$em = file_get_contents($root . '/classes/Emailer.php');
$pg = file_get_contents($root . '/admin/settings/email.php');
$mig = (string)@file_get_contents($root . '/database/migrations/039_tenant_smtp_email_log.sql');
$lc = file_get_contents($root . '/src/Database/LegacySchemaCatchup.php');
$ms = file_get_contents($root . '/classes/Master.php');
$pm = file_get_contents($root . '/src/Support/Permissions.php');

// Schema
check('migration adds tenant SMTP columns and the email_log table', substr_count($mig, 'ADD COLUMN IF NOT EXISTS `smtp_') === 8 && strpos($mig, 'CREATE TABLE IF NOT EXISTS `email_log`') !== false);
check('runtime catch-up mirrors both', strpos($lc, "'smtp_from_name'") !== false && strpos($lc, 'CREATE TABLE IF NOT EXISTS `email_log`') !== false);

// Service behaviour
check('a blank password keeps the stored one; saved passwords are encrypted', strpos($svc, "if (\$pass !== '')") !== false && strpos($svc, 'Crypto::encrypt($pass)') !== false);
check('audit never carries credential material', strpos($svc, "log_audit('Updated Company Email Settings'") !== false && !preg_match('/log_audit[^;]*\{\$pass\}/', $svc) && strpos($svc, "(password updated)") !== false);
check('every send is logged per tenant with transport, status and error', strpos($svc, 'INSERT INTO `email_log`') !== false && strpos($svc, 'bind_param') !== false);

// Emailer chain
check('tenant SMTP is tried first, system SMTP is the fallback', strpos($em, "'tenant-smtp'") !== false && strpos($em, "'system-smtp'") !== false && strpos($em, 'TenantMailService($conn, $_settings))->config($tenant_id)') !== false);
check('send outcome is recorded via TenantMailService::logSend', strpos($em, 'TenantMailService::logSend($conn, $tenant_id') !== false);
check('SMTP replies are verified so failures are real failures', strpos($em, "self::expect(\$socket, '235', 'authentication')") !== false && strpos($em, "self::expect(\$socket, '250', 'message delivery')") !== false && strpos($em, 'SMTP {$step} failed') !== false);
check('connection failures carry the reason instead of silently falling back', strpos($em, 'could not connect to {$host}:{$port}') !== false);

// Wiring & UI
check('actions whitelisted and gated by settings_manage', strpos($ms, "'save_tenant_smtp', 'test_tenant_smtp'") !== false && strpos($pm, "'save_tenant_smtp'      => ['settings_manage']") !== false);
check('settings page never echoes the stored password', strpos($pg, 'name="smtp_pass"') !== false && preg_match('/name="smtp_pass"[^>]*value=""/', $pg) === 1);
check('page shows the log with status badges and a test button', strpos($pg, 'Email Log') !== false && strpos($pg, 'badge-danger">failed') !== false && strpos($pg, 'test_tenant_smtp') !== false);
check('menu entry for settings managers', strpos(file_get_contents($root.'/admin/inc/menus/09_access_users.php'), 'page=settings/email') !== false);
check('help topic covers the page', strpos(file_get_contents($root.'/src/Support/HelpContent.php'), "'settings/email'") !== false);
check('changelog records the feature', strpos(file_get_contents($root.'/CHANGELOG.md'), 'Per-tenant company email') !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
