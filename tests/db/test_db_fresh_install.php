<?php
// A brand-new install must be complete, clean and repeatable.
require __DIR__ . '/_harness.php';
use App\Database\{MigrationRunner, SchemaInstaller};
[$c, $db] = nv_fresh_db();
$dir = base_app . 'database/migrations';
$one = fn($sql) => $c->query($sql)->fetch_row()[0];

check('every required table exists', SchemaInstaller::missingTables($c, SchemaInstaller::REQUIRED_TABLES) === []);
check('payroll and HR tables exist (they were missing from the old dump)', SchemaInstaller::missingTables($c, ['employee_list', 'payroll_list', 'leave_list', 'attendance_list']) === []);
check('books start empty: no sample journals', (int)$one('SELECT COUNT(*) FROM journal_entries') === 0 && (int)$one('SELECT COUNT(*) FROM journal_items') === 0);
check('chart of accounts and groups are seeded', (int)$one('SELECT COUNT(*) FROM account_list') > 0 && (int)$one('SELECT COUNT(*) FROM group_list') > 0);
check('default plans are seeded exactly once', (int)$one("SELECT COUNT(*) FROM tenant_plans WHERE code IN ('starter','professional','enterprise')") === 3);
$files = glob($dir . '/*.sql');
check('every migration file is recorded with a checksum', (int)$one('SELECT COUNT(*) FROM schema_migrations WHERE checksum IS NOT NULL AND migration LIKE "0%"') === count($files));
$r = new MigrationRunner($c);
$st = $r->status($dir);
check('nothing pending, nothing edited', $st['pending'] === [] && $st['changed'] === []);
check('running the migrations again applies nothing', $r->run($dir) === []);
check('latest applied matches latest file', $r->latestApplied() === MigrationRunner::latestFile($dir));

// Replay every statement of every migration: only harmless "already exists" repeats may fail.
$bad = [];
foreach ($files as $f) {
    foreach (MigrationRunner::splitStatements((string)file_get_contents($f)) as $stmt) {
        if (($a = MigrationRunner::parseConditionalAddColumn($stmt)) && $c->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$a['table']}' AND COLUMN_NAME='{$a['column']}'")->num_rows) { continue; }
        if (($i = MigrationRunner::parseConditionalCreateIndex($stmt)) && $c->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$i['table']}' AND INDEX_NAME='{$i['index']}'")->num_rows) { continue; }
        $sql = $a ? $a['sql'] : ($i ? $i['sql'] : $stmt);
        $c->query($sql);
        if ($c->errno && !MigrationRunner::isRepeatable($sql, $c->errno)) { $bad[] = basename($f) . " [{$c->errno}] " . substr(preg_replace('/\s+/', ' ', $sql), 0, 80); }
    }
}
check('replaying every migration statement: no real failures' . ($bad ? ' -> ' . implode(' | ', array_slice($bad, 0, 3)) : ''), $bad === []);

// Integrity of the design itself
check('no FLOAT or DOUBLE columns anywhere (money is DECIMAL)', (int)$one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE IN ('float','double')") === 0);
check('journal and invoice amounts are DECIMAL', (int)$one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='journal_items' AND COLUMN_NAME='amount' AND DATA_TYPE='decimal'") === 1);
check('all tables are InnoDB (transactions and foreign keys work)', (int)$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE <> 'InnoDB' AND TABLE_TYPE='BASE TABLE'") === 0);
check('every table has a primary key', (int)$one("SELECT COUNT(*) FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=t.TABLE_SCHEMA AND s.TABLE_NAME=t.TABLE_NAME AND s.INDEX_NAME='PRIMARY')") === 0);
nv_done();
