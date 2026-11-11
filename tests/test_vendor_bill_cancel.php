<?php
// Vendor bills: cancel reverses the ledger entry, delete never erases a posted journal.
$root = dirname(__DIR__);
require_once "$root/src/Services/PurchaseService.php";
use App\Services\PurchaseService as P;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('unpaid bill may be cancelled', P::cancelProblem(0, 0) === null);
check('bill with a payment cannot be cancelled', P::cancelProblem(10, 0) !== null);
check('bill with a debit note cannot be cancelled', P::cancelProblem(0, 5) !== null);
check('draft may be deleted', P::deleteProblem('draft', 0, 0) === null);
check('cancelled may be deleted', P::deleteProblem('cancelled', 0, 0) === null);
foreach (['unpaid', 'partially_paid', 'paid', 'pending_approval'] as $s) {
    check("$s bill cannot be deleted", P::deleteProblem($s, 0, 0) !== null);
}
check('a bill with payments cannot be deleted even if cancelled', P::deleteProblem('cancelled', 1, 0) !== null);
check('the message tells the user to cancel first', strpos(P::deleteProblem('unpaid', 0, 0), 'Cancel it first') !== false);

$src = file_get_contents("$root/src/Services/PurchaseService.php");
$del = substr($src, strpos($src, 'function deleteVendorBill('));
check('delete no longer removes a posted journal', strpos($del, "AND status <> 'posted'") !== false && strpos($del, "DELETE FROM `journal_entries` WHERE id = '{\$b['journal_id']}'\");") === false);
check('delete is refused through deleteProblem', strpos($del, 'self::deleteProblem(') !== false);
$save = substr($src, strpos($src, 'function saveVendorBill('), strpos($src, 'function cancelProblem(') - strpos($src, 'function saveVendorBill('));
check('cancelling reverses the journal from source', strpos($save, 'reverseJournal((int)$journal_id, date(\'Y-m-d\'), true)') !== false);
check('a cancelled bill is not re-posted', strpos($save, "\$status !== 'cancelled' && (\$pendingApproval") !== false && strpos($save, 'elseif ($journal_id)') !== false);
check('a cancelled bill cannot be edited', strpos($save, 'This bill is cancelled and cannot be changed') !== false);
check('cancelled bill owes nothing', strpos($save, "\$status === 'cancelled' ? 0.0") !== false);
check('previous status is not taken from the posted form', strpos($save, "\$oldStatus = '';") !== false && strpos($save, "\$credited_amount = 0.0;") !== false);
check('cancel action exists in service, Master and permissions', strpos($src, 'function cancelVendorBill(') !== false && strpos(file_get_contents("$root/classes/Master.php"), "case 'cancel_vendor_bill':") !== false && strpos(file_get_contents("$root/src/Support/Permissions.php"), "'cancel_vendor_bill'") !== false);
check('cancel is guarded against a concurrent change', strpos($src, "AND `status` = '{\$old}'") !== false);
check('bills list offers Cancel for posted bills and Delete only for draft or cancelled', strpos(file_get_contents("$root/admin/purchases/vendor_bills.php"), 'cancel_data') !== false);
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
