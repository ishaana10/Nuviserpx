<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Support\Money; use App\Services\LedgerService;
$f = 0; function check($n, $c) { global $f; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $f++; }

check('cents of 0.1+0.2 is 30', Money::cents(0.1 + 0.2) === 30);
check('1.005 style and string inputs', Money::cents('19.99') === 1999 && Money::cents(19.995) === 2000 && Money::cents('-2.50') === -250 && Money::cents(null) === 0 && Money::cents('abc') === 0);
check('format and toFloat', Money::format(-1205) === '-12.05' && Money::format(5) === '0.05' && Money::toFloat(1999) === 19.99);

// 10,000 lines of 0.10 each side: floats drift, cents do not.
$lines = [];
for ($i = 0; $i < 10000; $i++) { $lines[] = ['account_id' => 1, 'debit' => 0.10, 'credit' => 0]; $lines[] = ['account_id' => 2, 'debit' => 0, 'credit' => 0.10]; }
check('a 20,000 line journal of 0.10s balances exactly', LedgerService::validateLines($lines, [1, 2]) === []);
$lines[] = ['account_id' => 1, 'debit' => 0.01, 'credit' => 0];
$e = LedgerService::validateLines($lines, [1, 2]);
check('one cent out is rejected and says so', count($e) === 1 && strpos($e[0], 'out by 0.01') !== false);
check('thirds that sum to the total are fine', LedgerService::validateLines([['account_id' => 1, 'debit' => 100.00], ['account_id' => 2, 'credit' => 33.33], ['account_id' => 2, 'credit' => 33.33], ['account_id' => 2, 'credit' => 33.34]], [1, 2]) === []);
check('one line, negative, both sides still rejected', LedgerService::validateLines([['account_id' => 1, 'debit' => 5]], [1]) !== [] && LedgerService::validateLines([['account_id' => 1, 'debit' => -5], ['account_id' => 2, 'credit' => -5]], [1, 2]) !== [] && LedgerService::validateLines([['account_id' => 1, 'debit' => 5, 'credit' => 5], ['account_id' => 2, 'credit' => 0]], [1, 2]) !== []);
exit($f ? 1 : 0);
