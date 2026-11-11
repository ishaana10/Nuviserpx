<?php
// The migration runner must not lose statements to semicolons inside comments or strings.
$root = dirname(__DIR__);
require_once "$root/src/Database/MigrationRunner.php";
use App\Database\MigrationRunner as R;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('plain split', R::splitStatements('A; B;') === ['A', 'B']);
check('semicolon in a line comment is ignored', R::splitStatements("-- x; y\nSELECT 1;") === ['SELECT 1']);
check('semicolon in a block comment is ignored', R::splitStatements("/* a; b */ SELECT 1; SELECT 2") === ['SELECT 1', 'SELECT 2']);
check('semicolon in a string is kept', R::splitStatements("INSERT INTO t VALUES ('a;b'); SELECT 1") === ["INSERT INTO t VALUES ('a;b')", 'SELECT 1']);
check('escaped quote does not end the string', R::splitStatements("SELECT 'it\\'s; ok'; SELECT 2") === ["SELECT 'it\\'s; ok'", 'SELECT 2']);
check('trailing comment after a statement', R::splitStatements("SELECT 1; -- done; really\n") === ['SELECT 1']);
check('double dash inside a string is kept', R::splitStatements("SELECT '--x'; SELECT 2") === ["SELECT '--x'", 'SELECT 2']);

// Every real migration: the first statement must start with a SQL verb, never with leftover comment text.
$verbs = '/^(CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|SET|REPLACE|RENAME|TRUNCATE|CALL|SELECT)\b/i';
foreach (glob("$root/database/migrations/*.sql") as $f) {
    $bad = array_filter(R::splitStatements(file_get_contents($f)), fn($s) => !preg_match($verbs, $s));
    check(basename($f) . ' splits into real statements', !$bad);
}
$s007 = implode("\n", R::splitStatements(file_get_contents("$root/database/migrations/007_crm_activities.sql")));
check('007 now yields the crm_activities table', strpos($s007, 'CREATE TABLE IF NOT EXISTS `crm_activities`') !== false);
$s028 = file_get_contents("$root/database/migrations/028_catchup_crm_warehouse.sql");
check('catch-up creates crm_activities and product_stock', strpos($s028, '`crm_activities`') !== false && strpos($s028, '`product_stock`') !== false);
// ---- portable conditional column additions
$a = R::parseConditionalAddColumn("ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `name` varchar(150) DEFAULT NULL AFTER `tenant_id`");
check('parses table and column', $a && $a['table'] === 'webhooks' && $a['column'] === 'name');
check('IF NOT EXISTS is removed from the SQL', $a && stripos($a['sql'], 'IF NOT EXISTS') === false && strpos($a['sql'], 'ADD COLUMN `name` varchar(150)') !== false);
check('a version without AFTER is offered', $a && stripos($a['sqlNoPosition'], 'AFTER') === false && stripos($a['sql'], 'AFTER `tenant_id`') !== false);
check('unquoted names parse', R::parseConditionalAddColumn('alter table t add column if not exists c int')['column'] === 'c');
check('ordinary ALTER is not touched', R::parseConditionalAddColumn('ALTER TABLE `t` ADD COLUMN `c` int') === null);
check('CREATE is not touched', R::parseConditionalAddColumn('CREATE TABLE IF NOT EXISTS `t` (id int)') === null);
$n = 0;
foreach (glob("$root/database/migrations/*.sql") as $f) {
    foreach (R::splitStatements(file_get_contents($f)) as $st) {
        if (stripos($st, 'IF NOT EXISTS') !== false && preg_match('/^\s*ALTER\b/i', $st)) {
            $n++;
            if (R::parseConditionalAddColumn($st) === null) check(basename($f) . ' conditional ALTER is understood: ' . substr($st, 0, 60), false);
        }
    }
}
check("all $n conditional ALTERs in the migrations are understood", true);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
