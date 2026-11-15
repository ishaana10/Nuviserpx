<?php
/** Shared start-up for the command-line tools in bin/. Not a web page: refuses to run under a web server. */
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}
define('base_app_cli', true);
require_once dirname(__DIR__) . '/initialize.php';
spl_autoload_register(function ($c) {
    if (strpos($c, 'App\\') === 0) {
        $f = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        if (is_file($f)) { require_once $f; }
    }
});

function nv_say(string $m): void { fwrite(STDOUT, $m . "\n"); }
function nv_fail(string $m, int $code = 1): void { fwrite(STDERR, "ERROR: $m\n"); exit($code); }

/** Backup folder: BACKUP_DIR, or a sibling of the app folder so it is never inside the web root. */
function nv_backup_dir(): string
{
    $d = getenv('BACKUP_DIR') ?: (dirname(rtrim(base_app, '/')) . '/nuviserpx-backups');
    if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
        nv_fail("Cannot create the backup folder $d");
    }
    @chmod($d, 0700);
    if (realpath($d) !== false && strpos(realpath($d) . '/', realpath(rtrim(base_app, '/')) . '/') === 0) {
        nv_fail("BACKUP_DIR ($d) is inside the application folder. Choose a folder outside the web root.");
    }
    return rtrim($d, '/');
}

/** Write a private client options file for mysql/mysqldump; returns its path. Caller deletes it. */
function nv_client_file(): string
{
    $p = tempnam(sys_get_temp_dir(), 'nvmy');
    @chmod($p, 0600);
    file_put_contents($p, \App\Support\BackupPlan::clientOptionsFile(DB_USERNAME, DB_PASSWORD, DB_SERVER));
    return $p;
}

/** Run a shell command through bash with pipefail. Returns [exitCode, stderr]. */
function nv_sh(string $cmd, array $env = []): array
{
    $proc = proc_open(['bash', '-o', 'pipefail', '-c', $cmd], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env ?: null);
    if (!is_resource($proc)) { return [127, 'could not start bash']; }
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($proc), trim((string)$err)];
}

function nv_need_tools(array $tools): void
{
    foreach ($tools as $t) {
        [$rc] = nv_sh('command -v ' . escapeshellarg($t));
        if ($rc !== 0) { nv_fail("The command '$t' is not installed on this server."); }
    }
}
