<?php
/**
 * Nightly backup: database dump + uploaded files, checksummed, optionally encrypted, pruned, optionally copied off the server.
 *
 *   php bin/backup.php
 *
 * Cron (02:30 daily):  30 2 * * *  php /var/www/nuviserpx/bin/backup.php >> /var/log/nuviserpx-backup.log 2>&1
 *
 * .env settings (all optional):
 *   BACKUP_DIR            where backups go (default: ../nuviserpx-backups, outside the web root)
 *   BACKUP_KEEP_DAYS      delete backups older than this, always keeping the newest 3 of each kind (default 14)
 *   BACKUP_PASSPHRASE     if set, files are encrypted with AES-256 before being kept or copied off the server
 *   BACKUP_UPLOAD_CMD     run after each file, {file} is replaced by its path, e.g.  rclone copy {file} remote:nuvis-backups/
 * Exit code 0 only if every step worked, so cron or a monitor can alert on failure.
 */
require __DIR__ . '/_common.php';
use App\Support\BackupPlan;

nv_need_tools(['mysqldump', 'gzip', 'tar', 'sha256sum']);
$dir = nv_backup_dir();
$now = time();
$keepDays = max(1, (int)(getenv('BACKUP_KEEP_DAYS') ?: 14));
$pass = (string)getenv('BACKUP_PASSPHRASE');
if ($pass !== '') { nv_need_tools(['openssl']); }
$made = [];

// 1. Database. --single-transaction gives a consistent snapshot without locking the live system.
$dbFile = $dir . '/' . BackupPlan::fileName('db', $now);
$cnf = nv_client_file();
$hp = BackupPlan::hostPort(DB_SERVER);
$cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 '
     . escapeshellarg(DB_NAME) . ' | gzip -c > ' . escapeshellarg($dbFile);
[$rc, $err] = nv_sh($cmd);
@unlink($cnf);
if ($rc !== 0) { @unlink($dbFile); nv_fail("Database dump failed: $err"); }
[$rc] = nv_sh('gzip -t ' . escapeshellarg($dbFile) . ' && gzip -dc ' . escapeshellarg($dbFile) . ' | grep -m1 -q "CREATE TABLE"');
if ($rc !== 0) { @unlink($dbFile); nv_fail('The database dump is empty or damaged, so it was discarded.'); }
$made[] = $dbFile;

// 2. Uploaded files (receipts, logos, leave documents).
$filesFile = $dir . '/' . BackupPlan::fileName('files', $now);
[$rc, $err] = nv_sh('tar -czf ' . escapeshellarg($filesFile) . ' --exclude=uploads/temp -C ' . escapeshellarg(rtrim(base_app, '/')) . ' uploads');
if ($rc !== 0) { nv_fail("Uploads archive failed: $err"); }
$made[] = $filesFile;

// 3. Optional encryption, then checksum, then permissions.
$final = [];
foreach ($made as $f) {
    if ($pass !== '') {
        [$rc, $err] = nv_sh('openssl enc -aes-256-cbc -pbkdf2 -salt -in ' . escapeshellarg($f) . ' -out ' . escapeshellarg($f . '.enc') . ' -pass env:NV_BACKUP_PASS', ['NV_BACKUP_PASS' => $pass, 'PATH' => getenv('PATH')]);
        if ($rc !== 0) { nv_fail("Encryption failed: $err"); }
        @unlink($f);
        $f .= '.enc';
    }
    [$rc, $err] = nv_sh('cd ' . escapeshellarg($dir) . ' && sha256sum ' . escapeshellarg(basename($f)) . ' > ' . escapeshellarg(basename($f) . '.sha256'));
    if ($rc !== 0) { nv_fail("Checksum failed: $err"); }
    @chmod($f, 0600);
    @chmod($f . '.sha256', 0600);
    $final[] = $f;
    nv_say('Created ' . basename($f) . ' (' . number_format((int)filesize($f) / 1048576, 2) . ' MB)');
}

// 4. Copy off the server. A failed copy fails the run: a backup that only lives on the same machine is not a backup.
$up = (string)getenv('BACKUP_UPLOAD_CMD');
if ($up !== '') {
    foreach ($final as $f) {
        foreach ([$f, $f . '.sha256'] as $one) {
            [$rc, $err] = nv_sh(str_replace('{file}', escapeshellarg($one), $up));
            if ($rc !== 0) { nv_fail('Copy off the server failed for ' . basename($one) . ": $err"); }
        }
    }
    nv_say('Copied off the server.');
} else {
    nv_say('WARNING: BACKUP_UPLOAD_CMD is not set, so backups exist only on this server.');
}

// 5. Retention.
$all = [];
foreach (scandir($dir) ?: [] as $n) { if (BackupPlan::isBackupName($n)) { $all[$n] = (int)filemtime("$dir/$n"); } }
foreach (BackupPlan::expired($all, $now, $keepDays) as $n) { @unlink("$dir/$n"); nv_say("Removed old backup $n"); }

// 6. Status file for the health check.
file_put_contents($dir . '/last_backup.json', json_encode(['time' => $now, 'db' => basename($final[0]), 'encrypted' => $pass !== '', 'offsite' => $up !== '']));
@chmod($dir . '/last_backup.json', 0600);
nv_say('Backup finished.');
