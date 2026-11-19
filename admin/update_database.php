<?php
// Developer: apply pending database updates (the same thing `php bin/migrate.php` does) from the browser.
// Standalone on purpose: when the database is behind the code the normal admin pages stop with an "Update in
// progress" notice, and this page is the one thing that stays reachable, for a signed-in super administrator only.
require_once(dirname(__DIR__) . '/config.php');
use App\Database\{LegacySchemaCatchup, MigrationFailed, MigrationRunner};

if ((int)$_settings->userdata('type') !== 1) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:24px">Only the developer (super administrator) can update the database. <a href="' . base_url . 'admin/login.php">Sign in</a></body>';
    exit;
}
$dir = base_app . 'database/migrations';
$runner = new MigrationRunner($conn);
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_settings->require_csrf('html');
    $before = $runner->status($dir)['pending'];
    try {
        (new LegacySchemaCatchup($conn))->run();
        $done = $runner->run($dir, 60);
        $_settings->log_audit('Ran Database Updates', 'Developer', $done ? 'Applied: ' . implode(', ', $done) : 'Nothing pending');
        $result = ['ok' => true, 'done' => $done, 'was' => $before];
    } catch (MigrationFailed $e) {
        $result = ['ok' => false, 'msg' => $e->getMessage()];
    }
}
$st = $runner->status($dir);
$h = fn($v) => htmlspecialchars((string)$v);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Database updates</title>
<link rel="stylesheet" href="<?= base_url ?>plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="<?= base_url ?>plugins/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light"><div class="container py-4" style="max-width:720px">
  <div class="mb-3"><a href="<?= base_url ?>admin/?page=developer/dashboard"><i class="fas fa-arrow-left"></i> Developer dashboard</a></div>
  <div class="card shadow-sm"><div class="card-body">
    <h4 class="mb-1"><i class="fas fa-database text-primary"></i> Database updates</h4>
    <p class="text-muted">After you pull a new version, the database may need updating. This does what <code>php bin/migrate.php</code> does. Take a backup first if the update is large.</p>

    <?php if ($result && $result['ok']): ?>
      <div class="alert alert-success"><?= $result['done'] ? 'Applied ' . count($result['done']) . ' update(s):' : 'Nothing was pending.' ?>
        <?php if ($result['done']): ?><ul class="mb-0"><?php foreach ($result['done'] as $d): ?><li><?= $h($d) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
    <?php elseif ($result): ?>
      <div class="alert alert-danger"><b>An update failed.</b> Nothing after it was run and it was not recorded as applied. Fix the cause, then run again.<br><small><?= $h($result['msg']) ?></small></div>
    <?php endif; ?>

    <p><b><?= count($st['applied']) ?></b> applied, <b class="<?= $st['pending'] ? 'text-danger' : 'text-success' ?>"><?= count($st['pending']) ?></b> pending.</p>
    <?php if ($st['pending']): ?>
      <ul><?php foreach ($st['pending'] as $p): ?><li><code><?= $h($p) ?></code></li><?php endforeach; ?></ul>
      <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Updating...'">
        <input type="hidden" name="csrf_token" value="<?= $h($_settings->csrf_token()) ?>">
        <button class="btn btn-primary"><i class="fas fa-play"></i> Run pending updates</button>
      </form>
    <?php else: ?>
      <div class="alert alert-success mb-0"><i class="fas fa-check-circle"></i> The database is up to date.</div>
    <?php endif; ?>
    <?php foreach ($st['changed'] as $c): ?><div class="small text-warning mt-2"><i class="fas fa-exclamation-triangle"></i> <?= $h($c) ?> was edited after it was applied.</div><?php endforeach; ?>
  </div></div>
</div></body></html>
