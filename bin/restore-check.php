<?php
/**
 * Restore drill: loads a backup into a scratch database and checks it matches the live one. Run it monthly.
 *
 *   php bin/restore-check.php [path/to/nuviserpx-db-....sql.gz[.enc]] [--keep]
 *
 * Uses the newest database backup when no file is given. Needs permission to create a database named
 * <DB_NAME>_restorecheck; the live database is only ever read. Writes last_restore_check.json for the health check.
 */
require __DIR__ . '/_common.php';
use App\Support\BackupPlan;

nv_need_tools(['mysql', 'gzip', 'sha256sum']);
$dir = nv_backup_dir();
$args = array_slice($argv, 1);
$keep = in_array('--keep', $args, true);
$file = null;
foreach ($args as $a) { if ($a[0] !== '-') { $file = $a; } }
if ($file === null) {
    $c = array_values(array_filter(scandir($dir) ?: [], fn($n) => preg_match('~^nuviserpx-db-.*\.sql\.gz(\.enc)?$~', $n)));
    rsort($c);
    $file = $c ? "$dir/{$c[0]}" : null;
}
if ($file === null || !is_file($file)) { nv_fail('No database backup found to check.'); }
nv_say('Checking ' . basename($file));

// Verify the checksum written at backup time.
if (is_file($file . '.sha256')) {
    [$rc] = nv_sh('cd ' . escapeshellarg(dirname($file)) . ' && sha256sum -c ' . escapeshellarg(basename($file) . '.sha256') . ' >/dev/null 2>&1');
    if ($rc !== 0) { nv_fail('Checksum does not match: the backup file is damaged.'); }
    nv_say('Checksum OK');
}
$src = escapeshellarg($file);
if (substr($file, -4) === '.enc') {
    $pass = (string)getenv('BACKUP_PASSPHRASE');
    if ($pass === '') { nv_fail('This backup is encrypted. Set BACKUP_PASSPHRASE in .env.'); }
    $reader = 'openssl enc -d -aes-256-cbc -pbkdf2 -in ' . $src . ' -pass env:NV_BACKUP_PASS | gzip -dc';
} else {
    $reader = 'gzip -dc ' . $src;
}
$env = ['NV_BACKUP_PASS' => (string)getenv('BACKUP_PASSPHRASE'), 'PATH' => getenv('PATH')];

$scratch = BackupPlan::scratchDb(DB_NAME);
$cnf = nv_client_file();
$my = 'mysql --defaults-extra-file=' . escapeshellarg($cnf);
$ok = false; $report = [];
try {
    [$rc, $err] = nv_sh($my . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `$scratch`; CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"));
    if ($rc !== 0) { throw new RuntimeException("Cannot create the scratch database $scratch (grant CREATE on it, or run as an admin): $err"); }
    [$rc, $err] = nv_sh($reader . ' | ' . $my . ' ' . escapeshellarg($scratch), $env);
    if ($rc !== 0) { throw new RuntimeException("Restore failed: $err"); }

    $hp = BackupPlan::hostPort(DB_SERVER);
    $live = new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']);
    $rest = new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, $scratch, $hp['port']);
    if ($live->connect_error || $rest->connect_error) { throw new RuntimeException('Cannot connect to compare databases.'); }
    $tables = fn(mysqli $c, string $db) => array_column($c->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $c->real_escape_string($db) . "' AND TABLE_TYPE = 'BASE TABLE'")->fetch_all(), 0);
    $lt = $tables($live, DB_NAME); $rt = $tables($rest, $scratch);
    $missing = array_diff($lt, $rt);
    $report['tables_live'] = count($lt); $report['tables_restored'] = count($rt); $report['missing'] = array_values($missing);
    // Key business tables must not come back empty when the live ones hold data. (Row counts may legitimately differ:
    // the live system moves on after the backup was taken.)
    $worse = [];
    foreach (['users', 'journal_entries', 'journal_items', 'invoice_list', 'payroll_list', 'party_list'] as $t) {
        if (!in_array($t, $rt, true)) { continue; }
        $a = (int)$live->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
        $b = (int)$rest->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
        $report['rows'][$t] = ['live' => $a, 'restored' => $b];
        if ($b === 0 && $a > 0) { $worse[] = $t . ' (empty after restore)'; }
    }
    $report['suspicious'] = $worse;
    $ok = !$missing && !$worse && in_array('journal_entries', $rt, true);
    nv_say(sprintf('Tables: live %d, restored %d', count($lt), count($rt)));
    foreach ($report['rows'] ?? [] as $t => $r) { nv_say(sprintf('  %-16s live %-8d restored %d', $t, $r['live'], $r['restored'])); }
    if ($missing) { nv_say('Missing from restore: ' . implode(', ', $missing)); }
    if ($worse) { nv_say('Needs a look: ' . implode(', ', $worse)); }
} catch (Throwable $e) {
    nv_say('ERROR: ' . $e->getMessage());
} finally {
    if (!$keep) { nv_sh($my . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `$scratch`")); }
    @unlink($cnf);
}
file_put_contents($dir . '/last_restore_check.json', json_encode(['time' => time(), 'file' => basename($file), 'ok' => $ok] + $report));
nv_say($ok ? 'RESTORE CHECK PASSED' : 'RESTORE CHECK FAILED');
exit($ok ? 0 : 1);
