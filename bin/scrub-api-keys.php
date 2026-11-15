<?php
/**
 * Remove the old plain-text API keys once every integration has been seen using the hashed lookup.
 *
 *   php bin/scrub-api-keys.php          shows how many keys are still stored in plain text (changes nothing)
 *   php bin/scrub-api-keys.php --yes    replaces each with a masked placeholder; the keys keep working (the hash is used)
 *
 * This is deliberately a separate, manual step: after it runs, rolling back to a version of the code that matches
 * on the plain column would reject every key. Take a backup first (php bin/backup.php) and run it one release after
 * migration 046 is live. Old backups still contain the plain keys - treat them accordingly.
 */
require __DIR__ . '/_common.php';
use App\Support\ApiKey;

mysqli_report(MYSQLI_REPORT_OFF);
$hp = \App\Support\BackupPlan::hostPort(DB_SERVER);
$c = @new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']);
if ($c->connect_error) { nv_fail('Cannot connect to the database: ' . $c->connect_error); }
if (!$c->query("SELECT key_hash FROM api_keys LIMIT 1")) { nv_fail('Run php bin/migrate.php first (migration 046 adds the hash column).'); }

$rows = $c->query("SELECT id, api_key, key_prefix FROM api_keys WHERE key_hash IS NOT NULL AND api_key NOT LIKE '%" . ApiKey::MASK . "%'")->fetch_all(MYSQLI_ASSOC);
$unhashed = (int)$c->query("SELECT COUNT(*) FROM api_keys WHERE key_hash IS NULL")->fetch_row()[0];
nv_say(count($rows) . ' key(s) still stored in plain text; ' . $unhashed . ' without a hash (cannot be scrubbed).');
if (!in_array('--yes', $argv, true)) { nv_say('Nothing changed. Add --yes to scrub.'); exit(0); }

$up = $c->prepare("UPDATE api_keys SET api_key = ?, api_secret = '' WHERE id = ?");
$n = 0;
foreach ($rows as $r) {
    $masked = ApiKey::masked((string)($r['key_prefix'] ?: substr($r['api_key'], 0, 9)));
    $id = (int)$r['id'];
    $up->bind_param('si', $masked, $id);
    if ($up->execute()) { $n++; }
}
nv_say("Scrubbed {$n} key(s).");
