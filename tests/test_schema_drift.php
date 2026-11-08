<?php
// Every table and column that classes/DBConnection.php creates on start-up must also be in the migrations or the
// base schema, so a database built from the SQL files matches one the application built for itself.
$root = dirname(__DIR__);
$db   = file_get_contents("$root/classes/DBConnection.php");
$base = file_get_contents("$root/database/nuviserpx.sql");
$mig  = '';
foreach (glob("$root/database/migrations/*.sql") as $f) { $mig .= file_get_contents($f) . "\n"; }
$all = $base . "\n" . $mig;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)` \(/', $db, $m);
$runtimeTables = array_unique($m[1]);
$created = [];
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `?(\w+)`?/', $all, $c);
foreach ($c[1] as $t) { $created[$t] = true; }
$missing = array_values(array_filter($runtimeTables, fn($t) => !isset($created[$t])));
check('found the runtime tables', count($runtimeTables) >= 60);
check('every runtime table is in the migrations or base schema' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''), !$missing);

// Columns that the application adds together with a data backfill. Adding them early from SQL would skip the backfill.
$backfilled = ['journal_items.entry_side', 'account_list.normal_balance'];
preg_match_all('/"ALTER TABLE `(\w+)` ADD COLUMN `(\w+)`/', $db, $a, PREG_SET_ORDER);
$cols = [];
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `?(\w+)`? \((.*?)\)\s*ENGINE/s', $all, $ct, PREG_SET_ORDER);
foreach ($ct as [, $t, $body]) { preg_match_all('/^\s*`(\w+)`/m', $body, $cc); foreach ($cc[1] as $col) { $cols["$t.$col"] = true; } }
preg_match_all('/ALTER TABLE `?(\w+)`? ADD COLUMN(?: IF NOT EXISTS)? `(\w+)`/', $all, $ac, PREG_SET_ORDER);
foreach ($ac as [, $t, $col]) { $cols["$t.$col"] = true; }
$missingCols = [];
foreach ($a as [, $t, $col]) {
    if (in_array("$t.$col", $backfilled, true)) { continue; }
    if (!isset($cols["$t.$col"])) { $missingCols[] = "$t.$col"; }
}
check('found the runtime columns', count($a) >= 100);
check('every runtime column is in the migrations or base schema' . ($missingCols ? ' (missing: ' . implode(', ', array_slice(array_unique($missingCols), 0, 12)) . ')' : ''), !$missingCols);

$m24 = file_get_contents("$root/database/migrations/024_runtime_schema_catchup.sql");
check('backfilled columns stay out of the SQL catch-up', strpos($m24, '`entry_side`') === false && strpos($m24, '`normal_balance`') === false);
check('catch-up SQL has no AFTER clauses', !preg_match('/\bAFTER\s+`/', $m24));
check('catch-up SQL has no semicolons in comments', !preg_match('/^--.*;/m', $m24));
check('catch-up SQL only creates what is missing', substr_count($m24, 'CREATE TABLE IF NOT EXISTS') === substr_count($m24, 'CREATE TABLE'));
exit($fail ? 1 : 0);
