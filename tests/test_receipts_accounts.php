<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PaymentService as P;
use App\Services\AccountDefaultsService as A;
use App\Services\SequenceService as S;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

// --- allocations
$room = [
    1 => ['party_id' => 7, 'status' => 'unpaid', 'invoice_no' => 'INV-1', 'available' => 100.0],
    2 => ['party_id' => 7, 'status' => 'partially_paid', 'invoice_no' => 'INV-2', 'available' => 40.0],
    3 => ['party_id' => 9, 'status' => 'unpaid', 'invoice_no' => 'INV-3', 'available' => 500.0],
    4 => ['party_id' => 7, 'status' => 'cancelled', 'invoice_no' => 'INV-4', 'available' => 80.0],
    5 => ['party_id' => 7, 'status' => 'draft', 'invoice_no' => 'INV-5', 'available' => 80.0],
];
check('exact allocation is fine', P::allocationProblems(140.0, [1 => 100.0, 2 => 40.0], $room, 7) === []);
check('part payment and advance are fine', P::allocationProblems(500.0, [1 => 60.0], $room, 7) === []);
check('over an invoice balance is refused', count(P::allocationProblems(500.0, [2 => 50.0], $room, 7)) === 1);
check('rounding noise is tolerated', P::allocationProblems(100.0, [1 => 100.004], $room, 7) === []);
check('more than received is refused', P::allocationProblems(120.0, [1 => 100.0, 2 => 40.0], $room, 7) === ['More has been allocated to invoices than was received.']);
check("another customer's invoice is refused", count(P::allocationProblems(500.0, [3 => 10.0], $room, 7)) === 1);
check('an invoice that is not this company\'s (not found) is refused', count(P::allocationProblems(500.0, [99 => 10.0], $room, 7)) === 1);
check('cancelled and draft invoices cannot be paid', count(P::allocationProblems(500.0, [4 => 10.0, 5 => 10.0], $room, 7)) === 2);
check('no allocation at all is fine (advance receipt)', P::allocationProblems(50.0, [], $room, 7) === []);

// --- default accounts
$acc = [
    1 => ['name' => 'Cash on Hand', 'type' => 'Bank'], 2 => ['name' => 'Accounts Receivable', 'type' => 'Current Asset'],
    3 => ['name' => 'Inventory', 'type' => 'Inventory'], 4 => ['name' => 'Sales Revenue', 'type' => 'Revenue'],
    5 => ['name' => 'Sales Discounts', 'type' => 'Expense'], 6 => ['name' => 'Bank Charges', 'type' => 'Expense'],
    7 => ['name' => 'Accounts Payable', 'type' => 'Current Liability'],
];
check('receivable is found by class and name', A::detect($acc, 'receivable') === 2);
check('revenue is an income account, not "Sales Discounts" expense', A::detect($acc, 'revenue') === 4);
check('cash is an asset, not "Bank Charges"', A::detect($acc, 'cash') === 1);
check('nothing is invented when nothing fits', A::detect([7 => $acc[7]], 'receivable') === 0 && A::detect([], 'revenue') === 0);
check('a generic "asset" account is no longer taken as receivable', A::detect([9 => ['name' => 'Other Current Assets', 'type' => 'Current Asset']], 'receivable') === 0);
check('valid choices pass', A::validate(['receivable' => 2, 'revenue' => 4, 'cash' => 1], $acc) === []);
check('wrong class is refused', count(A::validate(['receivable' => 4, 'revenue' => 4, 'cash' => 1], $acc)) >= 1);
check('missing choices are reported', count(A::validate([], $acc)) === 3);
check('the same account twice is refused', in_array('Each role needs a different account.', A::validate(['receivable' => 2, 'revenue' => 4, 'cash' => 2], $acc), true));
check('missing-account message says where to fix it', str_contains(A::missing('revenue'), 'Default Accounts'));

// --- numbering
check('document numbers are padded', S::formatDoc('INV-202611-', 7) === 'INV-202611-0007' && S::formatDoc('INV-202611-', 12345) === 'INV-202611-12345');

// --- wiring
$pay = file_get_contents(base_app . 'src/Services/PaymentService.php');
check('domestic receipt saves in one transaction', substr_count($pay, 'begin_transaction') >= 3 && str_contains($pay, "'The receipt could not be saved. Nothing was changed.'"));
check('receipt allocations are validated before anything is written', strpos($pay, 'self::allocationProblems(') < strpos($pay, 'INSERT INTO `payment_list`'));
check('no account is guessed by name or id in receipts', !str_contains($pay, "LIKE '%cash%'") && !str_contains($pay, "LIKE '%receivable%'") && !str_contains($pay, ": 1;"));
check('deleting a receipt reverses it instead of erasing the journal', !str_contains($pay, "DELETE FROM `journal_entries`") && !str_contains($pay, 'DELETE FROM `payment_list`') && str_contains($pay, "SET `status` = 'cancelled'"));
check('a cancelled receipt cannot be edited', str_contains($pay, 'A cancelled receipt cannot be changed'));
check('receipt numbers come from the sequence and must be unique', str_contains($pay, 'SequenceService::documentNo(') && !str_contains($pay, 'rand('));
$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('invoices use the default accounts and numbering', str_contains($inv, "resolve('receivable')") && str_contains($inv, "resolve('revenue')") && str_contains($inv, 'SequenceService::documentNo(') && !str_contains($inv, 'rand(') && !str_contains($inv, "LIKE '%receivable%'"));
$cn = file_get_contents(base_app . 'src/Services/CreditNoteService.php');
check('credit notes use the default receivable account', str_contains($cn, "resolve('receivable')"));
check('settings action is permissioned', str_contains(file_get_contents(base_app . 'src/Support/Permissions.php'), "'save_default_accounts' => ['settings_manage']"));
check('migration file exists', is_file(base_app . 'database/migrations/011_sales_accounts_numbering.sql'));

echo $fail ? "\n$fail FAILED\n" : "\nAll receipts and accounts tests passed\n";
exit($fail ? 1 : 0);
