<?php
/**
 * Health probe for uptime monitors and the deploy script.
 *
 *   GET /health.php                         -> {"status":"ok"}  (200)  or {"status":"degraded"} (503). Nothing else is revealed.
 *   GET /health.php  + header X-Health-Token: <HEALTH_TOKEN from .env>  -> the full checklist.
 *   php health.php                          -> full checklist on the command line; exit code 1 when degraded.
 *
 * It talks to the database directly (never through DBConnection) so a probe can not trigger installs or migrations.
 */
define('NV_HEALTH', 1);
$cli = php_sapi_name() === 'cli';
if (!defined('base_app')) { define('base_app', str_replace('\\', '/', __DIR__) . '/'); }
if (!$cli) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }

if (!is_file(__DIR__ . '/.env') || !is_file(__DIR__ . '/install/installed.lock')) {
    if (!$cli) { http_response_code(503); }
    echo json_encode(['status' => 'not_installed']), "\n";
    exit(1);
}
require_once __DIR__ . '/initialize.php';
spl_autoload_register(function ($c) {
    if (strpos($c, 'App\\') === 0) { $f = __DIR__ . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) { require_once $f; } }
});

$token = (string)getenv('HEALTH_TOKEN');
$given = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');
$detail = $cli || ($token !== '' && $given !== '' && hash_equals($token, $given));

mysqli_report(MYSQLI_REPORT_OFF);
$hp = \App\Support\BackupPlan::hostPort(DB_SERVER);
$c = mysqli_init();
$c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$dbOk = @$c->real_connect($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']) && @$c->query('SELECT 1') !== false;

$applied = [];
if ($dbOk && ($r = @$c->query('SELECT `migration` FROM `schema_migrations`'))) { while ($row = $r->fetch_row()) { $applied[] = $row[0]; } }
$pending = $dbOk ? \App\Support\HealthCheck::pending(glob(__DIR__ . '/database/migrations/*.sql') ?: [], $applied) : [];

$free = @disk_free_space(__DIR__); $total = @disk_total_space(__DIR__);
$backupDir = getenv('BACKUP_DIR') ?: (dirname(__DIR__) . '/nuviserpx-backups');
$last = is_file($backupDir . '/last_backup.json') ? json_decode((string)file_get_contents($backupDir . '/last_backup.json'), true) : null;
$drill = is_file($backupDir . '/last_restore_check.json') ? json_decode((string)file_get_contents($backupDir . '/last_restore_check.json'), true) : null;
$now = time();

$facts = [
    'db_ok' => $dbOk,
    'pending_migrations' => count($pending),
    'disk_free_pct' => ($free && $total) ? round($free / $total * 100, 1) : null,
    'backup_required' => getenv('HEALTH_REQUIRE_BACKUP') === '1',
    'backup_age_hours' => \App\Support\BackupPlan::ageHours(is_array($last) ? (int)($last['time'] ?? 0) : null, $now),
    'app_key_set' => \App\Support\Crypto::hasAppKey(),
    'is_production' => strtolower((string)(getenv('APP_ENV') ?: 'production')) === 'production',
    'uploads_writable' => is_writable(__DIR__ . '/uploads'),
    'logs_writable' => is_dir(__DIR__ . '/logs') ? is_writable(__DIR__ . '/logs') : is_writable(__DIR__),
];
$verdict = \App\Support\HealthCheck::evaluate($facts);
if (!$cli) { http_response_code($verdict['status'] === 'ok' ? 200 : 503); }

if (!$detail) {
    echo json_encode(['status' => $verdict['status']]), "\n";
    exit;
}
$ver = is_file(__DIR__ . '/version.json') ? json_decode((string)file_get_contents(__DIR__ . '/version.json'), true) : [];
echo json_encode([
    'status' => $verdict['status'],
    'problems' => $verdict['problems'],
    'version' => $ver['version'] ?? null,
    'php' => PHP_VERSION,
    'checks' => $facts,
    'pending_migration_files' => $pending,
    'last_backup_utc' => is_array($last) ? gmdate('c', (int)($last['time'] ?? 0)) : null,
    'backup_offsite' => is_array($last) ? (bool)($last['offsite'] ?? false) : null,
    'last_restore_drill' => is_array($drill) ? ['utc' => gmdate('c', (int)($drill['time'] ?? 0)), 'ok' => (bool)($drill['ok'] ?? false)] : null,
    'maintenance' => \App\Support\Maintenance::isOn(),
    'web_git_enabled' => \App\Support\WebGit::enabled(),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
exit($cli && $verdict['status'] !== 'ok' ? 1 : 0);
