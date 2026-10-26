<?php
/**
 * Tests for App\Services\LedgerService — account classification, financial-year
 * maths, trial balance, worksheet, P&L, balance sheet, running ledger and
 * journal validation. Pure functions only; no database required.
 *
 * Run: php tests/test_ledger_service.php
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

echo "=== Classification ===\n";
$cases = [
    'Bank' => ['asset', 'bank'], 'Cash' => ['asset', 'bank'], 'Current Asset' => ['asset', 'current_asset'],
    'Inventory' => ['asset', 'current_asset'], 'Accounts Receivable' => ['asset', 'current_asset'],
    'Fixed Asset' => ['asset', 'fixed_asset'], 'Non-current Asset' => ['asset', 'non_current_asset'],
    'Current Liability' => ['liability', 'current_liability'], 'Non-current Liability' => ['liability', 'non_current_liability'],
    'Accounts Payable' => ['liability', 'current_liability'], 'Equity' => ['equity', 'equity'], 'Owner Drawings' => ['equity', 'equity'],
    'Revenue' => ['income', 'revenue'], 'Sales' => ['income', 'revenue'], 'Other Income' => ['income', 'other_income'],
    'Direct Costs' => ['expense', 'direct_costs'], 'Expense' => ['expense', 'expense'], 'Depreciation' => ['expense', 'expense'],
    '' => ['expense', 'expense'], 'Assets' => ['asset', 'current_asset'], 'Liabilities' => ['liability', 'current_liability'],
];
foreach ($cases as $type => [$class, $section]) {
    $c = L::classify($type);
    check($c['class'] === $class && $c['section'] === $section, "“{$type}” → {$class} / {$section}");
}
check(L::classify('Revenue')['normal'] === 'credit' && L::classify('Bank')['normal'] === 'debit', 'normal sides');
check(L::natural('asset', 100, 30) === 70.0 && L::natural('income', 100, 30) === -70.0, 'natural sign');

echo "\n=== Financial year ===\n";
check(L::fyStart('2026-10-26', 12) === '2026-01-01', 'calendar year');
check(L::fyStart('2026-10-26', 6) === '2026-07-01', 'July–June year, after July');
check(L::fyStart('2026-03-15', 6) === '2025-07-01', 'July–June year, before July');
check(L::fyStart('2026-07-01', 6) === '2026-07-01', 'first day of the year');
check(L::fyStart('2026-06-30', 6) === '2025-07-01', 'last day of the year');
check(L::fyStart('2026-02-10', 99) === '2026-01-01', 'bad month clamps to December year end');

/*
 * A small set of books, as balances() would return them for the window
 * 2026-01-01 .. 2026-10-26 (opening = everything before 1 Jan 2026).
 *
 * Opening (prior years): owner put 50,000 into the bank; last year sold 20,000
 * (cash) and spent 8,000 on rent  →  retained earnings 12,000.
 * This year: sales 30,000 on credit, received 18,000; cost of sales 9,000 paid;
 * rent 6,000 paid; bought equipment 10,000 with a loan.
 */
$raw = [
    ['id' => 1, 'code' => '090', 'name' => 'Bank', 'type' => 'Bank',                       'open_dr' => 50000 + 20000, 'open_cr' => 8000, 'dr' => 18000, 'cr' => 9000 + 6000],
    ['id' => 2, 'code' => '110', 'name' => 'Accounts Receivable', 'type' => 'Current Asset', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 30000, 'cr' => 18000],
    ['id' => 3, 'code' => '150', 'name' => 'Equipment', 'type' => 'Fixed Asset',           'open_dr' => 0, 'open_cr' => 0, 'dr' => 10000, 'cr' => 0],
    ['id' => 4, 'code' => '250', 'name' => 'Bank loan', 'type' => 'Non-current Liability', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 0, 'cr' => 10000],
    ['id' => 5, 'code' => '300', 'name' => 'Owner capital', 'type' => 'Equity',            'open_dr' => 0, 'open_cr' => 50000, 'dr' => 0, 'cr' => 0],
    ['id' => 6, 'code' => '400', 'name' => 'Sales', 'type' => 'Revenue',                   'open_dr' => 0, 'open_cr' => 20000, 'dr' => 0, 'cr' => 30000],
    ['id' => 7, 'code' => '500', 'name' => 'Cost of goods sold', 'type' => 'Direct Costs', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 9000, 'cr' => 0],
    ['id' => 8, 'code' => '610', 'name' => 'Rent', 'type' => 'Expense',                    'open_dr' => 8000, 'open_cr' => 0, 'dr' => 6000, 'cr' => 0],
    ['id' => 9, 'code' => '620', 'name' => 'Unused', 'type' => 'Expense',                  'open_dr' => 0, 'open_cr' => 0, 'dr' => 0, 'cr' => 0],
];

echo "\n=== Balance shaping ===\n";
$b = L::shapeBalances($raw);
check(!isset($b[9]) && count($b) === 8, 'accounts with no movement are hidden');
check(count(L::shapeBalances($raw, true)) === 9, '…unless empty accounts are requested');
check($b[6]['class'] === 'income' && $b[3]['section'] === 'fixed_asset', 'rows carry class and section');

echo "\n=== Trial balance ===\n";
$tb = L::trialBalance($b);
check($tb['balanced'] && near($tb['debit'], $tb['credit']), 'debits equal credits');
check(near($tb['debit'], 65000 + 12000 + 10000 + 9000 + 14000), 'total debits = bank + AR + equipment + COGS + rent (110,000)');
$byId = array_column($tb['lines'], null, 'id');
check(near($byId[6]['credit'], 50000) && near($byId[6]['debit'], 0), 'sales closes as a 50,000 credit');
check(near($byId[1]['debit'], 65000), 'bank closes at 65,000 debit');
check($tb['lines'][0]['class'] === 'asset' && end($tb['lines'])['class'] === 'expense', 'ordered assets → expenses');
$bad = $b;
$bad[1]['dr'] += 100;
$tbBad = L::trialBalance($bad);
check(!$tbBad['balanced'] && near($tbBad['difference'], 100), 'an unbalanced ledger is flagged with the difference');

$tbc = L::trialBalance($b, true);
$tbcById = [];
foreach ($tbc['lines'] as $l) { $tbcById[$l['id'] ?? 'RE'] = $l; }
check($tbc['balanced'], 'year-end-closed trial balance still balances');
check(near($tbcById[6]['credit'], 30000) && near($tbcById[8]['debit'], 6000), 'P&L accounts show only this year (sales 30,000, rent 6,000)');
check(near($tbcById['RE']['credit'], 12000) && $tbcById['RE']['class'] === 'equity', 'prior-year profit appears as retained earnings (12,000 Cr)');
$eq = array_values(array_filter($tbc['lines'], fn ($l) => $l['class'] === 'equity'));
check($eq[count($eq) - 1]['name'] === 'Retained earnings', 'retained earnings listed after real equity accounts');

echo "\n=== Worksheet ===\n";
$ws = L::worksheet($b);
$t = $ws['totals'];
check(near($t['dr'], $t['cr']), 'period debits equal period credits');
check(near($t['open_dr'], $t['open_cr']), 'opening balances balance');
check(near($t['close_dr'], $t['close_cr']), 'closing balances balance');
$wsById = array_column($ws['lines'], null, 'id');
check(near($wsById[1]['opening'], 62000) && near($wsById[1]['closing'], 65000), 'bank opening 62,000 → closing 65,000');

echo "\n=== Profit and loss ===\n";
$pl = L::incomeStatement($b);
check(near($pl['revenue'], 30000), 'only this period’s sales count (30,000)');
check(near($pl['sections']['direct_costs']['total'], 9000), 'cost of sales 9,000');
check(near($pl['gross_profit'], 21000) && near($pl['gross_margin'], 70.0), 'gross profit 21,000 (70%)');
check(near($pl['sections']['expense']['total'], 6000), 'operating expenses 6,000 (last year’s rent excluded)');
check(near($pl['net_profit'], 15000) && near($pl['net_margin'], 50.0), 'net profit 15,000 (50%)');
check(near($pl['total_expenses'], 15000), 'total expenses 15,000');
$plEmpty = L::incomeStatement([]);
check($plEmpty['net_profit'] == 0.0 && $plEmpty['gross_margin'] === null, 'empty period: zero profit, no margin');
$contra = L::shapeBalances([['id' => 1, 'code' => '', 'name' => 'Sales refunds', 'type' => 'Revenue', 'open_dr' => 0, 'open_cr' => 0, 'dr' => 500, 'cr' => 0]]);
check(near(L::incomeStatement($contra)['revenue'], -500), 'debits to an income account reduce revenue');

echo "\n=== Balance sheet ===\n";
$bs = L::balanceSheet($b);
check(near($bs['total_assets'], 65000 + 12000 + 10000), 'assets = bank + receivables + equipment (87,000)');
check(near($bs['total_liabilities'], 10000), 'liabilities = loan (10,000)');
check(near($bs['retained_earnings'], 12000), 'prior-year profit becomes retained earnings (12,000)');
check(near($bs['current_year_earnings'], 15000), 'this year’s profit is current year earnings (15,000)');
check(near($bs['total_equity'], 50000 + 12000 + 15000), 'equity = capital + retained + current year (77,000)');
check($bs['balanced'] && near($bs['net_assets'], $bs['total_equity']), 'net assets equal equity — the sheet balances');
check(near($bs['net_assets'], 77000), 'net assets 77,000');
$names = array_column($bs['sections']['equity']['lines'], 'name');
check($names === ['Owner capital', 'Retained earnings', 'Current year earnings'], 'equity lines in order');
check(empty($bs['sections']['bank']['lines'][0]['computed']), 'real accounts are not marked computed');

echo "\n=== Running ledger ===\n";
$gl = L::runningLedger(['id' => 1, 'code' => '090', 'name' => 'Bank', 'type' => 'Bank'], 62000, 0, [
    ['journal_id' => 10, 'code' => 'J1', 'journal_date' => '2026-02-01', 'description' => 'Receipt', 'line_description' => '', 'amount' => 18000, 'side' => 1],
    ['journal_id' => 11, 'code' => 'J2', 'journal_date' => '2026-03-01', 'description' => 'Header', 'line_description' => 'Stock purchase', 'amount' => 9000, 'side' => 2],
    ['journal_id' => 12, 'code' => 'J3', 'journal_date' => '2026-04-01', 'description' => 'Rent', 'line_description' => null, 'amount' => 6000, 'side' => 2],
]);
check(near($gl['opening'], 62000) && near($gl['closing'], 65000), 'opening 62,000 → closing 65,000');
check(near($gl['lines'][0]['balance'], 80000) && near($gl['lines'][1]['balance'], 71000), 'running balance after each line');
check(near($gl['debit'], 18000) && near($gl['credit'], 15000) && near($gl['net'], 3000), 'period totals and net movement');
check($gl['lines'][1]['description'] === 'Stock purchase' && $gl['lines'][0]['description'] === 'Receipt', 'line description preferred, header as fallback');
$glIncome = L::runningLedger(['type' => 'Revenue', 'name' => 'Sales'], 0, 20000, [
    ['journal_id' => 1, 'code' => 'J', 'journal_date' => '2026-05-01', 'description' => 'Sale', 'amount' => 1000, 'side' => 2],
]);
check(near($glIncome['opening'], 20000) && near($glIncome['closing'], 21000), 'income accounts run in credit-positive sign');

echo "\n=== Journal validation ===\n";
$ok = [['account_id' => 1, 'debit' => 100, 'credit' => 0], ['account_id' => 6, 'debit' => 0, 'credit' => 100], ['account_id' => 0, 'debit' => 0, 'credit' => 0]];
check(L::validateLines($ok, [1, 6]) === [], 'balanced journal passes; blank rows ignored');
$e = L::validateLines([['account_id' => 1, 'debit' => 100, 'credit' => 0], ['account_id' => 6, 'debit' => 0, 'credit' => 90]]);
check(count($e) === 1 && str_contains($e[0], 'out by 10.00'), 'unbalanced journal reports the difference');
$e = L::validateLines([['account_id' => 1, 'debit' => 50, 'credit' => 50], ['account_id' => 6, 'debit' => 0, 'credit' => 0]]);
check((bool)array_filter($e, fn ($m) => str_contains($m, 'not both')), 'debit and credit on one line rejected');
check((bool)array_filter($e, fn ($m) => str_contains($m, 'Line 2: enter an amount')), 'account without amount rejected');
$e = L::validateLines([['account_id' => 0, 'debit' => 10, 'credit' => 0], ['account_id' => 6, 'debit' => 0, 'credit' => 10]]);
check($e[0] === 'Line 1: choose an account.', 'amount without account rejected');
$e = L::validateLines([['account_id' => 99, 'debit' => 10, 'credit' => 0], ['account_id' => 6, 'debit' => 0, 'credit' => 10]], [1, 6]);
check(str_contains($e[0], 'not available for this company'), 'another company’s account rejected');
$e = L::validateLines([['account_id' => 1, 'debit' => -10, 'credit' => 0], ['account_id' => 6, 'debit' => -10, 'credit' => 0]]);
check((bool)array_filter($e, fn ($m) => str_contains($m, 'negative')), 'negative amounts rejected');
check(in_array('A journal needs at least two lines.', L::validateLines([['account_id' => 1, 'debit' => 0.004, 'credit' => 0]]), true), 'single line rejected');
check(L::validateLines([['account_id' => 1, 'debit' => 0.1 + 0.2, 'credit' => 0], ['account_id' => 6, 'debit' => 0, 'credit' => 0.3]]) === [], 'floating-point cents do not cause false imbalances');

$draft = [['account_id' => 1, 'debit' => 100, 'credit' => 0]];
check(L::validateLines($draft, [1], false) === [], 'drafts may be unbalanced or single-line');
check(L::validateLines([], [1], false) === ['Add at least one line before saving.'], 'empty draft rejected');
check((bool)L::validateLines([['account_id' => 1, 'debit' => 5, 'credit' => 5]], [1], false), 'drafts still reject debit+credit on one line');

echo "\n=== Cash summary ===\n";
$accs = [1 => ['code' => '090', 'name' => 'Bank', 'type' => 'Bank'], 2 => ['code' => '091', 'name' => 'Savings', 'type' => 'Bank'],
    6 => ['code' => '400', 'name' => 'Sales', 'type' => 'Revenue'], 8 => ['code' => '610', 'name' => 'Rent', 'type' => 'Expense'],
    3 => ['code' => '110', 'name' => 'Receivables', 'type' => 'Current Asset'], 7 => ['code' => '220', 'name' => 'VAT', 'type' => 'Current Liability']];
$cashLines = [
    ['journal_id' => 1, 'account_id' => 1, 'side' => 1, 'amount' => 1150], ['journal_id' => 1, 'account_id' => 6, 'side' => 2, 'amount' => 1000], ['journal_id' => 1, 'account_id' => 7, 'side' => 2, 'amount' => 150],
    ['journal_id' => 2, 'account_id' => 8, 'side' => 1, 'amount' => 600], ['journal_id' => 2, 'account_id' => 1, 'side' => 2, 'amount' => 600],
    ['journal_id' => 3, 'account_id' => 2, 'side' => 1, 'amount' => 5000], ['journal_id' => 3, 'account_id' => 1, 'side' => 2, 'amount' => 5000],
    ['journal_id' => 4, 'account_id' => 1, 'side' => 1, 'amount' => 300], ['journal_id' => 4, 'account_id' => 3, 'side' => 2, 'amount' => 300],
    ['journal_id' => 5, 'account_id' => 8, 'side' => 2, 'amount' => 50], ['journal_id' => 5, 'account_id' => 1, 'side' => 1, 'amount' => 50],
];
$cs = L::cashMovements($cashLines, [1, 2], $accs);
$inBy = array_column($cs['in'], 'amount', 'name');
$outBy = array_column($cs['out'], 'amount', 'name');
check(near($inBy['Sales'], 1000) && near($inBy['VAT'], 150) && near($inBy['Receivables'], 300), 'cash in split by contra account (sales, VAT, receivables)');
check(near($outBy['Rent'], 600), 'rent paid is cash out');
check(near($inBy['Rent'], 50), 'a rent refund shows as cash in, not negative cash out');
check(near($cs['transfers'], 5000) && !isset($inBy['Savings']) && !isset($outBy['Savings']), 'bank-to-bank transfer kept out of in/out');
check(near($cs['total_in'], 1500) && near($cs['total_out'], 600) && near($cs['net'], 900), 'totals and net movement');

echo "\n=== VAT on journals ===\n";
check(L::taxRateFromLabel('VAT on Income (15%)') === 15.0 && L::taxRateFromLabel('No Tax (0%)') === 0.0 && L::taxRateFromLabel('Exempt') === 0.0, 'rate parsed from label');
$vat = L::vatFromJournalLines([
    ['journal_id' => 1, 'code' => 'J1', 'journal_date' => '2026-10-01', 'description' => 'Office supplies', 'tax_mode' => 'Tax Exclusive', 'amount' => 200, 'tax_rate' => 'VAT on Expenses (15%)', 'side' => 1],
    ['journal_id' => 2, 'code' => 'J2', 'journal_date' => '2026-10-02', 'description' => 'Fuel', 'tax_mode' => 'Tax Inclusive', 'amount' => 115, 'tax_rate' => 'VAT on Expenses (15%)', 'side' => 1],
    ['journal_id' => 3, 'code' => 'J3', 'journal_date' => '2026-10-03', 'description' => 'Cash sale', 'tax_mode' => 'No Tax', 'amount' => 1000, 'tax_rate' => 'VAT on Income (15%)', 'side' => 2],
    ['journal_id' => 4, 'code' => 'J4', 'journal_date' => '2026-10-04', 'description' => 'Supplier refund', 'tax_mode' => 'Tax Exclusive', 'amount' => 40, 'tax_rate' => 'VAT on Expenses (15%)', 'side' => 2],
    ['journal_id' => 5, 'code' => 'J5', 'journal_date' => '2026-10-05', 'description' => 'Bank', 'tax_mode' => 'No Tax', 'amount' => 999, 'tax_rate' => 'No Tax (0%)', 'side' => 1],
]);
check(near($vat['input_tax'], 30 + 15 - 6) && count($vat['input']) === 3, 'input VAT: 15% on net, 15/115 on inclusive, refunds reverse (39.00)');
check(near($vat['input_net'], 200 + 100 - 40), 'input net amounts');
check(near($vat['output_tax'], 150) && near($vat['output_net'], 1000), 'output VAT on a tagged cash sale');

echo "\n=== Aged receivables ===\n";
check(L::agingBucket('2026-10-26', '2026-10-01', '2026-10-26')[0] === 'current', 'due today is current');
check(L::agingBucket('2026-10-25', '2026-10-01', '2026-10-26') === ['d30', 1], 'one day overdue');
check(L::agingBucket('2026-07-01', '2026-06-01', '2026-10-26')[0] === 'older', 'over 90 days');
check(L::agingBucket(null, '2026-09-20', '2026-10-26') === ['d60', 36], 'no due date: aged from invoice date');
$ar = L::agedReceivables([
    ['party_id' => 1, 'party_name' => 'A', 'id' => 1, 'invoice_no' => 'I1', 'invoice_date' => '2026-10-01', 'due_date' => '2026-11-01', 'total' => 100, 'balance' => 100],
    ['party_id' => 1, 'party_name' => 'A', 'id' => 2, 'invoice_no' => 'I2', 'invoice_date' => '2026-06-01', 'due_date' => '2026-07-01', 'total' => 300, 'balance' => 50],
    ['party_id' => 2, 'party_name' => 'B', 'id' => 3, 'invoice_no' => 'I3', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'total' => 500, 'balance' => 500],
    ['party_id' => 2, 'party_name' => 'B', 'id' => 4, 'invoice_no' => 'I4', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'total' => 80, 'balance' => 0],
], '2026-10-26');
check($ar['customers'][0]['name'] === 'B' && near($ar['customers'][0]['d30'], 500), 'customers ordered by amount owed; B is 25 days overdue');
check(near($ar['customers'][1]['current'], 100) && near($ar['customers'][1]['older'], 50) && $ar['customers'][1]['oldest'] === 117, 'A split across current and over-90');
check(near($ar['totals']['total'], 650) && count($ar['customers'][0]['invoices']) === 1, 'fully paid invoices ignored; totals add up');

echo "\nAll {$passed} ledger checks passed.\n";
