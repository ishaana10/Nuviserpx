<?php
/** Tests for bill approval rules and wiring (no database). Run: php tests/test_bill_approvals.php */
require_once(__DIR__ . '/../vendor/autoload.php');
use App\Services\ApprovalService as A;
use App\Support\Permissions;

$passed = 0;
function check(bool $c, string $l): void { global $passed; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } $passed++; echo "  ✓ $l\n"; }

check(A::DOC_BILL === 'vendor_bill', 'bills have their own policy type');
check(A::requiresApproval(true, 1000, 1000), 'a bill at the threshold needs approval');
check(!A::requiresApproval(true, 1000, 999.99), 'a bill below the threshold does not');
check(!A::requiresApproval(false, 0, 99999), 'nothing needs approval while the policy is off');
check(A::approvalBlock(5, 5, true, false) !== null, 'the maker cannot approve their own bill');
check(A::approvalBlock(5, 6, true, false) === null, 'another approver can');
check(A::approvalBlock(5, 5, true, true) === null, 'self-approval works when the policy allows it');
check(A::approvalBlock(5, 6, false, false) !== null, 'approving needs the permission');

foreach (['approve_bill', 'reject_bill'] as $a) { check(Permissions::forAction($a) === 'bills_approve', "$a needs bills_approve"); }
check(in_array('bills_approve', Permissions::keys(), true), 'bills_approve is a grantable permission');
foreach (['admin/reports/ap_aging.php', 'admin/reports/purchases_by_vendor.php', 'src/Services/TaxReturnService.php'] as $f) {
    $s = file_get_contents(__DIR__ . '/../' . $f);
    check(strpos($s, "'pending_approval'") !== false, "$f leaves pending bills out");
}
$ledger = \App\Services\LedgerService::POSTED_SQL;
check(strpos($ledger, 'pending_approval') !== false, 'pending journals stay out of the ledger');
echo "\n$passed checks passed\n";
