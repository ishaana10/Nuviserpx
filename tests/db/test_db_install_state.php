<?php
// A missing lock must not send an installed system back to the wizard, and a fresh clone must still get the wizard.
require __DIR__ . '/_harness.php';
use App\Support\InstallState;
[$c, $db] = nv_fresh_db();
[$h, $u, $p] = nv_db_server();
$dir = sys_get_temp_dir() . '/nvi_' . bin2hex(random_bytes(3)); mkdir($dir);
$env = fn($name) => file_put_contents("$dir/.env", "DB_SERVER=\"$h\"\nDB_USERNAME=\"$u\"\nDB_PASSWORD=\"$p\"\nDB_NAME=\"$name\"\n");
$lock = "$dir/installed.lock";

$env($db);
check('fresh schema with no users is not an installed system', InstallState::adoptExisting($lock, "$dir/.env") === false && !file_exists($lock));
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','admin','x')");
check('a database with a user is an installed system: the lock is recreated', InstallState::adoptExisting($lock, "$dir/.env") === true && file_exists($lock));
unlink($lock);
$env('no_such_db_' . bin2hex(random_bytes(2)));
check('a database that does not exist gets the wizard (no lock)', InstallState::adoptExisting($lock, "$dir/.env") === false && !file_exists($lock));
file_put_contents("$dir/.env", "DB_SERVER=\"$h\"\nDB_USERNAME=\"nobody\"\nDB_PASSWORD=\"wrong\"\nDB_NAME=\"$db\"\n");
check('wrong credentials get the wizard, never a lock', InstallState::adoptExisting($lock, "$dir/.env") === false && !file_exists($lock));
check('no .env at all is not installed', InstallState::adoptExisting($lock, "$dir/missing.env") === false);
file_put_contents("$dir/.env", "# only comments\nDB_NAME=\n");
check('a half-written .env is not installed', InstallState::adoptExisting($lock, "$dir/.env") === false);
check('.env values with quotes, comments and = in the password are parsed', InstallState::parseEnv("# c\nDB_PASSWORD=\"a=b#c\"\nDB_NAME='x'\n\nBAD") === ['DB_PASSWORD' => 'a=b#c', 'DB_NAME' => 'x']);
foreach (glob("$dir/{,.}*", GLOB_BRACE) ?: [] as $x) { if (is_file($x)) unlink($x); } @rmdir($dir);
nv_done();
