<?php
/**
 * Restore the LIVE database from a backup. Destructive: replaces everything. Used by bin/rollback.sh --with-db.
 *
 *   php bin/restore.php path/to/nuviserpx-db-....sql.gz[.enc] --yes
 *
 * Without --yes it only shows what it would do. It takes a safety dump of the current database first.
 */
require __DIR__ . '/_common.php';
use App\Support\BackupPlan;

nv_need_tools(['mysql', 'mysqldump', 'gzip']);
$args = array_slice($argv, 1);
$yes = in_array('--yes', $args, true);
$file = null;
foreach ($args as $a) { if ($a[0] !== '-') { $file = $a; } }
if ($file === null || !is_file($file)) { nv_fail('Usage: php bin/restore.php <backup file> --yes'); }
nv_say('Backup file : ' . basename($file));
nv_say('Database    : ' . DB_NAME . ' on ' . DB_SERVER . '  (ALL CURRENT DATA WILL BE REPLACED)');
if (!$yes) { nv_say('Dry run. Add --yes to go ahead.'); exit(0); }

$dir = nv_backup_dir();
$cnf = nv_client_file();
$my = 'mysql --defaults-extra-file=' . escapeshellarg($cnf);
$safety = $dir . '/before-restore-' . gmdate('Ymd-His') . '.sql.gz';
[$rc, $err] = nv_sh('mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --routines --triggers --events --hex-blob ' . escapeshellarg(DB_NAME) . ' | gzip -c > ' . escapeshellarg($safety));
if ($rc !== 0) { @unlink($cnf); nv_fail("Could not take the safety dump, so nothing was changed: $err"); }
@chmod($safety, 0600);
nv_say('Safety dump of the current database: ' . $safety);

if (substr($file, -4) === '.enc') {
    $pass = (string)getenv('BACKUP_PASSPHRASE');
    if ($pass === '') { @unlink($cnf); nv_fail('This backup is encrypted. Set BACKUP_PASSPHRASE in .env.'); }
    $reader = 'openssl enc -d -aes-256-cbc -pbkdf2 -in ' . escapeshellarg($file) . ' -pass env:NV_BACKUP_PASS | gzip -dc';
} else {
    $reader = 'gzip -dc ' . escapeshellarg($file);
}
// The dump drops and recreates each table, so loading it over the live database replaces the data.
[$rc, $err] = nv_sh($reader . ' | ' . $my . ' ' . escapeshellarg(DB_NAME), ['NV_BACKUP_PASS' => (string)getenv('BACKUP_PASSPHRASE'), 'PATH' => getenv('PATH')]);
@unlink($cnf);
if ($rc !== 0) { nv_fail("Restore failed part-way: $err\nThe database may be inconsistent. Restore the safety dump $safety or retry with a good backup."); }
nv_say('Restore complete.');
