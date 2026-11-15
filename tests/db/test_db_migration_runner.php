<?php
// The runner must stop at the first real failure and must never record a migration that did not fully work.
require __DIR__ . '/_harness.php';
use App\Database\{MigrationFailed, MigrationRunner};
[$c, $db] = nv_fresh_db(false);   // empty database; the runner builds its own tracking table
$tmp = sys_get_temp_dir() . '/nvm_' . bin2hex(random_bytes(4)); mkdir($tmp);
$put = fn($n, $sql) => file_put_contents("$tmp/$n", $sql);
$recorded = fn() => array_column($c->query("SELECT migration FROM schema_migrations WHERE migration LIKE '%.sql' ORDER BY migration")->fetch_all(MYSQLI_ASSOC), 'migration');

$put('001_a.sql', "CREATE TABLE t_a (id INT PRIMARY KEY, n INT);\nINSERT INTO t_a VALUES (1, 10);");
$put('002_b.sql', "CREATE TABLE t_b (id INT PRIMARY KEY);\nALTER TABLE does_not_exist ADD COLUMN x INT;\nCREATE TABLE t_b_after (id INT);");
$put('003_c.sql', "CREATE TABLE t_c (id INT PRIMARY KEY);");

$r = new MigrationRunner($c);
$err = null;
try { $r->run($tmp); } catch (MigrationFailed $e) { $err = $e; }
check('a real failure throws MigrationFailed', $err !== null);
check('the error names the file, the MySQL error number and the SQL', $err && $err->migration === '002_b.sql' && $err->sqlErrno === 1146 && strpos($err->statement, 'does_not_exist') !== false && strpos($err->getMessage(), '002_b.sql') !== false);
check('the good migration before it is recorded', $recorded() === ['001_a.sql']);
check('the failed migration is NOT recorded as applied', !in_array('002_b.sql', $recorded(), true));
check('statements after the failure were not run', $c->query("SHOW TABLES LIKE 't_b_after'")->num_rows === 0);
check('later migrations were not run', $c->query("SHOW TABLES LIKE 't_c'")->num_rows === 0 && !in_array('003_c.sql', $recorded(), true));
check('status shows what is pending', $r->status($tmp)['pending'] === ['002_b.sql', '003_c.sql']);

// Fix the file and run again: it picks up where it stopped. (t_b already exists from the first, partial attempt.)
$put('002_b.sql', "CREATE TABLE t_b (id INT PRIMARY KEY);\nCREATE TABLE t_b_after (id INT);");
$done = $r->run($tmp);
check('after the fix the run resumes: repeat of a created table is tolerated', $done === ['002_b.sql', '003_c.sql'] && $recorded() === ['001_a.sql', '002_b.sql', '003_c.sql']);
check('nothing pending afterwards', $r->status($tmp)['pending'] === [] && $r->run($tmp) === []);

// Checksums: editing an applied file is noticed but does not re-run it.
$put('001_a.sql', "CREATE TABLE t_a (id INT PRIMARY KEY, n INT);\nINSERT INTO t_a VALUES (2, 20);");
$r2 = new MigrationRunner($c);
check('status reports an edited, already-applied migration', $r2->status($tmp)['changed'] === ['001_a.sql']);
$r2->run($tmp);
check('the edited migration is not run again, a warning is kept', (int)$c->query('SELECT COUNT(*) FROM t_a')->fetch_row()[0] === 1 && $r2->warnings === ['001_a.sql was changed after it was applied']);

// Only "already there" errors are tolerated
foreach ([[1050, 'CREATE TABLE x (a INT)'], [1060, 'ALTER TABLE x ADD COLUMN a INT'], [1061, 'ALTER TABLE x ADD INDEX i (a)'], [1062, 'INSERT INTO x VALUES (1)']] as [$no, $sql]) {
    check("error $no is tolerated on repeatable SQL", MigrationRunner::isRepeatable($sql, $no));
}
check('missing table (1146), syntax (1064), unknown column (1054), FK (1452) are never tolerated', !MigrationRunner::isRepeatable('ALTER TABLE x ADD COLUMN a INT', 1146) && !MigrationRunner::isRepeatable('SELEKT', 1064) && !MigrationRunner::isRepeatable('UPDATE x SET a = 1', 1054) && !MigrationRunner::isRepeatable('INSERT INTO x VALUES (1)', 1452));
check('a duplicate entry is tolerated only for INSERT, not UPDATE', !MigrationRunner::isRepeatable('UPDATE x SET a = 1', 1062));

// MySQL-compatible emulation of MariaDB-only syntax
$put2 = fn($n, $sql) => file_put_contents("$tmp/$n", $sql);
$put2('004_d.sql', "CREATE TABLE t_d (id INT PRIMARY KEY, v INT);\nALTER TABLE t_d ADD COLUMN IF NOT EXISTS w INT DEFAULT NULL;\nALTER TABLE t_d ADD COLUMN IF NOT EXISTS w INT DEFAULT NULL;\nCREATE INDEX IF NOT EXISTS idx_d_v ON t_d (v);\nCREATE INDEX IF NOT EXISTS idx_d_v ON t_d (v);\nCREATE UNIQUE INDEX IF NOT EXISTS uq_d_w ON t_d (w);");
$r->run($tmp);
check('ADD COLUMN IF NOT EXISTS works twice on any server', $c->query("SHOW COLUMNS FROM t_d LIKE 'w'")->num_rows === 1);
check('CREATE INDEX IF NOT EXISTS works twice on any server', $c->query("SHOW INDEX FROM t_d WHERE Key_name='idx_d_v'")->num_rows === 1 && $c->query("SHOW INDEX FROM t_d WHERE Key_name='uq_d_w' AND Non_unique=0")->num_rows === 1);
$p = MigrationRunner::parseConditionalCreateIndex('CREATE UNIQUE INDEX IF NOT EXISTS a ON b (c, d)');
check('index statement is rewritten without IF NOT EXISTS', $p === ['table' => 'b', 'index' => 'a', 'sql' => 'CREATE UNIQUE INDEX `a` ON `b` (c, d)'] && MigrationRunner::parseConditionalCreateIndex('CREATE TABLE x (a int)') === null);

// One runner at a time
$other = new mysqli(...array_merge(nv_db_server(), [$db]));
$other->query("SELECT GET_LOCK('nuviserpx_migrations', 0)");
$put2('005_e.sql', 'CREATE TABLE t_e (id INT);');
check('while another process holds the lock, the runner does nothing', $r->run($tmp) === [] && $c->query("SHOW TABLES LIKE 't_e'")->num_rows === 0);
$other->query("SELECT RELEASE_LOCK('nuviserpx_migrations')");
check('once the lock is free it runs', $r->run($tmp) === ['005_e.sql']);

// Windows line endings do not change the checksum
file_put_contents("$tmp/a.sql", "x\ny\n"); file_put_contents("$tmp/b.sql", "x\r\ny\r\n");
check('CRLF and LF files have the same checksum', MigrationRunner::checksum("$tmp/a.sql") === MigrationRunner::checksum("$tmp/b.sql"));
array_map('unlink', glob("$tmp/*")); rmdir($tmp);
nv_done();
