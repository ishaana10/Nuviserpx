<?php
/**
 * Tests for credit and debit note rules and postings (no database).
 *
 * Run: php tests/test_credit_notes.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\CreditNoteService as C;
use App\Services\TaxService as T;
use App\Support\Permissions;

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
    $d = $c = 0.0;
    foreach ($lines as $l) { $d += $l['debit']; $c += $l['credit']; }
    return [round($d, 2), round($c, 2)];
}

echo "=== What is still owed ===\n";
check(C::outstanding(1150, 0, 0) === 1150.00, 'an untouched invoice owes its total');
check(C::outstanding(1150, 500, 150) === 500.00, 'payments and credit notes both reduce it');
check(C::outstanding(1000, 1000, 300) === 0.0, 'it never goes below zero');

echo "\n=== Status after credits ===\n";
check(C::settledStatus(1000, 0, 1000, 'unpaid') === 'paid', 'a fully credited invoice is settled');
check(C::settledStatus(1000, 400, 600, 'partially_paid') === 'paid', 'payments plus credits that reach the total settle it');
check(C::settledStatus(1000, 0, 250, 'unpaid') === 'partially_paid', 'a partial credit makes it partially settled');
check(C::settledStatus(1000, 0, 0, 'partially_paid') === 'unpaid', 'voiding the only credit puts it back to unpaid');
check(C::settledStatus(1000, 0, 1000, 'cancelled') === 'cancelled' && C::settledStatus(1000, 0, 0, 'draft') === 'draft', 'drafts and cancelled documents are left alone');

echo "\n=== Size limits ===\n";
check(C::amountError(100, 15, 1150) === null, 'a note within the balance is accepted');
check(C::amountError(1000, 150, 1150) === null, 'a note for exactly the balance is accepted');
check(C::amountError(1000.01, 150, 1150) !== null, 'a note over the balance is refused');
check(C::amountError(0, 0, 1150) !== null && C::amountError(-5, 0, 1150) !== null, 'a zero or negative amount is refused');
check(C::amountError(10, -1, 1150) !== null, 'negative tax is refused');

echo "\n=== Credit note posting (customer) ===\n";
// Original sale of 1,150 incl. 150 VAT: Dr AR 1,150  Cr Revenue 1,000  Cr VAT Output 150.
$L = C::noteLines('credit', 1150.00, 150.00, 4, 9, 1);   // revenue 4, VAT output 9, receivable 1
check(count($L) === 3 && sums($L) === [1150.00, 1150.00], 'a taxed credit note posts three balanced lines');
$by = array_column($L, null, 'account_id');
check($by[4]['debit'] === 1000.00 && $by[4]['credit'] === 0.0, 'revenue is debited for the net');
check($by[9]['debit'] === 150.00, 'VAT output is debited, reducing what is owed to the tax authority');
check($by[1]['credit'] === 1150.00 && $by[1]['debit'] === 0.0, 'receivables are credited for the gross');
$L = C::noteLines('credit', 200.00, 0.0, 4, 0, 1);
check(count($L) === 2 && sums($L) === [200.00, 200.00], 'a tax-free credit note posts two lines');

echo "\n=== Debit note posting (supplier) ===\n";
$L = C::noteLines('debit', 230.00, 30.00, 6, 8, 2);      // expense 6, VAT input 8, payable 2
$by = array_column($L, null, 'account_id');
check(count($L) === 3 && sums($L) === [230.00, 230.00], 'a taxed debit note posts three balanced lines');
check($by[2]['debit'] === 230.00, 'payables are debited for the gross');
check($by[6]['credit'] === 200.00, 'the expense is credited for the net');
check($by[8]['credit'] === 30.00, 'VAT input is credited, reducing what can be reclaimed');

echo "\n=== The note is the exact mirror of the original ===\n";
foreach (['credit' => 'sale', 'debit' => 'purchase'] as $kind => $orig) {
    $o = T::postingLines($orig, 1150.00, 150.00, 4, 9, 1);
    $n = C::noteLines($kind, 1150.00, 150.00, 4, 9, 1);
    $ok = true;
    foreach ($o as $i => [$acc, $side, $amt]) {
        $ok = $ok && $n[$i]['account_id'] === $acc
            && ($side === T::DEBIT ? ($n[$i]['credit'] === $amt && $n[$i]['debit'] === 0.0) : ($n[$i]['debit'] === $amt && $n[$i]['credit'] === 0.0));
    }
    check($ok, "a {$kind} note reverses the {$orig} posting line for line");
}
mt_srand(11);
$ok = true;
for ($i = 0; $i < 300; $i++) {
    $net = mt_rand(1, 500000) / 100;
    $tax = T::taxOn($net, mt_rand(0, 3) * 7.5);
    foreach (['credit', 'debit'] as $k) {
        $s = sums(C::noteLines($k, round($net + $tax, 2), $tax, 4, 9, 1));
        $ok = $ok && $s[0] === $s[1];
    }
}
check($ok, '300 random notes of both kinds balance to the cent');

echo "\n=== Wiring ===\n";
check(Permissions::forAction('issue_credit_note') === 'invoices_view' && Permissions::forAction('void_credit_note') === 'invoices_view', 'the actions are gated by the invoices module (debit notes also check purchases in the service)');
$svc = file_get_contents(__DIR__ . '/../src/Services/CreditNoteService.php');
check(str_contains($svc, 'purchases_create') && str_contains($svc, 'invoices_create'), 'raising a note needs create permission on the module it belongs to');
check(str_contains($svc, 'reverseJournal'), 'voiding reverses the original journal rather than deleting it');
$root = __DIR__ . '/../';
foreach (['src/Services/InvoiceService.php', 'src/Services/PaymentService.php', 'src/Services/PurchaseService.php', 'api/v1/invoices.php', 'api/v1/payments.php'] as $f) {
    check(str_contains(file_get_contents($root . $f), 'credited_amount'), "{$f} takes credit notes into account when working out the balance");
}

echo "\nAll {$passed} credit-note checks passed.\n";
