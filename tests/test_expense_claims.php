<?php
// Expense claims: ledger posting, reimbursement and workflow rules.
$root = dirname(__DIR__);
require_once "$root/src/Services/ExpenseClaimService.php";
use App\Services\ExpenseClaimService as S;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// ---- workflow
check('pending can be approved', S::canMove('pending', 'approved'));
check('pending can be rejected', S::canMove('pending', 'rejected'));
check('pending cannot jump to reimbursed', !S::canMove('pending', 'reimbursed'));
check('approved can be reimbursed', S::canMove('approved', 'reimbursed'));
check('approved can be voided', S::canMove('approved', 'voided'));
check('rejected is final', !S::canMove('rejected', 'approved'));
check('reimbursed can only go back to approved', S::canMove('reimbursed', 'approved') && !S::canMove('reimbursed', 'voided'));

// ---- journal lines balance
$a = S::approvalLines(10, 20, 123.456, 'x');
check('approval debits expense', $a[0]['account_id'] === 10 && $a[0]['debit'] == 123.46 && $a[0]['credit'] == 0);
check('approval credits payable', $a[1]['account_id'] === 20 && $a[1]['credit'] == 123.46);
check('approval balances', abs(array_sum(array_column($a, 'debit')) - array_sum(array_column($a, 'credit'))) < 0.001);
$r = S::reimbursementLines(20, 30, 250.5, 'x');
check('reimbursement debits payable, credits bank', $r[0]['account_id'] === 20 && $r[0]['debit'] == 250.5 && $r[1]['account_id'] === 30 && $r[1]['credit'] == 250.5);
foreach ([[0.0], [-5.0]] as [$bad]) {
    try { S::approvalLines(1, 2, $bad, 'x'); check("approval rejects $bad", false); } catch (\InvalidArgumentException $e) { check("approval rejects $bad", true); }
}
try { S::reimbursementLines(1, 2, 0, 'x'); check('reimbursement rejects zero', false); } catch (\InvalidArgumentException $e) { check('reimbursement rejects zero', true); }

// ---- validation
$ok = ['amount' => '45.50', 'claim_date' => '2026-11-01', 'category' => 'Travel', 'employee_id' => 3];
check('valid claim passes', S::validateClaim($ok, '2026-11-08') === []);
check('zero amount fails', S::validateClaim(['amount' => 0] + $ok, '2026-11-08') !== []);
check('huge amount fails', S::validateClaim(['amount' => 5000000] + $ok, '2026-11-08') !== []);
check('non-numeric amount fails', S::validateClaim(['amount' => 'abc'] + $ok, '2026-11-08') !== []);
check('future date fails', S::validateClaim(['claim_date' => '2026-12-01'] + $ok, '2026-11-08') !== []);
check('bad date fails', S::validateClaim(['claim_date' => 'soon'] + $ok, '2026-11-08') !== []);
check('missing category fails', S::validateClaim(['category' => ' '] + $ok, '2026-11-08') !== []);
check('missing employee fails', S::validateClaim(['employee_id' => 0] + $ok, '2026-11-08') !== []);

// ---- account choice
check('chosen account wins', S::pickExpenseAccount(5, 9) === 5);
check('category account used otherwise', S::pickExpenseAccount(0, 9) === 9);
check('none gives 0', S::pickExpenseAccount(0, null) === 0);

// ---- wiring (source checks)
$master = file_get_contents("$root/classes/Master.php");
$perm = file_get_contents("$root/src/Support/Permissions.php");
$jr = file_get_contents("$root/src/Services/JournalService.php");
$svc = file_get_contents("$root/src/Services/ExpenseClaimService.php");
foreach (['approve_expense_claim', 'reject_expense_claim', 'cancel_expense_claim', 'void_expense_claim', 'reimburse_expense_claims', 'reverse_expense_reimbursement', 'save_expense_category'] as $f) {
    check("Master has $f method and case", strpos($master, "function $f(") !== false && strpos($master, "case '$f':") !== false);
    check("Permissions maps $f", strpos($perm, "'$f'") !== false);
}
check('journal sources cover claims and payments', strpos($jr, "'expense_reports'") !== false && strpos($jr, "'expense_reimbursement'") !== false);
check('self-approval is blocked', stripos($svc, 'own claim') !== false || stripos($svc, 'your own') !== false);
check('reimbursement is serialised with a lock', strpos($svc, 'GET_LOCK') !== false);

// ---- migration hygiene: the runner splits on semicolons
$sql = file_get_contents("$root/database/migrations/026_expense_claims.sql");
$noComments = preg_replace('/^\s*--.*$/m', '', $sql);
check('migration has no semicolons inside comments', substr_count($sql, ';') === substr_count($noComments, ';'));
check('migration adds journal and payment columns', strpos($sql, 'payment_journal_id') !== false && strpos($sql, 'expense_categories') !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
