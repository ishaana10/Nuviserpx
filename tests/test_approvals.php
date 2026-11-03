<?php
/**
 * Tests for the journal approval rules (no database).
 *
 * Run: php tests/test_approvals.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\ApprovalService as A;
use App\Services\JournalService as J;
use App\Services\LedgerService;
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

echo "=== When approval is needed ===\n";
check(!A::requiresApproval(false, 0, 1000000), 'nothing needs approval while the policy is off');
check(A::requiresApproval(true, 0, 0.01), 'a threshold of 0 means every journal');
check(A::requiresApproval(true, 5000, 5000), 'a journal exactly at the threshold needs approval');
check(!A::requiresApproval(true, 5000, 4999.99), 'a journal below the threshold posts straight away');
check(A::requiresApproval(true, 5000, 12000), 'a journal above the threshold needs approval');
check(!A::requiresApproval(true, 5000, 4999.994), 'amounts are compared to the cent');

echo "\n=== Who may approve ===\n";
check(A::approvalBlock(7, 9, true, false) === null, 'a different person with the permission may approve');
check(A::approvalBlock(7, 7, true, false) !== null, 'the preparer may not approve their own journal');
check(A::approvalBlock(7, 7, true, true) === null, 'unless the company has allowed self-approval');
check(A::approvalBlock(7, 9, false, false) !== null, 'someone without the permission may not approve');
check(A::approvalBlock(7, 7, false, true) !== null, 'self-approval does not grant the permission itself');

echo "\n=== Threshold input ===\n";
check(A::cleanThreshold('') === 0.0 && A::cleanThreshold('1,250.50') === 1250.5 && A::cleanThreshold('0') === 0.0, 'blank, zero and formatted amounts accepted');
check(A::cleanThreshold('-1') === null && A::cleanThreshold('abc') === null && A::cleanThreshold('1e30') === null, 'negative, text and absurd amounts rejected');

echo "\n=== A pending journal is not posted ===\n";
check(J::statusKind(A::PENDING) === 'pending', 'pending_approval is its own status kind');
check(J::statusKind('posted') === 'posted' && J::statusKind('draft') === 'draft', 'other statuses are unchanged');
check(str_contains(LedgerService::POSTED_SQL, A::PENDING), 'reports exclude journals awaiting approval');
$msg = J::lockedByStatus(A::PENDING, 'deleted');
check($msg !== null && str_contains($msg, 'waiting for approval'), 'a pending journal cannot be edited or deleted, and says why');
check(J::lockedByStatus('draft') === null, 'a draft (including one returned by an approver) can still be edited');
check(J::statusKind(A::PENDING) !== 'posted', 'a pending journal cannot be reversed as if it were posted');

echo "\n=== Permissions ===\n";
foreach (['approve_journal', 'reject_journal'] as $a) {
    check(Permissions::forAction($a) === 'journals_approve', "{$a} requires the approve permission");
}
check(Permissions::forAction('save_approval_policy') === 'settings_manage', 'changing the policy requires settings_manage');
check(in_array('journals_approve', Permissions::keys(), true), 'journals_approve is a grantable permission');

echo "\nAll {$passed} approval checks passed.\n";
