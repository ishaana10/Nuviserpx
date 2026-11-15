<?php
/**
 * Apply pending database migrations from the command line.
 *
 *   php bin/migrate.php           apply what is pending (stops at the first real failure, which is NOT recorded as done)
 *   php bin/migrate.php --status  list applied / pending / edited-after-applying, change nothing
 *
 * Exit code: 0 all current, 1 a migration failed, 2 (--status) something is pending.
 * bin/deploy.sh runs this while the site is in maintenance mode. With AUTO_MIGRATE=0 in .env this is the only
 * thing that ever changes the schema.
 */
require __DIR__ . '/_common.php';
use App\Database\{LegacySchemaCatchup, MigrationFailed, MigrationRunner};

mysqli_report(MYSQLI_REPORT_OFF);
$hp = \App\Support\BackupPlan::hostPort(DB_SERVER);
$c = @new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']);
if ($c->connect_error) { nv_fail('Cannot connect to the database: ' . $c->connect_error); }
$c->set_charset('utf8mb4');
\App\Database\SqlMode::apply($c);
$dir = base_app . 'database/migrations';
$runner = new MigrationRunner($c);

$st = $runner->status($dir);
nv_say(count($st['applied']) . ' applied, ' . count($st['pending']) . ' pending');
foreach ($st['pending'] as $p) { nv_say('  pending: ' . $p); }
foreach ($st['changed'] as $p) { nv_say('  WARNING: ' . $p . ' was edited after it was applied'); }
if (in_array('--status', $argv, true)) { exit($st['pending'] ? 2 : 0); }
if (!$st['pending']) { nv_say('Nothing to do.'); exit(0); }

try {
    (new LegacySchemaCatchup($c))->run();   // older installs relied on these checks creating tables the migrations then alter
    $done = $runner->run($dir, 60);
} catch (MigrationFailed $e) {
    nv_say('FAILED: ' . $e->getMessage());
    nv_say('Nothing after it was run, and it was not recorded as applied. Fix the cause (or restore the pre-deploy backup) and run this again.');
    exit(1);
}
foreach ($done as $n) { nv_say('  applied: ' . $n); }
$left = $runner->status($dir)['pending'];
if ($left) { nv_fail('Still pending: ' . implode(', ', $left) . ' (another migration run may hold the lock; try again)'); }
nv_say('Database is up to date.');
