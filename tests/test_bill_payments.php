<?php
/** Tests for supplier bill payments (no database). Run: php tests/test_bill_payments.php */
require_once(__DIR__ . '/../vendor/autoload.php');
use App\Services\BillPaymentService as B;
use App\Services\TaxService as T;
use App\Support\Permissions;

$passed = 0;
function check(bool $c, string $l): void { global $passed; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } $passed++; echo "  ✓ $l\n"; }

echo "=== Which bills can be paid ===\n";
foreach (['unpaid', 'partially_paid'] as $s) { check(B::payable($s), "$s bills can be paid"); }
foreach (['draft', 'pending_approval', 'paid', 'cancelled'] as $s) { check(!B::payable($s), "$s bills cannot be paid"); }

echo "\n=== Allocation rules ===\n";
$open = [1 => 500.00, 2 => 250.00];
check(B::allocationError(500, [1 => 500], $open) === null, 'paying a bill in full is fine');
check(B::allocationError(300, [1 => 200, 2 => 100], $open) === null, 'one payment can split across bills');
check(B::allocationError(0, [1 => 0], $open) !== null, 'a zero payment is refused');
check(B::allocationError(100, [], $open) !== null, 'a payment with no bills is refused');
check(B::allocationError(600, [1 => 600], $open) !== null, 'paying more than a bill owes is refused');
check(B::allocationError(100, [3 => 100], $open) !== null, 'a bill that is not open is refused');
check(B::allocationError(300, [1 => 200], $open) !== null, 'part of the payment left unallocated is refused');
check(B::allocationError(100, [1 => 150], $open) !== null, 'allocating more than the payment is refused');
check(B::allocationError(100.00, [1 => 33.33, 2 => 66.67], $open) === null, 'cents that add up exactly are accepted');

echo "\n=== Settling a bill ===\n";
$s = B::settle(1000, 400, 0, 'unpaid');
check($s['status'] === 'partially_paid' && $s['balance'] === 600.0, 'a part payment leaves the bill part-paid');
$s = B::settle(1000, 1000, 0, 'partially_paid');
check($s['status'] === 'paid' && $s['balance'] === 0.0, 'a full payment clears the bill');
$s = B::settle(1000, 700, 300, 'unpaid');
check($s['status'] === 'paid', 'a debit note plus payments can clear a bill');
$s = B::settle(1000, 0, 0, 'paid');
check($s['status'] === 'unpaid' && $s['balance'] === 1000.0, 'voiding the only payment makes the bill unpaid again');
$s = B::settle(1000, -5, 0, 'paid');
check($s['paid'] === 0.0, 'paid never goes below zero');

echo "\n=== Journal ===\n";
$l = B::lines(20, 10, 450.50);
check($l[0] === [20, T::DEBIT, 450.50] && $l[1] === [10, T::CREDIT, 450.50], 'debits Accounts Payable, credits the bank');

echo "\n=== Wiring ===\n";
check(Permissions::forAction('pay_vendor_bills') === 'purchases_edit', 'paying needs purchases edit');
check(Permissions::forAction('void_bill_payment') === 'purchases_delete', 'voiding needs purchases delete');
check(strpos(file_get_contents(__DIR__ . '/../classes/DBConnection.php'), 'check_bill_payment_migrations();') !== false, 'the migration is in the chain');
check(strpos(file_get_contents(__DIR__ . '/../src/Services/PurchaseService.php'), 'bill_payment_allocations') !== false, 'a bill with payments cannot be deleted');
echo "\n=== Approval ===\n";
use App\Services\ApprovalService as A;
check(A::DOC_BILL_PAYMENT === 'bill_payment', 'supplier payments have their own policy');
check(A::requiresApproval(true, 5000, 5000) && !A::requiresApproval(true, 5000, 4999.99), 'payments at or above the threshold need approval');
check(!A::requiresApproval(false, 0, 1000000), 'nothing needs approval while the policy is off');
check(A::approvalBlock(7, 7, true, false) !== null, 'the person who entered a payment cannot approve it');
check(A::approvalBlock(7, 8, true, false) === null, 'another approver can');
check(A::approvalBlock(7, 8, false, false) !== null, 'approving needs the permission');
foreach (['approve_bill_payment', 'reject_bill_payment'] as $a) { check(Permissions::forAction($a) === 'bill_payments_approve', "$a needs bill_payments_approve"); }
check(in_array('bill_payments_approve', Permissions::keys(), true), 'bill_payments_approve is a grantable permission');
$svc = file_get_contents(__DIR__ . '/../src/Services/BillPaymentService.php');
check(strpos($svc, "p.status = 'pending_approval'") !== false, 'money promised in a pending payment is held back from the bill');
check(strpos(file_get_contents(__DIR__ . '/../classes/DBConnection.php'), 'check_bill_payment_approval_migrations();') !== false, 'the approval migration is in the chain');
echo "\nAll {$passed} bill payment checks passed.\n";
