<?php
// The backup's "is this dump intact?" check must accept a realistically sized dump and reject a damaged/empty one.
require __DIR__ . '/../vendor/autoload.php';
use App\Support\BackupPlan;
$fail = 0;
function check($n, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $n\n"; if (!$ok) { $fail++; } }
function run(string $cmd): int { $o = []; exec('bash -o pipefail -c ' . escapeshellarg($cmd) . ' 2>/dev/null', $o, $rc); return $rc; }

$dir = sys_get_temp_dir() . '/nvdump' . bin2hex(random_bytes(4));
mkdir($dir);
// ~2 MB of SQL, far above any pipe buffer: the case that used to be thrown away.
$sql = "-- dump\nCREATE TABLE `t` (id int);\n";
for ($i = 0; $i < 40000; $i++) { $sql .= "INSERT INTO `t` VALUES ($i,'row number $i with some padding text to make it bigger');\n"; }
file_put_contents("$dir/big.sql", $sql);
exec('gzip -c ' . escapeshellarg("$dir/big.sql") . ' > ' . escapeshellarg("$dir/big.sql.gz"));
check('large intact dump passes', run(BackupPlan::dumpCheckCommand("$dir/big.sql.gz")) === 0);

file_put_contents("$dir/small.sql", "CREATE TABLE a (id int);\n");
exec('gzip -c ' . escapeshellarg("$dir/small.sql") . ' > ' . escapeshellarg("$dir/small.sql.gz"));
check('small intact dump passes', run(BackupPlan::dumpCheckCommand("$dir/small.sql.gz")) === 0);

file_put_contents("$dir/empty.sql", "-- nothing here\n");
exec('gzip -c ' . escapeshellarg("$dir/empty.sql") . ' > ' . escapeshellarg("$dir/empty.sql.gz"));
check('dump without tables is rejected', run(BackupPlan::dumpCheckCommand("$dir/empty.sql.gz")) !== 0);

file_put_contents("$dir/bad.sql.gz", substr(file_get_contents("$dir/big.sql.gz"), 0, 5000));
check('truncated dump is rejected', run(BackupPlan::dumpCheckCommand("$dir/bad.sql.gz")) !== 0);
check('missing file is rejected', run(BackupPlan::dumpCheckCommand("$dir/nope.gz")) !== 0);
check('backup.php uses the shared check', strpos(file_get_contents(__DIR__ . '/../bin/backup.php'), 'BackupPlan::dumpCheckCommand') !== false);
exec('rm -rf ' . escapeshellarg($dir));
exit($fail ? 1 : 0);
