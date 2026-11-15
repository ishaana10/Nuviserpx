<?php
/**
 * Harness for tests that need a real MySQL/MariaDB.
 *
 * Environment:  NV_TEST_DB_SERVER (default localhost)  NV_TEST_DB_USER  NV_TEST_DB_PASS  NV_TEST_DB_REQUIRED=1
 * Without a reachable server the test prints SKIP and exits 0, unless NV_TEST_DB_REQUIRED=1 (CI), where it fails.
 * Every test gets its own empty database, built by the real installer, and drops it when it finishes.
 */
$root = dirname(__DIR__, 2);
define('base_app', $root . '/');
define('NV_TESTING', 1);
require_once $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_OFF);

$GLOBALS['nv_fail'] = 0;
function check($name, $ok) { echo ($ok ? 'PASS' : 'FAIL') . " - $name\n"; if (!$ok) { $GLOBALS['nv_fail']++; } }

function nv_db_server(): array
{
    return [getenv('NV_TEST_DB_SERVER') ?: 'localhost', getenv('NV_TEST_DB_USER') ?: 'nuvis', getenv('NV_TEST_DB_PASS') !== false ? getenv('NV_TEST_DB_PASS') : 'nuvispw'];
}

/** Admin connection, or exit (skip/fail) when no server. */
function nv_admin(): mysqli
{
    [$h, $u, $p] = nv_db_server();
    $c = @new mysqli($h, $u, $p);
    if ($c->connect_error) {
        if (getenv('NV_TEST_DB_REQUIRED') === '1') {
            echo "FAIL - database required but not reachable: {$c->connect_error}\n";
            exit(1);
        }
        echo "SKIP - no test database reachable (set NV_TEST_DB_SERVER/USER/PASS)\n";
        exit(0);
    }
    return $c;
}

/** @return array{0:mysqli,1:string} fresh database with the full schema, and its name */
function nv_fresh_db(bool $install = true): array
{
    $admin = nv_admin();
    $name = 'nvt_' . bin2hex(random_bytes(4));
    $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $c = new mysqli(...array_merge(nv_db_server(), [$name]));
    $c->set_charset('utf8mb4');
    \App\Database\SqlMode::apply($c);
    if ($install) {
        \App\Database\SchemaInstaller::install($c, base_app . 'database/nuviserpx.sql', base_app . 'database/migrations');
    }
    register_shutdown_function(function () use ($name) { $a = nv_admin(); $a->query("DROP DATABASE IF EXISTS `$name`"); });
    return [$c, $name];
}

function nv_done(): void { exit($GLOBALS['nv_fail'] ? 1 : 0); }
