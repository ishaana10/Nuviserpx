<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PayrollApprovalService as A;
use App\Services\ApprovalService as P;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

check('another approver may approve', A::block([5], 9, true, false) === null);
check('maker may not approve own run', A::block([5], 5, true, false) !== null);
check('maker among several makers is blocked', A::block([5, 6, 9], 9, true, false) !== null);
check('self-approval allowed when the company allows it', A::block([5], 5, true, true) === null);
check('no permission', A::block([5], 9, false, false) !== null);
check('policy types registered', P::DOC_PAYROLL_RUN === 'payroll_run' && P::DOC_PAYROLL_REMITTANCE === 'payroll_remittance');
check('threshold: below', !P::requiresApproval(true, 1000, 999.99));
check('threshold: at', P::requiresApproval(true, 1000, 1000));
check('threshold 0 means every run', P::requiresApproval(true, 0, 1));
check('disabled never requires', !P::requiresApproval(false, 0, 1e9));

echo $fail ? "\n$fail FAILED\n" : "\nAll payroll approval tests passed\n";
exit($fail ? 1 : 0);
