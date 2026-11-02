<?php
/**
 * Tests for exchange-rate conversion and foreign-currency revaluation (pure functions, no database).
 *
 * Run: php tests/test_fx.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\FxService as F;

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
function net(array $lines): array
{
    $dr = $cr = 0.0;
    foreach ($lines as $l) {
        $dr += $l['debit'];
        $cr += $l['credit'];
    }
    return [round($dr, 2), round($cr, 2)];
}

echo "=== Rates and conversion ===\n";
check(F::toBase(1000, 2.25) === 2250.00, '1,000 USD at 2.25 is 2,250.00 base');
check(F::toBase(33.33, 2.2417) === 74.72, 'conversion rounds to cents');
check(F::cleanRate('2.25') === 2.25 && F::cleanRate('0.00000123') === 0.00000123 && F::cleanRate('1,234.5') === 1234.5, 'valid rates accepted');
check(F::cleanRate('') === null && F::cleanRate('0') === null && F::cleanRate('-2') === null && F::cleanRate('x') === null && F::cleanRate('1e12') === null, 'blank, zero, negative, text and absurd rates rejected');

echo "\n=== Converting journal lines ===\n";
$rates = ['USD' => 2.25, 'NZD' => 1.40];
$rateFor = fn ($c) => $rates[$c] ?? null;
$lines = [
    ['account_id' => 1, 'debit' => 1000.0, 'credit' => 0.0, 'currency' => 'usd'],
    ['account_id' => 2, 'debit' => 0.0, 'credit' => 2250.0, 'currency' => ''],
];
[$out, $err] = F::convertLines($lines, 'FJD', $rateFor);
check(!$err, 'no errors when the rate exists');
check($out[0]['debit'] === 2250.00 && $out[0]['amount_fc'] === 1000.00 && $out[0]['currency'] === 'USD' && $out[0]['fx_rate'] === 2.25, 'foreign line stored in base with the original kept');
check($out[1]['credit'] === 2250.00 && $out[1]['currency'] === null, 'a base-currency line is untouched');
check(net($out) === [2250.00, 2250.00], 'the converted journal balances');

[$out, $err] = F::convertLines([['account_id' => 1, 'debit' => 10.0, 'credit' => 0.0, 'currency' => 'EUR']], 'FJD', $rateFor);
check(count($err) === 1 && str_contains($err[0], 'EUR'), 'a currency with no rate is refused, naming it');

[$out, $err] = F::convertLines([['account_id' => 1, 'debit' => 10.0, 'credit' => 0.0, 'currency' => 'FJD']], 'FJD', $rateFor);
check(!$err && $out[0]['currency'] === null && $out[0]['debit'] === 10.0, 'the base currency needs no rate');

[$out] = F::convertLines([['account_id' => 1, 'debit' => 0.0, 'credit' => 100.0, 'currency' => 'USD', 'fx_rate' => '2.5']], 'FJD', $rateFor);
check($out[0]['credit'] === 250.00 && $out[0]['fx_rate'] === 2.5, 'a rate typed on the line wins over the table');

echo "\n=== Revaluation ===\n";
// A 1,000 USD receivable booked at 2.25 = 2,250 base. Closing rate 2.30.
$g = [['account_id' => 10, 'currency' => 'USD', 'fc_net' => 1000.00, 'base_net' => 2250.00]];
$a = F::adjustments($g, ['USD' => 2.30]);
check(count($a['rows']) === 1 && $a['rows'][0]['adjustment'] === 50.00 && $a['rows'][0]['revalued'] === 2300.00, 'receivable gains 50 when the rate rises');
$L = F::journalLines($a['rows'], 99);
check(net($L) === [50.00, 50.00], 'the revaluation journal balances');
check($L[0]['account_id'] === 10 && $L[0]['debit'] === 50.00 && $L[1]['account_id'] === 99 && $L[1]['credit'] === 50.00, 'Dr receivable, Cr unrealised FX gain');

// After posting, the account carries 2,300; revaluing again at the same rate does nothing.
$again = F::adjustments([['account_id' => 10, 'currency' => 'USD', 'fc_net' => 1000.00, 'base_net' => 2300.00]], ['USD' => 2.30]);
check(!$again['rows'], 'a second run at the same rate posts nothing (idempotent)');

// Rate then falls to 2.20: cumulative, so it takes 2,300 down to 2,200.
$down = F::adjustments([['account_id' => 10, 'currency' => 'USD', 'fc_net' => 1000.00, 'base_net' => 2300.00]], ['USD' => 2.20]);
check($down['rows'][0]['adjustment'] === -100.00, 'the next period restates from the carried value, not from cost');
$L = F::journalLines($down['rows'], 99);
check($L[0]['credit'] === 100.00 && $L[1]['debit'] === 100.00 && net($L) === [100.00, 100.00], 'a fall is Cr receivable, Dr unrealised FX loss');

// A USD payable (credit balance): -1,000 USD carried at -2,250. Rate rises to 2.30 => owe more => loss.
$p = F::adjustments([['account_id' => 20, 'currency' => 'USD', 'fc_net' => -1000.00, 'base_net' => -2250.00]], ['USD' => 2.30]);
check($p['rows'][0]['adjustment'] === -50.00, 'a payable grows when the rate rises');
$L = F::journalLines($p['rows'], 99);
check($L[0]['credit'] === 50.00 && $L[1]['debit'] === 50.00, 'Cr payable, Dr unrealised FX loss');

// Settled in full at a different rate: foreign balance 0, base residue -50 => clear it.
$s = F::adjustments([['account_id' => 10, 'currency' => 'USD', 'fc_net' => 0.00, 'base_net' => -50.00]], ['USD' => 2.30]);
check($s['rows'][0]['adjustment'] === 50.00, 'a settled balance with a base residue is cleared to zero');

// Several accounts and currencies net onto one FX line.
$m = F::adjustments([
    ['account_id' => 10, 'currency' => 'USD', 'fc_net' => 1000.00, 'base_net' => 2250.00],
    ['account_id' => 20, 'currency' => 'USD', 'fc_net' => -400.00, 'base_net' => -900.00],
    ['account_id' => 30, 'currency' => 'NZD', 'fc_net' => 500.00, 'base_net' => 700.00],
], ['USD' => 2.30, 'NZD' => 1.38]);
$L = F::journalLines($m['rows'], 99);
check(net($L)[0] === net($L)[1], 'a mixed run balances');
$fxLines = array_values(array_filter($L, fn ($l) => $l['account_id'] === 99));
check(count($fxLines) === 1, 'one FX line carries the net gain or loss');

check(F::adjustments([['account_id' => 1, 'currency' => 'EUR', 'fc_net' => 5.0, 'base_net' => 10.0]], ['USD' => 2.3])['missing'] === ['EUR'], 'a currency with no closing rate is reported, not guessed');
check(!F::journalLines([], 99), 'no adjustments, no journal');

mt_srand(7);
$ok = true;
for ($i = 0; $i < 300; $i++) {
    $groups = [];
    for ($k = 1; $k <= mt_rand(1, 6); $k++) {
        $fc = round(mt_rand(-500000, 500000) / 100, 2);
        $groups[] = ['account_id' => $k, 'currency' => 'USD', 'fc_net' => $fc, 'base_net' => round($fc * mt_rand(180, 260) / 100, 2)];
    }
    $a = F::adjustments($groups, ['USD' => mt_rand(180, 260) / 100]);
    $L = F::journalLines($a['rows'], 99);
    $n = net($L);
    $ok = $ok && $n[0] === $n[1];
}
check($ok, '300 random revaluation runs all balance to the cent');

echo "\nAll {$passed} FX checks passed.\n";
