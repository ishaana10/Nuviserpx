<?php
// Gap 9: payroll run references come from a per-company, per-month counter, never from rand().
$root = dirname(__DIR__);
require_once "$root/src/Services/PayrollRunRefService.php";
use App\Services\PayrollRunRefService as R;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('format', R::format('202611', 7) === 'RUN-202611-007');
check('format beyond 3 digits', R::format('202611', 1234) === 'RUN-202611-1234');
$p = R::prefix('202611');
check('suffix reads number', R::suffix('RUN-202611-042', $p) === 42);
check('suffix ignores other month', R::suffix('RUN-202610-042', $p) === null);
check('suffix ignores legacy refs', R::suffix('RUN-202611-AB', $p) === null && R::suffix('PR-123', $p) === null);
check('highest of mixed refs', R::highest(['RUN-202611-003', 'RUN-202611-010', 'RUN-202610-099', 'junk'], $p) === 10);
check('empty month starts at 1', R::nextNumber(0, [], $p) === 1);
check('counter ahead of refs (voided run deleted) still advances', R::nextNumber(5, ['RUN-202611-002'], $p) === 6);
check('refs ahead of counter (counter lost) still advances', R::nextNumber(0, ['RUN-202611-009'], $p) === 10);
$seen = []; $c = 0; $refs = [];
for ($i = 0; $i < 500; $i++) { $c = R::nextNumber($c, $refs, $p); $refs[] = R::format('202611', $c); }
check('500 sequential refs are unique', count(array_unique($refs)) === 500);

$svc = file_get_contents("$root/src/Services/PayrollService.php");
check('PayrollService no longer uses rand for refs', !preg_match('/payroll_run_ref[^;\n]*\b(rand|random_int|mt_rand)\(/', $svc));
check('PayrollService uses PayrollRunRefService', substr_count($svc, 'PayrollRunRefService') >= 2);
$rs = file_get_contents("$root/src/Services/PayrollRunRefService.php");
check('reservation is serialised with a lock', strpos($rs, 'GET_LOCK') !== false && strpos($rs, 'RELEASE_LOCK') !== false);
check('counter table is in migration 018', strpos(file_get_contents("$root/database/migrations/018_payroll_schema.sql"), 'payroll_run_counters') !== false);
exit($fail ? 1 : 0);
