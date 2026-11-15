<?php
// API keys: hashed at rest, legacy keys keep working and are upgraded on first use, the key is shown once, scrub is explicit.
require __DIR__ . '/_harness.php';
use App\Support\ApiKey;
[$c, $db] = nv_fresh_db();

// A key created by the old code (plain text, no hash), as an existing install would have it.
$c->query("ALTER TABLE api_keys DROP INDEX uniq_api_key_hash");
$c->query("INSERT INTO api_keys (tenant_id,name,api_key,api_secret,permissions,status) VALUES (1,'legacy','erpx_legacy0123456789abcdef0123456789','sec','*',1)");
$c->query("UPDATE api_keys SET key_hash = NULL, key_prefix = NULL");
$c->query("CREATE UNIQUE INDEX uniq_api_key_hash ON api_keys (key_hash)");

$row = ApiKey::find($c, 'erpx_legacy0123456789abcdef0123456789');
check('an existing plain-text key still authenticates', $row && $row['name'] === 'legacy');
$after = $c->query("SELECT key_hash, key_prefix FROM api_keys WHERE name='legacy'")->fetch_assoc();
check('and is given a hash the first time it is used', $after['key_hash'] === hash('sha256', 'erpx_legacy0123456789abcdef0123456789') && $after['key_prefix'] === 'erpx_lega');
check('a wrong key is refused', ApiKey::find($c, 'erpx_legacy0123456789abcdef0123456780') === null && ApiKey::find($c, '') === null && ApiKey::find($c, str_repeat('a', 500)) === null);
check('SQL in the key is just a wrong key', ApiKey::find($c, "' OR '1'='1") === null && ApiKey::find($c, "x' OR key_hash IS NULL -- ") === null);

// New key as the app creates it
$k = ApiKey::generate();
$c->query("INSERT INTO api_keys (tenant_id,name,api_key,key_hash,key_prefix,api_secret,permissions,status) VALUES (1,'fresh','{$k['display']}','{$k['hash']}','{$k['prefix']}','','read_products',1)");
$r = $c->query("SELECT * FROM api_keys WHERE name='fresh'")->fetch_assoc();
check('the plain key is nowhere in the stored row', strpos(json_encode($r), $k['key']) === false && ApiKey::isMasked($r['api_key']));
check('the new key authenticates', ApiKey::find($c, $k['key'])['name'] === 'fresh');
check('the masked placeholder does not authenticate', ApiKey::find($c, $r['api_key']) === null);
check('lists show the prefix and no secret', strpos(ApiKey::display($r), $k['prefix']) === 0 && strpos(ApiKey::display($r), substr($k['key'], 9)) === false);
$c->query("UPDATE api_keys SET status = 0 WHERE name='fresh'");
check('a revoked key is refused', ApiKey::find($c, $k['key']) === null);
check('two generated keys differ and have 128 bits of randomness', ApiKey::generate()['key'] !== ApiKey::generate()['key'] && strlen($k['key']) === 37);

// Migration backfill: run its UPDATE on a legacy row
$c->query("INSERT INTO api_keys (tenant_id,name,api_key,api_secret,permissions,status) VALUES (1,'legacy2','erpx_backfill0000000000000000000000','s','*',1)");
$mig = file_get_contents(base_app . 'database/migrations/046_api_key_hash.sql');
preg_match('/^UPDATE .*;$/m', $mig, $m);
$c->query($m[0]);
$bf = $c->query("SELECT key_hash FROM api_keys WHERE name='legacy2'")->fetch_row()[0];
check('the migration backfills hashes that match what the app computes', $bf === hash('sha256', 'erpx_backfill0000000000000000000000'));
check('the migration does not touch already-hashed or masked rows', $c->query("SELECT key_hash FROM api_keys WHERE name='fresh'")->fetch_row()[0] === $k['hash']);

// Scrub script
$env = sprintf('DB_SERVER=%s DB_USERNAME=%s DB_PASSWORD=%s DB_NAME=%s', ...array_map('escapeshellarg', array_merge(nv_db_server(), [$db])));
$run = fn($args) => (string)shell_exec("cd " . escapeshellarg(base_app) . " && $env php bin/scrub-api-keys.php $args 2>&1");
$dry = $run('');
check('scrub without --yes changes nothing', strpos($dry, 'Nothing changed') !== false && $c->query("SELECT api_key FROM api_keys WHERE name='legacy2'")->fetch_row()[0] === 'erpx_backfill0000000000000000000000');
$run('--yes');
$plain = (int)$c->query("SELECT COUNT(*) FROM api_keys WHERE key_hash IS NOT NULL AND api_key NOT LIKE '%…%'")->fetch_row()[0];
check('scrub --yes removes every plain key', $plain === 0);
check('and the scrubbed keys still authenticate by hash', ApiKey::find($c, 'erpx_backfill0000000000000000000000')['name'] === 'legacy2' && ApiKey::find($c, 'erpx_legacy0123456789abcdef0123456789')['name'] === 'legacy');
nv_done();
