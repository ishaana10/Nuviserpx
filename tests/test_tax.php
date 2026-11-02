<?php
/**
 * Tests for tax maths and VAT posting lines (pure functions, no database).
 *
 * Run: php tests/test_tax.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\TaxService as T;

$passed = 0;
function check(bool $cond, string $label): void
{
    global $passed;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $passed++;
    echo "  ✓ {$label}\n";
}
function sums(array $lines): array
{
    $dr = $cr = 0.0;
    foreach ($lines as [, $side, $amt]) {
        $side === T::DEBIT ? $dr += $amt : $cr += $amt;
    }
    return [round($dr, 2), round($cr, 2)];
}

echo "=== Tax maths ===\n";
check(T::taxOn(100.00, 15) === 15.00, '15% of 100 is 15');
check(T::taxOn(19.99, 15) === 3.00, '15% of 19.99 rounds to 3.00');
check(T::taxOn(100, 0) === 0.0, 'zero rate gives no tax');
$g = T::fromGross(115.00, 15);
check($g['net'] === 100.00 && $g['tax'] === 15.00, '115 gross at 15% is 100 net + 15 tax');
$g = T::fromGross(10.00, 15);
check(near2($g['net'] + $g['tax'], 10.00), 'net + tax always equals gross');
check(T::fromGross(50, 0) === ['net' => 50.0, 'tax' => 0.0], 'no tax inside a zero-rated gross');
function near2(float $a, float $b): bool { return abs($a - $b) < 0.0001; }

check(T::cleanRate('15') === 15.0 && T::cleanRate('7.5') === 7.5 && T::cleanRate('0') === 0.0, 'valid rates accepted');
check(T::cleanRate('') === null && T::cleanRate('abc') === null && T::cleanRate('-1') === null && T::cleanRate('100.1') === null, 'blank, text, negative and over 100 rejected');
check(T::cleanRate('12.34567') === 12.3457, 'rate kept to four decimals');

echo "\n=== Sale postings ===\n";
$L = T::postingLines('sale', 115.00, 15.00, 4, 9, 1);   // revenue 4, VAT output 9, receivable 1
check(count($L) === 3, 'a taxed sale posts three lines');
check($L[0] === [1, T::DEBIT, 115.00], 'receivable debited for the gross');
check($L[1] === [4, T::CREDIT, 100.00], 'revenue credited for the net, not the gross');
check($L[2] === [9, T::CREDIT, 15.00], 'VAT output credited for the tax');
check(sums($L) === [115.00, 115.00], 'the sale journal balances');

$L = T::postingLines('sale', 200.00, 0.0, 4, 9, 1);
check(count($L) === 2 && sums($L) === [200.00, 200.00], 'a tax-free sale posts two balanced lines');
check($L[1] === [4, T::CREDIT, 200.00], 'tax-free revenue is the full total');

echo "\n=== Purchase postings ===\n";
$L = T::postingLines('purchase', 230.00, 30.00, 6, 8, 2);  // expense 6, VAT input 8, payable 2
check(count($L) === 3, 'a taxed bill posts three lines');
check($L[0] === [6, T::DEBIT, 200.00], 'expense debited for the net');
check($L[1] === [8, T::DEBIT, 30.00], 'VAT input debited for the tax');
check($L[2] === [2, T::CREDIT, 230.00], 'payable credited for the gross');
check(sums($L) === [230.00, 230.00], 'the bill journal balances');
check($L[0][1] === T::DEBIT && $L[2][1] === T::CREDIT, 'a bill debits the expense and credits payables (the right way round)');

echo "\n=== Guard rails ===\n";
foreach ([[100.0, -5.0], [100.0, 100.0], [100.0, 250.0], [100.0, 0.0]] as [$tot, $tax]) {
    $L = T::postingLines('sale', $tot, $tax, 4, 9, 1);
    check(count($L) === 2 && sums($L) === [100.00, 100.00], "tax of {$tax} on a total of {$tot} posts unsplit and balanced");
}
$L = T::postingLines('sale', 100.0, 15.0, 4, 0, 1);
check(count($L) === 2 && sums($L) === [100.00, 100.00], 'no VAT account available: posts unsplit rather than failing');

mt_srand(42);
$ok = true;
for ($i = 0; $i < 500; $i++) {
    $total = round(mt_rand(1, 9999999) / 100, 2);
    $tax = mt_rand(0, 1) ? round($total * mt_rand(0, 30) / 100, 2) : 0.0;
    foreach (['sale', 'purchase'] as $k) {
        [$d, $c] = sums(T::postingLines($k, $total, $tax, 4, 9, 1));
        $ok = $ok && $d === $c && $d === round($total, 2);
    }
}
check($ok, '500 random documents of both kinds all balance to the cent');

echo "\n=== Ledger position ===\n";
$p = T::ledgerPosition(1500.00, 100.00, 400.00, 50.00);
check($p['output'] === 1400.00 && $p['input'] === 350.00 && $p['payable'] === 1050.00, 'output − input from control account movements');
$p = T::ledgerPosition(0, 0, 300.00, 0);
check($p['payable'] === -300.00, 'more input than output is a refund (negative payable)');

echo "\nAll {$passed} tax checks passed.\n";
