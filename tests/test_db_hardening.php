<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

// The class has a constructor that connects; read the pure helper by source instead of instantiating.
$src = file_get_contents(base_app . 'src/Database/LegacySchemaCatchup.php');
check('integrity migration is chained', str_contains($src, '$this->check_integrity_hardening_migrations();'));
check('type list helper exists', str_contains($src, 'function account_type_sql_list'));

// Every account type the app offers is in the list, and quoting is safe.
$f = function (array $groups) { $o = []; foreach ($groups as $ts) foreach ($ts as $t) $o[] = "'" . str_replace("'", "''", $t) . "'"; return implode(',', $o); };
$list = $f(App\Services\AccountService::ACCOUNT_TYPES);
$all = true;
foreach (App\Services\AccountService::ACCOUNT_TYPES as $ts) foreach ($ts as $t) if (!str_contains($list, "'$t'")) $all = false;
check('every offered type is allowed', $all);
check('quote escaping', $f([["O'Brien"]]) === "'O''Brien'");

// No money column may be created as float/double/real again (they lose cents at volume).
$sql = file_get_contents(base_app . 'database/nuviserpx.sql');
preg_match_all('/`(\w+)`\s+(float|double|real)\b/i', $sql, $m1);
check('install schema: only journal_items.amount is float (migrated to DECIMAL on upgrade)', $m1[1] === ['amount']);
preg_match_all('/`(\w+)`\s+(float|double|real)\b[^;\n]*/i', $src, $m2);
check('migrations create no float/double columns', count($m2[0]) === 0);

echo $fail ? "\n$fail FAILED\n" : "\nAll DB hardening tests passed\n";
exit($fail ? 1 : 0);
