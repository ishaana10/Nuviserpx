<?php
/**
 * Tests for the cash flow statement, statement of changes in equity and budget vs actual.
 * Pure functions over balances(); no database required.
 *
 * Run: php tests/test_statements.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\LedgerService as L;

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
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

/* Same books as test_ledger_service.php, window 2026-01-01 .. 2026-10-26. */
$raw = [
    ['id' => 1, 'code' => '090', 'name' => 'Bank', 'type' => 'Bank',                       'open_dr' => 70000, 'open_cr' => 8000, 'dr' => 18000, 'cr' => 15000],
    ['id' => 2, 'code' => '110', 'name' => 'Accounts Receivable', 'type' => 'Current Asset', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 30000, 'cr' => 18000],
    ['id' => 3, 'code' => '150', 'name' => 'Equipment', 'type' => 'Fixed Asset',           'open_dr' => 0, 'open_cr' => 0, 'dr' => 10000, 'cr' => 0],
    ['id' => 4, 'code' => '250', 'name' => 'Bank loan', 'type' => 'Non-current Liability', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 0, 'cr' => 10000],
    ['id' => 5, 'code' => '300', 'name' => 'Owner capital', 'type' => 'Equity',            'open_dr' => 0, 'open_cr' => 50000, 'dr' => 0, 'cr' => 0],
    ['id' => 6, 'code' => '400', 'name' => 'Sales', 'type' => 'Revenue',                   'open_dr' => 0, 'open_cr' => 20000, 'dr' => 0, 'cr' => 30000],
    ['id' => 7, 'code' => '500', 'name' => 'Cost of goods sold', 'type' => 'Direct Costs', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 9000, 'cr' => 0],
    ['id' => 8, 'code' => '610', 'name' => 'Rent', 'type' => 'Expense',                    'open_dr' => 8000, 'open_cr' => 0, 'dr' => 6000, 'cr' => 0],
];
$b = L::shapeBalances($raw);

echo "=== Cash flow ===\n";
$cf = L::cashFlow($b);
check(near($cf['profit'], 15000), 'starts from this period’s profit (15,000)');
check(near($cf['operating'], 3000), 'operating = profit 15,000 − receivables up 12,000 = 3,000');
check(near($cf['investing'], -10000), 'investing = equipment bought (10,000)');
check(near($cf['financing'], 10000), 'financing = loan drawn 10,000');
check(near($cf['net_change'], 3000) && near($cf['opening_cash'], 62000) && near($cf['closing_cash'], 65000), 'net change 3,000 takes cash from 62,000 to 65,000');
check($cf['reconciled'] && near($cf['difference'], 0), 'the statement agrees with the bank accounts');
check(near($cf['working_assets'][0]['amount'], -12000), 'a rise in receivables reduces cash');

// Depreciation: expense 1,000 (non-cash) against accumulated depreciation (fixed asset section, credit).
$withDep = $raw;
$withDep[] = ['id' => 9, 'code' => '630', 'name' => 'Depreciation expense', 'type' => 'Depreciation', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 1000, 'cr' => 0];
$withDep[] = ['id' => 10, 'code' => '155', 'name' => 'Accumulated depreciation', 'type' => 'Fixed Asset', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 0, 'cr' => 1000];
$cfd = L::cashFlow(L::shapeBalances($withDep));
check(near($cfd['profit'], 14000), 'profit falls by the depreciation charge (14,000)');
check(near($cfd['non_cash'][0]['amount'], 1000), 'depreciation is added back as non-cash');
check(near($cfd['operating'], 3000) && near($cfd['investing'], -10000), 'cash flows are unchanged: depreciation moves no cash');
check($cfd['reconciled'], 'still agrees with the bank accounts with depreciation');

// An owner drawing paid from the bank.
$more = $raw;
$more[] = ['id' => 11, 'code' => '320', 'name' => 'Owner drawings', 'type' => 'Equity', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 500, 'cr' => 0];
$more[0]['cr'] += 500;   // Dr Owner drawings / Cr Bank 500
$cfm = L::cashFlow(L::shapeBalances($more));
check(near($cfm['financing'], 9500) && $cfm['reconciled'], 'owner drawings reduce financing cash flow');

// Mis-sectioned account: a bank account typed as an expense leaves a visible difference, not a silent one.
$bad = $raw;
$bad[7]['cr'] += 250;   // rent credited, but the matching debit was never made to bank
$cfb = L::cashFlow(L::shapeBalances($bad));
check(!$cfb['reconciled'] && near($cfb['difference'], 250), 'a one-sided entry (250 credited to rent, no bank leg) shows as a 250 difference');
$cfEmpty = L::cashFlow([]);
check($cfEmpty['net_change'] == 0.0 && $cfEmpty['reconciled'], 'empty period: zero and reconciled');

echo "\n=== Changes in equity ===\n";
$eq = L::equityStatement($b);
$byName = array_column($eq['rows'], null, 'name');
check(near($byName['Owner capital']['opening'], 50000) && near($byName['Owner capital']['closing'], 50000), 'capital opens and closes at 50,000');
check(near($byName['Retained earnings']['opening'], 12000), 'retained earnings open at last year’s profit (12,000)');
check(near($byName['Retained earnings']['profit'], 15000), 'this year’s profit is added (15,000)');
check(near($eq['totals']['closing'], 77000), 'closing equity 77,000');
check(near($eq['totals']['closing'], L::balanceSheet($b)['total_equity']), 'closing equity equals the balance sheet’s total equity');
check(near($eq['totals']['opening'] + $eq['totals']['profit'] + $eq['totals']['movement'], $eq['totals']['closing']), 'opening + profit + movements = closing');
$contrib = $raw; $contrib[4]['cr'] = 5000; $contrib[0]['dr'] += 5000;
$eq2 = L::equityStatement(L::shapeBalances($contrib));
check(near(array_column($eq2['rows'], null, 'name')['Owner capital']['movement'] ?? 0, 5000), 'capital introduced in the period shows as a movement');
check(near($eq2['totals']['closing'], L::balanceSheet(L::shapeBalances($contrib))['total_equity']), 'and still agrees with the balance sheet');

echo "\n=== Budget vs actual ===\n";
$act = [];
foreach ($b as $id => $row) { $act[$id] = $row; }
$bva = L::budgetVsActual($act, [6 => 36000, 7 => 8000, 8 => 7000]);
$lines = [];
foreach ($bva['sections'] as $s) { foreach ($s['lines'] as $l) { $lines[$l['id']] = $l; } }
check(near($lines[6]['actual'], 30000) && near($lines[6]['variance'], -6000) && !$lines[6]['favourable'], 'sales 6,000 under budget is unfavourable');
check(near($lines[6]['variance_pct'], -16.7), 'variance % is against the budget (−16.7%)');
check(near($lines[7]['variance'], 1000) && !$lines[7]['favourable'], 'cost of sales 1,000 over budget is unfavourable');
check(near($lines[8]['variance'], -1000) && $lines[8]['favourable'], 'rent 1,000 under budget is favourable');
check(near($bva['profit']['actual'], 15000) && near($bva['profit']['budget'], 21000) && near($bva['profit']['variance'], -6000), 'profit 15,000 against budget 21,000');
check(!isset($lines[1]) && !isset($lines[5]), 'balance sheet accounts are never budgeted');
$none = L::budgetVsActual($act, []);
check(near($none['profit']['actual'], 15000) && $none['profit']['budget'] == 0.0 && $none['sections']['revenue']['lines'][0]['variance_pct'] === null, 'no budget: actuals still show and % is blank');
$unspent = L::budgetVsActual([8 => $act[8]], [8 => 7000, 99 => 500]);
check(count($unspent['sections']['expense']['lines']) === 1, 'a budget line for an unknown account is ignored');
$zeroAct = $act[8]; $zeroAct['dr'] = 0.0; $zeroAct['cr'] = 0.0;
$budOnly = L::budgetVsActual([8 => $zeroAct], [8 => 7000]);
check(near($budOnly['sections']['expense']['lines'][0]['actual'], 0) && $budOnly['sections']['expense']['lines'][0]['favourable'], 'budgeted but unspent shows as favourable');


echo "\n=== Budget helpers ===\n";
use App\Services\BudgetService as B;
$m = B::fyMonths('2026-07-01');
check(count($m) === 12 && $m[0] === '2026-07-01' && $m[11] === '2027-06-01', 'a July–June year runs from 1 Jul to 1 Jun');
check(B::fyMonths('2026-03-15')[0] === '2026-03-01', 'a mid-month start is snapped to the month');
check(B::monthsInRange('2026-01-15', '2026-03-10') === ['2026-01-01', '2026-02-01', '2026-03-01'], 'a part month counts as the whole month');
check(B::monthsInRange('2026-05-01', '2026-05-31') === ['2026-05-01'], 'one month');
check(B::monthsInRange('2026-06-01', '2026-05-01') === [], 'an inverted range is empty');
$sp = B::spread(1000.00);
check(count($sp) === 12 && abs(array_sum($sp) - 1000.00) < 0.0001, 'a yearly 1,000.00 spreads to exactly 1,000.00');
check($sp[0] === 83.33 && $sp[11] === 83.37, 'whole cents each month, rounding in the last month');
check(abs(array_sum(B::spread(0.07)) - 0.07) < 0.0001 && abs(array_sum(B::spread(12345.67)) - 12345.67) < 0.0001, 'awkward amounts still add up exactly');
check(B::cleanAmount('') === 0.0 && B::cleanAmount('1,250.5') === 1250.5 && B::cleanAmount(' 7 ') === 7.0, 'blank is zero, commas and spaces are tolerated');
check(B::cleanAmount('-5') === null && B::cleanAmount('abc') === null && B::cleanAmount('1e99') === null, 'negatives, text and absurd values are rejected');

echo "\nAll {$passed} statement checks passed.\n";
