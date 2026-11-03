<?php
/** Tests for foreign-currency invoices and receipts (no database). Run: php tests/test_fx_documents.php */
require_once(__DIR__ . '/../vendor/autoload.php');
use App\Services\FxService as F;
use App\Services\TaxService as T;

$passed = 0;
function check(bool $c, string $l): void { global $passed; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } $passed++; echo "  ✓ $l\n"; }
function net(array $lines): float { $d = $c = 0.0; foreach ($lines as $l) { if ($l[1] === T::DEBIT) $d += $l[2]; else $c += $l[2]; } return round($d - $c, 2); }

echo "=== Allocation: carried value ===\n";
check(F::allocationBase(500, 1000, 2250.00, 2.25) === 1125.00, 'a part payment takes its share at the invoice rate');
check(F::allocationBase(1000, 1000, 2250.00, 2.25) === 2250.00, 'the final payment takes the whole remaining value');
check(F::allocationBase(333.33, 666.66, 1499.99, 2.25) === 749.99, 'cents are carried at the invoice rate (749.9925 rounds to 749.99)');
check(F::allocationBase(666.66, 666.66, 750.00, 2.25) === 750.00, 'clearing the last foreign unit absorbs rounding cents');
check(F::allocationBase(10, 5, 11.25, 2.25) === 11.25, 'never takes more than remains');

echo "\n=== Receipt journal ===\n";
$gain = F::receiptLines(10, 20, 30, 2400.00, 2250.00, 'USD', 2.4, 1000);
check(net($gain) === 0.0, 'a gain receipt balances');
check($gain[2] === [30, T::CREDIT, 150.00], 'cash above the carried value is a credit gain');
check($gain[1][3]['amount_fc'] === 1000.0 && $gain[1][3]['currency'] === 'USD', 'the receivable line carries the foreign amount');
$loss = F::receiptLines(10, 20, 30, 2100.00, 2250.00, 'USD', 2.1, 1000);
check(net($loss) === 0.0, 'a loss receipt balances');
check($loss[2] === [30, T::DEBIT, 150.00], 'cash below the carried value is a debit loss');
$flat = F::receiptLines(10, 20, 30, 2250.00, 2250.00, 'USD', 2.25, 1000);
check(count($flat) === 2 && net($flat) === 0.0, 'no exchange line when the rate has not moved');

echo "\n=== End to end: invoice, two receipts ===\n";
$rateInv = 2.25; $totalFc = 1000.00; $totalBase = F::toBase($totalFc, $rateInv);
$arLeftBase = $totalBase; $arLeftFc = $totalFc; $realised = 0.0; $cashTotal = 0.0;
foreach ([[400.00, 2.30], [600.00, 2.10]] as [$fc, $payRate]) {
    $ar = F::allocationBase($fc, $arLeftFc, $arLeftBase, $rateInv);
    $cash = F::toBase($fc, $payRate);
    $lines = F::receiptLines(1, 2, 3, $cash, $ar, 'USD', $payRate, $fc);
    check(net($lines) === 0.0, "receipt of {$fc} at {$payRate} balances");
    $realised += round($cash - $ar, 2); $cashTotal += $cash;
    $arLeftBase = round($arLeftBase - $ar, 2); $arLeftFc = round($arLeftFc - $fc, 2);
}
check($arLeftBase === 0.0 && $arLeftFc === 0.0, 'the invoice clears to exactly zero in both currencies');
check(round($cashTotal - $totalBase - $realised, 2) === 0.0, 'cash received = invoice value + realised gain/loss');
check($realised === round(2.30*400 + 2.10*600 - 2250, 2), 'realised result is cash less carried value (' . $realised . ')');

echo "\n=== Credit note on a foreign invoice ===\n";
// Invoice USD 1000 at 2.25 = 2250 base, nothing paid. Two notes of USD 400 then the last USD 600.
$remFc = 1000.0; $remBase = 2250.0; $credited = 0.0;
foreach ([400.0, 600.0] as $fcNote) {
    $base = F::allocationBase($fcNote, $remFc, $remBase, 2.25);
    $credited += $base; $remFc = round($remFc - $fcNote, 2); $remBase = round($remBase - $base, 2);
}
check($credited === 2250.0 && $remBase === 0.0 && $remFc === 0.0, 'notes that clear the foreign balance clear the base balance exactly');
$base = F::allocationBase(100.0, 1000.0, 2250.0, 2.25); $tax = min(F::toBase(15.0, 2.25), $base);
check($base === 225.0 && $tax === 33.75 && round($base - $tax, 2) === 191.25, 'a USD 100 note incl. USD 15 tax posts 191.25 net + 33.75 tax = 225.00');
$j = \App\Services\CreditNoteService::noteLines('credit', 225.0, 33.75, 2, 3, 1);
check(round(array_sum(array_column($j, 'debit')) - array_sum(array_column($j, 'credit')), 2) === 0.0, 'the note journal balances');

echo "\n=== Foreign bank account ===\n";
// USD account holds 1000 USD carried at 2,250 base (avg 2.25). Pay 450 base when USD is 2.30.
$o = F::bankOutflow(450.00, 2.30, 1000.0, 2250.0);
check($o['fc'] === 195.65, '450 base at 2.30 takes 195.65 USD out');
check($o['carried'] === 440.21 && $o['gain'] === 9.79, 'those units were carried at 2.25 = 440.21, so paying 450 books 9.79 realised gain');
$l = F::bankOutflowLines(20, 10, 30, 450.00, $o, 'USD');
check(net($l) === 0.0, 'the outflow journal balances');
check($l[1][3]['amount_fc'] === 195.65 && $l[1][1] === T::CREDIT, 'the bank credit carries the foreign amount');
$o = F::bankOutflow(450.00, 2.10, 1000.0, 2250.0);
check($o['gain'] < 0 && net(F::bankOutflowLines(20, 10, 30, 450.00, $o, 'USD')) === 0.0, 'a weaker rate books a loss and still balances');
$o = F::bankOutflow(2300.00, 2.30, 1000.0, 2250.0);
check($o['carried'] === 2250.00, 'emptying the account takes the whole carried value');
$o = F::bankOutflow(230.00, 2.30, 0.0, 0.0);
check($o['gain'] === 0.0, 'an account with nothing on record books no difference');
$rc = F::receiptLines(10, 20, 30, 2300.00, 2250.00, 'USD', 2.30, 1000, true);
check(isset($rc[0][3]) && $rc[0][3]['amount_fc'] === 1000.0, 'a receipt into a foreign account tags the bank line');
check(!isset(F::receiptLines(10, 20, 30, 2300.00, 2250.00, 'USD', 2.30, 1000)[0][3]), 'a receipt into a company-currency account does not');
check(net($rc) === 0.0, 'the tagged receipt still balances');

echo "\n=== Foreign bank reconciliation ===\n";
check(F::untracked(2250.00, 2250.00) === 0.0, 'a fully tagged account has nothing untracked');
check(F::untracked(3000.00, 2250.00) === 750.0, 'balance from before the account was foreign shows as untracked');
check(F::untracked(2250.004, 2250.00) === 0.0, 'sub-cent differences are ignored');
$rs = file_get_contents(__DIR__ . '/../src/Services/BankReconciliationService.php');
check(strpos($rs, 'movementAmountSql') !== false && strpos($rs, 'ji.amount_fc') !== false, 'a foreign account is matched on foreign amounts');
check(strpos($rs, "'untracked_base'") !== false, 'the summary reports untracked balance');

echo "\n=== Plumbing ===\n";
$m = file_get_contents(__DIR__ . '/../classes/DBConnection.php');
check(strpos($m, 'check_fx_document_migrations();') !== false, 'the migration is in the chain');
check(strpos(file_get_contents(__DIR__ . '/../src/Services/PaymentService.php'), 'saveForeignReceipt') !== false, 'foreign receipts have their own path');
check(F::REALISED_ACCOUNT === 'Realised FX Gain/Loss', 'realised gains have their own account');
echo "\nAll {$passed} foreign-currency document checks passed.\n";
