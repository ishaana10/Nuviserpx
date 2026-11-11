<?php
$root = dirname(__DIR__);
require_once "$root/src/Database/SqlMode.php";
use App\Database\SqlMode as M;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
check('removes ONLY_FULL_GROUP_BY, keeps the rest', M::withoutFullGroupBy('ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO') === 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO');
check('works in the middle and at the end', M::withoutFullGroupBy('A,ONLY_FULL_GROUP_BY,B') === 'A,B' && M::withoutFullGroupBy('A,only_full_group_by') === 'A');
check('mode that is only that flag becomes empty', M::withoutFullGroupBy('ONLY_FULL_GROUP_BY') === '');
check('mode without it is unchanged', M::withoutFullGroupBy('STRICT_TRANS_TABLES') === 'STRICT_TRANS_TABLES');
check('connection applies it', strpos(file_get_contents("$root/classes/DBConnection.php"), 'SqlMode::apply') !== false);
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
