<?php
// Supplier withholding tax: amounts, journal lines, rate parsing and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/TaxService.php";
require_once "$root/src/Services/BillPaymentService.php";
use App\Services\BillPaymentService as B;
use App\Services\TaxService as T;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('15% of 1000', B::withheld(1000, 15) === 150.0);
check('rounds to the cent', B::withheld(333.33, 15) === 50.0);
check('zero rate withholds nothing', B::withheld(1000, 0) === 0.0);
check('zero payment withholds nothing', B::withheld(0, 15) === 0.0);
check('rate is capped at 100', B::withheld(100, 150) === 100.0);

check('no tax: the old two lines', B::lines(10, 20, 100) === [[10, T::DEBIT, 100.0], [20, T::CREDIT, 100.0]]);
check('no account given: no tax line', count(B::lines(10, 20, 100, 0, 15)) === 2);
$l = B::lines(10, 20, 100, 30, 15);
check('with tax: Dr AP gross, Cr bank net, Cr tax payable', $l === [[10, T::DEBIT, 100.0], [20, T::CREDIT, 85.0], [30, T::CREDIT, 15.0]]);
$d = 0; $c = 0; foreach ($l as $x) { if ($x[1] === T::DEBIT) { $d += $x[2]; } else { $c += $x[2]; } }
check('journal balances', abs($d - $c) < 0.0001);

check('no error for zero or part', B::whtError(100, 0) === null && B::whtError(100, 15) === null);
check('negative rejected', B::whtError(100, -1) !== null);
check('whole payment cannot be withheld', B::whtError(100, 100) !== null && B::whtError(100, 120) !== null);

check('blank rate is zero', B::cleanRate('') === [0.0, null]);
check('rate with percent sign', B::cleanRate('15%') === [15.0, null]);
check('rate above 50 refused', B::cleanRate('60')[1] !== null);
check('negative and text rates refused', B::cleanRate('-1')[1] !== null && B::cleanRate('abc')[1] !== null);

$svc = file_get_contents("$root/src/Services/BillPaymentService.php");
check('payment checks the withheld amount', strpos($svc, 'self::whtError($amount, $wht)') !== false);
check('payment posts the tax line', strpos($svc, 'self::lines($apAcc, $bankAcc, $amount, $whtAcc, $wht)') !== false);
check('foreign-currency bank refused with tax withheld', strpos($svc, '$bankCur !== null && $wht > 0') !== false);
check('withheld tax is recorded in the same transaction', strpos($svc, 'SET wht_amount') > strpos($svc, 'begin_transaction') && strpos($svc, 'SET wht_amount') < strpos($svc, '$this->conn->commit()'));
check('report counts only posted payments', strpos($svc, "p.status = 'posted' AND p.wht_amount > 0") !== false);
$mig = file_get_contents("$root/database/migrations/033_supplier_wht.sql");
check('migration adds the three columns', substr_count($mig, 'ADD COLUMN') === 3);
$party = file_get_contents("$root/src/Services/PartyService.php");
check('supplier rate is validated and saved', strpos($party, 'BillPaymentService::cleanRate') !== false && substr_count($party, "`wht_rate` = '{\$whtRate}'") === 2);
exit($fail ? 1 : 0);
