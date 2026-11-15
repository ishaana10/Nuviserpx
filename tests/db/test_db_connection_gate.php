<?php
// With AUTO_MIGRATE=0 a web request never changes the schema, and refuses to run on a database that is behind the code.
require __DIR__ . '/_harness.php';
use App\Database\MigrationRunner;
[$c, $db] = nv_fresh_db();
[$h, $u, $p] = nv_db_server();
$run = function (string $dbName, string $autoMigrate) use ($h, $u, $p) {
    $code = 'define("base_app", ' . var_export(base_app, true) . '); define("DB_SERVER",' . var_export($h, true) . '); define("DB_USERNAME",' . var_export($u, true) . '); define("DB_PASSWORD",' . var_export($p, true) . '); define("DB_NAME",' . var_export($dbName, true) . '); define("base_url","http://t/");'
        . 'require base_app."vendor/autoload.php"; require base_app."classes/DBConnection.php"; $d = new DBConnection(); echo "SERVED";';
    $env = 'AUTO_MIGRATE=' . escapeshellarg($autoMigrate);
    return (string)shell_exec("$env php -d error_log=/dev/null -r " . escapeshellarg($code) . ' 2>&1');
};
check('current database: the request is served', strpos($run($db, '0'), 'SERVED') !== false);

$latest = MigrationRunner::latestFile(base_app . 'database/migrations');
$c->query("DELETE FROM schema_migrations WHERE migration = '$latest'");
$out = $run($db, '0');
check('database behind the code: 503 page, request not served', strpos($out, 'SERVED') === false && strpos($out, 'Update in progress') !== false);
check('the page does not reveal the database, host or migration names', strpos($out, $db) === false && strpos($out, $latest) === false && strpos($out, 'migrat') === false);
check('and it did not apply the migration itself', (int)$c->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$latest'")->fetch_row()[0] === 0);

$legacy = $run($db, '1');
check('legacy mode (AUTO_MIGRATE=1) still brings the schema up to date', strpos($legacy, 'SERVED') !== false && (int)$c->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$latest'")->fetch_row()[0] === 1);

[$c2, $db2] = nv_fresh_db(false);   // empty database
$out2 = $run($db2, '0');
check('an empty database is refused too (nothing is created by a request)', strpos($out2, 'SERVED') === false && $c2->query("SHOW TABLES")->num_rows === 0);

// Cost of the per-request check
$t = microtime(true);
for ($i = 0; $i < 50; $i++) { (new MigrationRunner($c))->latestApplied(); MigrationRunner::latestFile(base_app . 'database/migrations'); }
$ms = (microtime(true) - $t) / 50 * 1000;
check(sprintf('the per-request version check is cheap (%.2f ms)', $ms), $ms < 5);
nv_done();
