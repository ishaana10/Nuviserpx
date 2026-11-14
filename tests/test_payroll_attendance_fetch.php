<?php
// unpaidDaysFor fetches each statement's result before the next executes,
// and a failed result is a logged no-deduction, never a fatal.
$root = dirname(__DIR__);
$s = file_get_contents($root . '/src/Services/PayrollAttendanceService.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
check('results are buffered immediately after each execute', strpos($s, '$leaves = $lr ? $lr->fetch_all(MYSQLI_ASSOC) : null;') !== false && strpos($s, '$absences = $ar ? $ar->fetch_all(MYSQLI_ASSOC) : null;') !== false);
check('a false result logs and deducts nothing instead of crashing', strpos($s, "Db::log(\$this->conn, 'unpaidDaysFor')") !== false && strpos($s, 'return 0.0; // without reliable records') !== false);
check('no fetch_all is chained straight onto get_result() in unpaidDaysFor', substr_count($s, 'get_result()->fetch_all') === 0);
check('holiday fetch is guarded too', strpos($s, '$holidays = $hr ? array_column($hr->fetch_all(MYSQLI_ASSOC)') !== false);
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
