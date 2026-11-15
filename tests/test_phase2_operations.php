<?php
// Phase 2 operations: backup rules, health verdict, maintenance mode, scripts and docs.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
define('base_app', $root . '/');
spl_autoload_register(function ($c) use ($root) {
    if (strpos($c, 'App\\') === 0) { $f = $root . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; }
});
use App\Support\BackupPlan as B; use App\Support\HealthCheck as H; use App\Support\Maintenance as M;
$read = fn($p) => file_get_contents("$root/$p");

// Backup rules
check('host and port parsing', B::hostPort('localhost') === ['host' => 'localhost', 'port' => 3306] && B::hostPort('db.x.com:3307') === ['host' => 'db.x.com', 'port' => 3307] && B::hostPort('') === ['host' => 'localhost', 'port' => 3306]);
$cnf = B::clientOptionsFile('u"ser', "p\"a\\ss\nword", '10.0.0.5:3310');
check('client options file escapes quotes/backslashes/newlines and carries host and port', strpos($cnf, 'user="u\\"ser"') !== false && strpos($cnf, 'password="p\\"a\\\\ssword"') !== false && substr_count($cnf, "\n") === 5 && strpos($cnf, 'port=3310') !== false);
check('file names are sortable and recognised', B::fileName('db', 0) === 'nuviserpx-db-19700101-000000.sql.gz' && B::isBackupName(B::fileName('files', 86400 * 365)) && B::isBackupName('nuviserpx-db-20260101-000000.sql.gz.enc.sha256') && !B::isBackupName('notes.txt') && !B::isBackupName('../nuviserpx-db-20260101-000000.sql.gz') && !B::isBackupName('last_backup.json'));
$now = strtotime('2026-11-15 00:00:00 UTC');
$files = [];
foreach (range(0, 9) as $i) { $t = $now - $i * 5 * 86400; $n = B::fileName('db', $t); $files[$n] = $t; $files[$n . '.sha256'] = $t; }
$files['unrelated.txt'] = 1; $files['last_backup.json'] = 1;
$del = B::expired($files, $now, 14, 3);
check('retention drops old backups with their checksums', in_array(B::fileName('db', $now - 9 * 5 * 86400), $del, true) && in_array(B::fileName('db', $now - 9 * 5 * 86400) . '.sha256', $del, true) && !in_array(B::fileName('db', $now), $del, true));
check('retention keeps files younger than the limit', !in_array(B::fileName('db', $now - 2 * 5 * 86400), $del, true));
check('retention never touches unrelated files', !in_array('unrelated.txt', $del, true) && !in_array('last_backup.json', $del, true));
$ancient = []; foreach (range(1, 5) as $i) { $t = $now - (100 + $i) * 86400; $ancient[B::fileName('db', $t)] = $t; }
check('even when everything is old, the newest 3 are kept', count(B::expired($ancient, $now, 14, 3)) === 2 * 1 && count(array_filter(B::expired($ancient, $now, 14, 3), fn($n) => substr($n, -7) !== '.sha256')) === 2);
check('backup age in hours', B::ageHours($now - 5400, $now) === 1.5 && B::ageHours(null, $now) === null && B::ageHours($now + 99, $now) === 0.0);
check('scratch database can never be the live one', B::scratchDb('nuviserpx') === 'nuviserpx_restorecheck' && B::scratchDb('nuviserpx_restorecheck') !== 'nuviserpx_restorecheck' && B::scratchDb('a`b;c') === 'abc_restorecheck');

// Health verdict
$good = ['db_ok' => true, 'pending_migrations' => 0, 'disk_free_pct' => 40, 'backup_required' => true, 'backup_age_hours' => 3.0, 'app_key_set' => true, 'is_production' => true, 'uploads_writable' => true, 'logs_writable' => true];
check('healthy system is ok', H::evaluate($good) === ['status' => 'ok', 'problems' => []]);
check('database down is degraded', H::evaluate(['db_ok' => false] + $good)['status'] === 'degraded');
check('pending migrations are reported', H::evaluate(['pending_migrations' => 2] + $good)['problems'] === ['2 migration(s) not applied']);
check('disk under 10% is degraded', H::evaluate(['disk_free_pct' => 4.5] + $good)['status'] === 'degraded' && H::evaluate(['disk_free_pct' => 10.0] + $good)['status'] === 'ok');
check('stale or missing backup is degraded only when backups are required', H::evaluate(['backup_age_hours' => 40.0] + $good)['status'] === 'degraded' && H::evaluate(['backup_age_hours' => null] + $good)['status'] === 'degraded' && H::evaluate(['backup_age_hours' => null, 'backup_required' => false] + $good)['status'] === 'ok');
check('missing APP_KEY matters in production only', H::evaluate(['app_key_set' => false] + $good)['status'] === 'degraded' && H::evaluate(['app_key_set' => false, 'is_production' => false] + $good)['status'] === 'ok');
check('unwritable uploads or logs are degraded', H::evaluate(['uploads_writable' => false] + $good)['status'] === 'degraded' && H::evaluate(['logs_writable' => false] + $good)['status'] === 'degraded');
check('pending migration list', H::pending(['/x/002_b.sql', '/x/001_a.sql', '/x/003_c.sql'], ['001_a.sql', '002_b.sql']) === ['003_c.sql'] && H::pending([], ['x']) === []);

// Maintenance mode
check('health probe is reachable during maintenance, other pages are not', M::allows('/nuviserpx/health.php', '1.2.3.4') && !M::allows('/admin/index.php', '1.2.3.4') && !M::allows('/nuviserpx/xhealth.php', '1.2.3.4'));
check('listed addresses may bypass; blank and unlisted may not', M::allows('/admin/', '10.0.0.9', '10.0.0.8, 10.0.0.9') && !M::allows('/admin/', '10.0.0.7', '10.0.0.8') && !M::allows('/admin/', '', '') && !M::allows('/admin/', '', ','));
M::off(); check('flag off by default', !M::isOn());
check('on() creates and off() removes the flag', M::on() && M::isOn() && (M::off() || true) && !M::isOn());
$cfg = $read('config.php'); $api = $read('api/v1/auth.php');
check('web and API entry points both call the maintenance guard', strpos($cfg, 'Maintenance::guard()') !== false && strpos($api, 'Maintenance::guard(true)') !== false);
check('guard does nothing on the command line', (function () { M::on(); ob_start(); M::guard(); $o = ob_get_clean(); M::off(); return $o === ''; })());

// Scripts and files
foreach (['bin/backup.php', 'bin/restore.php', 'bin/restore-check.php', 'bin/migrate.php'] as $f) { check("$f only runs on the command line", strpos($read('bin/_common.php'), "php_sapi_name() !== 'cli'") !== false && strpos($read($f), "require __DIR__ . '/_common.php'") !== false); }
check('web access to bin/ is denied', strpos($read('bin/.htaccess'), 'Require all denied') !== false);
$bk = $read('bin/backup.php');
check('dump is consistent, checks itself, and password never on the command line', strpos($bk, '--single-transaction') !== false && strpos($bk, 'gzip -t') !== false && strpos($bk, 'defaults-extra-file') !== false && strpos($bk, '-p"') === false && strpos($bk, '--password') === false);
check('passphrase goes through the environment, not the command line', strpos($bk, '-pass env:NV_BACKUP_PASS') !== false && strpos($bk, '-pass pass:') === false);
check('a failed off-server copy fails the run', strpos($bk, 'Copy off the server failed') !== false);
check('backup folder may not sit inside the web root', strpos($read('bin/_common.php'), 'is inside the application folder') !== false);
$rc = $read('bin/restore-check.php'); $rs = $read('bin/restore.php');
check('restore drill uses a scratch database and verifies the checksum', strpos($rc, 'BackupPlan::scratchDb') !== false && strpos($rc, 'sha256sum -c') !== false && strpos($rc, 'DROP DATABASE IF EXISTS') !== false);
check('real restore needs --yes and takes a safety dump first', strpos($rs, "'--yes'") !== false && strpos($rs, 'Safety dump') !== false && strpos($rs, 'Dry run') !== false);
$dp = $read('bin/deploy.sh'); $rb = $read('bin/rollback.sh');
check('deploy: backup before maintenance before migrate, fail leaves site closed', strpos($dp, 'bin/backup.php') < strpos($dp, 'Maintenance::on()') && strpos($dp, 'Maintenance::on()') < strpos($dp, 'bin/migrate.php') && strpos($dp, 'trap \'recover\' ERR') !== false && strpos($dp, 'set -euo pipefail') !== false);
check('deploy refuses a dirty working copy', strpos($dp, 'git status --porcelain') !== false);
check('rollback restores the database only on request and after typed confirmation', strpos($rb, '--with-db') !== false && strpos($rb, 'Type RESTORE') !== false);
check('scripts are executable', is_executable("$root/bin/deploy.sh") && is_executable("$root/bin/rollback.sh"));
$hl = $read('health.php');
check('health page shows nothing but status without the token, compares in constant time', strpos($hl, 'hash_equals($token, $given)') !== false && strpos($hl, "\$token !== ''") !== false && strpos($hl, 'new DBConnection') === false && strpos($hl, 'classes/DBConnection.php') === false);
check('env example documents the new settings', array_reduce(['BACKUP_DIR', 'BACKUP_KEEP_DAYS', 'BACKUP_PASSPHRASE', 'BACKUP_UPLOAD_CMD', 'HEALTH_TOKEN', 'HEALTH_REQUIRE_BACKUP', 'MAINTENANCE_ALLOW_IPS'], fn($c, $k) => $c && strpos($read('.env.example'), $k) !== false, true));
check('deployment guide exists and covers the key topics', is_file("$root/docs/DEPLOYMENT.md") && array_reduce(['Backups', 'Restore drill', 'Deploying', 'Rolling back', 'Monitoring', 'Database users', 'Web server', 'Checklist'], fn($c, $k) => $c && stripos($read('docs/DEPLOYMENT.md'), $k) !== false, true));
check('README no longer tells people to edit initialize.php or chmod 775 everything', strpos($read('README.md'), 'Open `initialize.php`') === false && strpos($read('README.md'), 'chmod -R 775') === false && strpos($read('README.md'), 'docs/DEPLOYMENT.md') !== false);

exit($fail ? 1 : 0);
