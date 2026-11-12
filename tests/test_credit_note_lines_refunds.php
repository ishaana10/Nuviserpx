<?php
/** Tests for credit note lines and refunds (no database). Run: php tests/test_credit_note_lines_refunds.php */
require_once(__DIR__ . '/../vendor/autoload.php');
use App\Services\CreditNoteService as C;
use App\Support\Permissions;

$passed = 0;
function check(bool $c, string $l): void { global $passed; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } $passed++; echo "  ✓ $l\n"; }

echo "=== Line totals ===\n";
$r = C::lineTotals([['description' => 'Widgets', 'qty' => '3', 'price' => '100', 'tax_rate' => '15'], ['description' => 'Freight', 'qty' => '1', 'price' => '40.50', 'tax_rate' => '0']]);
check($r['net'] === 340.5 && $r['tax'] === 45.0, 'net and per-line tax add up (340.50 + 45.00)');
check(count($r['lines']) === 2, 'both lines kept');
$r = C::lineTotals([['description' => '', 'qty' => '', 'price' => '', 'tax_rate' => ''], ['description' => 'Item', 'qty' => '', 'price' => '10', 'tax_rate' => '']]);
check($r['net'] === 10.0 && $r['tax'] === 0.0, 'blank rows are skipped; a blank quantity means 1 and a blank rate 0');
check(isset(C::lineTotals([])['error']), 'a note with no lines is refused');
check(isset(C::lineTotals([['description' => 'X', 'qty' => '1', 'price' => '0', 'tax_rate' => '0']])['error']), 'a zero price is refused');
check(isset(C::lineTotals([['description' => 'X', 'qty' => '-1', 'price' => '5', 'tax_rate' => '0']])['error']), 'a negative quantity is refused');
check(isset(C::lineTotals([['description' => 'X', 'qty' => '1', 'price' => '5', 'tax_rate' => '150']])['error']), 'a tax rate over 100 is refused');
check(isset(C::lineTotals([['description' => '', 'qty' => '1', 'price' => '5', 'tax_rate' => '0']])['error']), 'a priced line with no description is refused');
$r = C::lineTotals([['description' => 'A', 'qty' => '1', 'price' => '0.33', 'tax_rate' => '15'], ['description' => 'B', 'qty' => '1', 'price' => '0.33', 'tax_rate' => '15']]);
check($r['tax'] === 0.1, 'tax is rounded per line (0.05 + 0.05)');

echo "\n=== Credit on a paid document ===\n";
check(C::creditable(1000, 0) === 1000.0, 'an unpaid invoice can be credited up to its total');
check(C::creditable(1000, 400) === 600.0, 'earlier credit reduces what can be credited');
check(C::creditable(1000, 1000) === 0.0, 'a fully credited invoice has nothing left');
check(C::amountError(1000, 0, C::creditable(1000, 0)) === null, 'crediting a paid invoice in full is allowed');
check(C::amountError(900, 150, 1000) !== null, 'a note above the creditable amount is refused');

echo "\n=== Refunds ===\n";
check(C::refundable(1000, 1000, 300, 0) === 300.0, 'paid 1000 on 1000, credited 300 → 300 to refund');
check(C::refundable(1000, 700, 300, 0) === 0.0, 'credit that only clears what was owed leaves nothing to refund');
check(C::refundable(1000, 1000, 300, 100) === 200.0, 'earlier refunds reduce it');
check(C::refundable(1000, 0, 1000, 0) === 0.0, 'a credit on an unpaid invoice refunds nothing');
check(C::refundError(0, 300) !== null, 'a zero refund is refused');
check(C::refundError(300.01, 300) !== null, 'refunding more than the overpayment is refused');
check(C::refundError(300, 300) === null, 'refunding exactly the overpayment is fine');
foreach (['credit' => [1, 2], 'debit' => [2, 1]] as $kind => $_) {
    $l = C::refundLines($kind, 20, 10, 150.0);
    $d = array_sum(array_column($l, 'debit')); $c = array_sum(array_column($l, 'credit'));
    check($d === 150.0 && $c === 150.0, "$kind refund balances");
}
$l = C::refundLines('credit', 20, 10, 150.0);
check($l[0]['account_id'] === 20 && $l[0]['debit'] === 150.0 && $l[1]['account_id'] === 10 && $l[1]['credit'] === 150.0, 'customer refund: Dr Receivables, Cr Bank');
$l = C::refundLines('debit', 20, 10, 150.0);
check($l[0]['account_id'] === 10 && $l[0]['debit'] === 150.0 && $l[1]['account_id'] === 20 && $l[1]['credit'] === 150.0, 'supplier refund: Dr Bank, Cr Payables');

echo "\n=== Voiding a note with refunds ===\n";
check(C::canVoidNote(1000, 1000, 300, 300, 0), 'a note with no refunds can be voided');
check(!C::canVoidNote(1000, 1000, 300, 300, 100), 'voiding would leave a refund unbacked, so it is refused');
check(C::canVoidNote(1000, 1000, 500, 200, 100), 'another note still backs the refund, so voiding is fine');

echo "\n=== Wiring ===\n";
foreach (['refund_credit_note', 'void_note_refund'] as $a) { check(Permissions::forAction($a) === 'invoices_view', "$a is gated"); }
check(strpos(file_get_contents(__DIR__ . '/../src/Database/LegacySchemaCatchup.php'), 'credit_note_refunds') !== false, 'refunds have a table');
echo "\nAll {$passed} checks passed.\n";
