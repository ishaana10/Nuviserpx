<?php
/**
 * Apply pending database migrations from the command line, then report the result.
 *
 *   php bin/migrate.php          apply anything pending
 *   php bin/migrate.php --status list applied / pending without changing anything
 *
 * bin/deploy.sh runs this while the site is in maintenance mode.
 */
require __DIR__ . '/_common.php';
use App\Support\HealthCheck;

$files = glob(base_app . 'database/migrations/*.sql') ?: [];
mysqli_report(MYSQLI_REPORT_OFF);
$hp = \App\Support\BackupPlan::hostPort(DB_SERVER);
$c = @new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']);
if ($c->connect_error) { nv_fail('Cannot connect to the database: ' . $c->connect_error); }
$applied = [];
if ($r = @$c->query('SELECT `migration` FROM `schema_migrations`')) { while ($row = $r->fetch_row()) { $applied[] = $row[0]; } }
$pending = HealthCheck::pending($files, $applied);
nv_say(count($applied) . ' applied, ' . count($pending) . ' pending');
foreach ($pending as $p) { nv_say('  pending: ' . $p); }
if (in_array('--status', $argv, true)) { exit($pending ? 2 : 0); }
$c->close();

if ($pending) {
    // The application's own connection class applies migrations when it connects (the runner is shared with the web app).
    require_once base_app . 'classes/DBConnection.php';
    new DBConnection();
    $c = new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']);
    $applied = [];
    if ($r = @$c->query('SELECT `migration` FROM `schema_migrations`')) { while ($row = $r->fetch_row()) { $applied[] = $row[0]; } }
    $still = HealthCheck::pending($files, $applied);
    if ($still) { nv_fail('These migrations did not complete: ' . implode(', ', $still)); }
    nv_say('Migrations applied.');
}
