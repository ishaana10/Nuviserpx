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

echo "\n=== Plumbing ===\n";
$m = file_get_contents(__DIR__ . '/../classes/DBConnection.php');
check(strpos($m, 'check_fx_document_migrations();') !== false, 'the migration is in the chain');
check(strpos(file_get_contents(__DIR__ . '/../src/Services/PaymentService.php'), 'saveForeignReceipt') !== false, 'foreign receipts have their own path');
check(F::REALISED_ACCOUNT === 'Realised FX Gain/Loss', 'realised gains have their own account');
echo "\nAll {$passed} foreign-currency document checks passed.\n";
