<?php
// Recurring contract invoices: period maths, catch-up limits, invoice fields and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/InvoiceService.php";
require_once "$root/src/Services/ContractBillingService.php";
use App\Services\ContractBillingService as B;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('monthly period 3', B::periodStart('2026-01-15', 'monthly', 3) === '2026-04-15');
check('quarterly period 1', B::periodStart('2026-01-15', 'quarterly', 1) === '2026-04-15');
check('annual period 2', B::periodStart('2024-03-01', 'annual', 2) === '2026-03-01');
check('31 Jan + 1 month clamps to 28 Feb', B::periodStart('2026-01-31', 'monthly', 1) === '2026-02-28');
check('then returns to 31 Mar (anchored on the start)', B::periodStart('2026-01-31', 'monthly', 2) === '2026-03-31');
check('leap day annual clamps', B::periodStart('2024-02-29', 'annual', 1) === '2025-02-28');
check('period 0 is the start date', B::periodStart('2026-05-20', 'monthly', 0) === '2026-05-20');

// due periods
check('first run bills the start period', B::duePeriods('2026-11-01', 'monthly', null, null, '2026-11-08') === ['2026-11-01']);
check('not due before the start', B::duePeriods('2026-12-01', 'monthly', null, null, '2026-11-08') === []);
check('nothing new when up to date', B::duePeriods('2026-01-01', 'monthly', null, '2026-11-01', '2026-11-08') === []);
check('catches up missed months in order', B::duePeriods('2026-01-01', 'monthly', null, '2026-08-01', '2026-11-08') === ['2026-09-01', '2026-10-01', '2026-11-01']);
check('stops at the end date', B::duePeriods('2026-01-01', 'monthly', '2026-02-15', null, '2026-11-08') === ['2026-01-01', '2026-02-01']);
check('catch-up is capped', count(B::duePeriods('2020-01-01', 'monthly', null, null, '2026-11-08')) === B::MAX_CATCH_UP);
check('cap continues from where it stopped', B::duePeriods('2020-01-01', 'monthly', null, '2020-12-01', '2026-11-08')[0] === '2021-01-01');
check('quarterly skips between periods', B::duePeriods('2026-01-01', 'quarterly', null, '2026-01-01', '2026-03-31') === []);

// label
check('label covers the period', B::periodLabel('2026-01-01', 'monthly') === '01 Jan 2026 to 31 Jan 2026');
check('quarterly label', B::periodLabel('2026-01-01', 'quarterly') === '01 Jan 2026 to 31 Mar 2026');

// invoice fields
$c = ['party_id' => 4, 'title' => 'Support', 'contract_no' => 'CTR-1', 'billing_frequency' => 'monthly', 'amount' => 100, 'tax_rate' => 15, 'tax_type' => 'exclusive'];
$p = B::invoicePost($c, '2026-11-01');
check('exclusive tax adds on top', abs($p['subtotal'] - 100) < 0.01 && abs($p['tax_amount'] - 15) < 0.01 && abs($p['total'] - 115) < 0.01);
check('invoice is dated the period start and auto-numbered', $p['invoice_date'] === '2026-11-01' && $p['invoice_no'] === '');
check('line names the contract and period', strpos($p['item_desc'][0], 'CTR-1') !== false && strpos($p['item_desc'][0], '01 Nov 2026') !== false);
$p = B::invoicePost(['tax_type' => 'inclusive'] + $c, '2026-11-01');
check('inclusive tax keeps the total', abs($p['total'] - 100) < 0.01 && abs($p['tax_amount'] - 13.04) < 0.02);
$p = B::invoicePost(['tax_rate' => 0] + $c, '2026-11-01');
check('zero tax rate means no tax', $p['tax_type'] === 'none' && abs($p['total'] - 100) < 0.01 && $p['tax_amount'] == 0);
// the invoice service must accept these fields as consistent
check('amounts agree with InvoiceService totals', abs(\App\Services\InvoiceService::computeTotals([['qty' => 1, 'price' => 100, 'discount_pct' => 0, 'tax_rate' => 15]], 'exclusive', 0.0)['total'] - 115) < 0.01);

// wiring
$master = file_get_contents("$root/classes/Master.php");
$perm = file_get_contents("$root/src/Support/Permissions.php");
$svc = file_get_contents("$root/src/Services/ContractBillingService.php");
$cron = file_get_contents("$root/cron/contract_invoices.php");
check('Master action and case exist', strpos($master, 'function generate_contract_invoices(') !== false && strpos($master, "case 'generate_contract_invoices':") !== false);
check('action is permissioned', strpos($perm, "'generate_contract_invoices'") !== false);
check('billing is locked per contract', strpos($svc, 'GET_LOCK') !== false);
check('cron is CLI only and loops tenants', strpos($cron, "PHP_SAPI !== 'cli'") !== false && strpos($cron, 'FROM tenants') !== false);
$sql = file_get_contents("$root/database/migrations/027_contract_billing.sql");
check('migration has a unique contract period key', strpos($sql, 'UNIQUE KEY `uniq_contract_period` (`contract_id`, `billing_period_start`)') !== false);
check('migration has no semicolons inside comments', substr_count($sql, ';') === substr_count(preg_replace('/^\s*--.*$/m', '', $sql), ';'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
