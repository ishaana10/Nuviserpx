<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\JournalService as J;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
$rate = fn ($c) => $c === 'USD' ? 2.0 : null;   // 1 USD = 2.00 base

// Base-only: balance goes to OBE.
[$f, $e] = J::openingBalanceForm(['account_id' => [1, 2], 'debit' => [1000, 0], 'credit' => [0, 400]], 99, 'FJD', $rate);
check('base only: no errors', $e === []);
check('base only: OBE credit 600', end($f['account_id']) === 99 && end($f['credit']) === 600.0 && end($f['debit']) === 0.0);

// Foreign bank: 500 USD debit => 1000 base; OBE credits 1000; the form keeps the foreign amount + rate.
[$f, $e] = J::openingBalanceForm(['account_id' => [1], 'debit' => [500], 'credit' => [0], 'currency' => ['USD'], 'fx_rate' => ['']], 99, 'FJD', $rate);
check('foreign: no errors', $e === []);
check('foreign: line keeps foreign amount and currency', $f['debit'][0] == 500 && $f['currency'][0] === 'USD' && (float)$f['fx_rate'][0] == 2.0);
check('foreign: OBE balances on the base value', $f['credit'][1] === 1000.0 && $f['currency'][1] === '');

// Explicit rate overrides the dated one.
[$f, ] = J::openingBalanceForm(['account_id' => [1], 'debit' => [100], 'credit' => [0], 'currency' => ['USD'], 'fx_rate' => ['2.5']], 99, 'FJD', $rate);
check('explicit rate used', $f['credit'][1] === 250.0);

// Missing rate is an error, not a silent base posting.
[, $e] = J::openingBalanceForm(['account_id' => [1], 'debit' => [100], 'credit' => [0], 'currency' => ['EUR']], 99, 'FJD', $rate);
check('missing rate rejected', count($e) === 1);

// Other validation.
[, $e] = J::openingBalanceForm(['account_id' => [99], 'debit' => [5], 'credit' => [0]], 99, 'FJD', $rate);
check('OBE line rejected', count($e) === 1);
[, $e] = J::openingBalanceForm(['account_id' => [1], 'debit' => [5], 'credit' => [5]], 99, 'FJD', $rate);
check('debit and credit together rejected', count($e) === 1);
[, $e] = J::openingBalanceForm(['account_id' => [1], 'debit' => [0], 'credit' => [0]], 99, 'FJD', $rate);
check('empty rejected', count($e) === 1);

// Already balanced: no OBE line.
[$f, ] = J::openingBalanceForm(['account_id' => [1, 2], 'debit' => [50, 0], 'credit' => [0, 50]], 99, 'FJD', $rate);
check('balanced input adds no OBE line', count($f['account_id']) === 2);

echo $fail ? "\n$fail FAILED\n" : "\nAll opening balance tests passed\n";
exit($fail ? 1 : 0);
