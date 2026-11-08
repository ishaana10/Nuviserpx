<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Employee expense claims, from submission to the ledger.
 *
 *   pending  --approve-->  approved   Dr expense account (by category)   Cr Employee Reimbursements Payable
 *   approved --reimburse-> reimbursed Dr Employee Reimbursements Payable  Cr bank
 *   pending  --reject / cancel-->  rejected / cancelled   (nothing posted)
 *   approved --void-->  voided           (approval journal reversed)
 *   reimbursed --reverse payment--> approved   (payment journal reversed)
 *
 * Nobody approves their own claim. Each posting is checked against the period lock; an approval whose claim date
 * falls in closed books is posted on today's date instead and says so. Claims are in the company currency and carry
 * no tax split: record VAT-bearing purchases as vendor bills instead.
 */
class ExpenseClaimService
{
    public const MAX_AMOUNT = 1000000.00;
    public const PAYABLE = ['Employee Reimbursements Payable', 'Current Liability', 'Approved expense claims owed to employees until they are paid back'];
    public const DEFAULT_CATEGORIES = ['Travel & Lodging', 'Meals & Entertainment', 'Office Supplies', 'Vehicle & Fuel', 'Client Expenses', 'Communication', 'Training', 'Other'];

    private const FLOW = [
        'pending'    => ['approved', 'rejected', 'cancelled'],
        'approved'   => ['reimbursed', 'voided'],
        'reimbursed' => ['approved'],      // payment reversed
        'rejected'   => [], 'cancelled' => [], 'voided' => [],
    ];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure rules ------------------------------ */

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::FLOW[$from] ?? [], true);
    }

    /** Pure: journal lines [account_id, debit, credit, description] for an approval. */
    public static function approvalLines(int $expenseAccount, int $payableAccount, float $amount, string $label): array
    {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            throw new \InvalidArgumentException('The claim amount must be more than zero.');
        }
        return [
            ['account_id' => $expenseAccount, 'debit' => $amt, 'credit' => 0.0, 'description' => $label],
            ['account_id' => $payableAccount, 'debit' => 0.0, 'credit' => $amt, 'description' => $label],
        ];
    }

    /** Pure: journal lines for paying claims back from a bank account. */
    public static function reimbursementLines(int $payableAccount, int $bankAccount, float $total, string $label): array
    {
        $amt = round($total, 2);
        if ($amt <= 0) {
            throw new \InvalidArgumentException('Nothing to reimburse.');
        }
        return [
            ['account_id' => $payableAccount, 'debit' => $amt, 'credit' => 0.0, 'description' => $label],
            ['account_id' => $bankAccount, 'debit' => 0.0, 'credit' => $amt, 'description' => $label],
        ];
    }

    /** Pure: problems with a submitted claim. */
    public static function validateClaim(array $c, string $today): array
    {
        $err = [];
        $amount = is_numeric($c['amount'] ?? null) ? round((float)$c['amount'], 2) : 0.0;
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) $err[] = 'Enter an amount above zero and up to ' . number_format(self::MAX_AMOUNT, 2) . '.';
        $d = (string)($c['claim_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) $err[] = 'Enter the date of the expense.';
        elseif ($d > $today) $err[] = 'The expense date cannot be in the future.';
        if (trim((string)($c['category'] ?? '')) === '') $err[] = 'Choose a category.';
        if ((int)($c['employee_id'] ?? 0) <= 0) $err[] = 'Choose the employee.';
        return $err;
    }

    /** Pure: the expense account for an approval. A chosen account wins, then the category's, else 0. */
    public static function pickExpenseAccount(int $chosen, ?int $categoryAccount): int
    {
        return $chosen > 0 ? $chosen : (int)($categoryAccount ?? 0);
    }

    /* ------------------------------ categories ------------------------------ */

    /** Active categories for the company, adding the standard list the first time. */
    public function categories(bool $activeOnly = true): array
    {
        $t = $this->tenantId();
        $c = $this->conn->query("SELECT COUNT(*) n FROM expense_categories WHERE tenant_id = {$t}");
        if ($c && (int)$c->fetch_assoc()['n'] === 0) {
            $ins = $this->conn->prepare("INSERT IGNORE INTO expense_categories (tenant_id, name) VALUES (?, ?)");
            foreach (self::DEFAULT_CATEGORIES as $name) {
                $ins->bind_param('is', $t, $name);
                $ins->execute();
            }
        }
        $q = $this->conn->prepare("SELECT id, name, account_id, status FROM expense_categories WHERE tenant_id = ?" . ($activeOnly ? " AND status = 1" : "") . " ORDER BY name");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function saveCategory(array $p): string
    {
        $t = $this->tenantId();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        $acc = (int)($p['account_id'] ?? 0);
        $status = isset($p['status']) && (int)$p['status'] === 0 ? 0 : 1;
        if ($name === '' || mb_strlen($name) > 100) return $this->fail('Enter a category name (up to 100 characters).');
        if ($acc > 0 && !$this->isExpenseAccount($acc)) return $this->fail('Choose one of this company\'s expense accounts.');
        $accSql = $acc > 0 ? $acc : null;
        if ($id > 0) {
            $s = $this->conn->prepare("UPDATE expense_categories SET name = ?, account_id = ?, status = ? WHERE id = ? AND tenant_id = ?");
            $s->bind_param('siiii', $name, $accSql, $status, $id, $t);
        } else {
            $s = $this->conn->prepare("INSERT INTO expense_categories (tenant_id, name, account_id, status) VALUES (?,?,?,?)");
            $s->bind_param('isii', $t, $name, $accSql, $status);
        }
        if (!$s->execute()) return $this->fail('That category name is already used.');
        $this->settings->log_audit('Saved expense category', 'Expense Claims', $name);
        return json_encode(['status' => 'success', 'msg' => 'Category saved.']);
    }

    /* ------------------------------ lifecycle ------------------------------ */

    public function submit(array $p, ?string $attachmentPath = null): string
    {
        $t = $this->tenantId();
        $scope = $this->settings->employee_scope('expenses');
        if ($scope !== null) {
            if ($scope <= 0) return $this->fail('Your account is not linked to an employee profile. Ask your administrator.');
            $p['employee_id'] = $scope;           // staff can only claim for themselves
        }
        $errors = self::validateClaim($p, date('Y-m-d'));
        if ($errors) return $this->fail(implode(' ', $errors));
        $emp = (int)$p['employee_id'];
        $e = $this->conn->prepare("SELECT id FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $e->bind_param('ii', $emp, $t);
        $e->execute();
        if (!$e->get_result()->fetch_assoc()) return $this->fail('Employee not found.');
        $cat = trim((string)$p['category']);
        $known = array_column($this->categories(), 'name');
        if (!in_array($cat, $known, true)) return $this->fail('Choose one of the listed categories.');

        $claimNo = SequenceService::documentNo($this->conn, $t, 'expense_reports', 'claim_no', 'EXP-' . date('Ym') . '-', 'expense-' . date('Ym'));
        $date = (string)$p['claim_date'];
        $amount = number_format(round((float)$p['amount'], 2), 2, '.', '');
        $desc = mb_substr(trim((string)($p['description'] ?? '')), 0, 2000);
        $by = (int)$this->settings->userdata('id');
        $s = $this->conn->prepare("INSERT INTO expense_reports (tenant_id, claim_no, employee_id, claim_date, category, amount, description, attachment_path, status, submitted_by)
            VALUES (?,?,?,?,?,?,?,?, 'pending', ?)");
        $s->bind_param('isisssssi', $t, $claimNo, $emp, $date, $cat, $amount, $desc, $attachmentPath, $by);
        if (!$s->execute()) return $this->fail('Failed to submit the expense claim.');
        $this->settings->log_audit('Submitted Expense Claim', 'Expense Claims', "Claim #{$claimNo}, Amount: {$amount}");
        return json_encode(['status' => 'success', 'id' => (int)$this->conn->insert_id, 'msg' => 'Expense claim submitted successfully.']);
    }

    public function approve(int $id, int $chosenAccount = 0, string $note = ''): string
    {
        $t = $this->tenantId();
        $c = $this->claim($id);
        if (!$c) return $this->fail('Claim not found.');
        if (!self::canMove((string)$c['status'], 'approved') || $c['status'] !== 'pending') return $this->fail('Only a pending claim can be approved.');
        $my = (int)$this->settings->own_employee_id();
        if (!$this->settings->is_super_admin() && $my > 0 && $my === (int)$c['employee_id']) {
            return $this->fail('You cannot approve your own expense claim.');
        }
        $catAcc = null;
        $cq = $this->conn->prepare("SELECT account_id FROM expense_categories WHERE tenant_id = ? AND name = ?");
        $cq->bind_param('is', $t, $c['category']);
        $cq->execute();
        $catAcc = ($cq->get_result()->fetch_assoc()['account_id'] ?? null);
        $exp = self::pickExpenseAccount($chosenAccount, $catAcc !== null ? (int)$catAcc : null);
        if ($exp === 0) return $this->fail('Choose the expense account for this claim (or map the category to one).');
        if (!$this->isExpenseAccount($exp)) return $this->fail('Choose one of this company\'s expense accounts.');
        try {
            $payable = $this->payableAccount(true);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $date = (string)$c['claim_date'];
        $moved = '';
        if (PeriodLockService::guard($this->conn, $t, $date) !== null) {
            $date = date('Y-m-d');
            $moved = ' The books are closed on the claim date, so it was posted on ' . $date . '.';
        }
        $label = "Expense claim {$c['claim_no']}";
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, $label, self::approvalLines($exp, $payable, (float)$c['amount'], $label), 'posted'), true);
        if (($res['status'] ?? '') !== 'success') return $this->fail($res['msg'] ?? 'The claim could not be posted.');
        $jid = (int)$res['id'];
        $by = (int)$this->settings->userdata('id');
        $note = mb_substr(trim($note), 0, 500);
        $u = $this->conn->prepare("UPDATE expense_reports SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_note = ?, expense_account_id = ?, journal_id = ?
            WHERE id = ? AND tenant_id = ? AND status = 'pending'");
        $u->bind_param('isiiii', $by, $note, $exp, $jid, $id, $t);
        $u->execute();
        if ($u->affected_rows !== 1) {
            (new JournalService($this->conn, $this->settings))->reverseJournal($jid, $date, true);
            return $this->fail('The claim changed while it was being approved, so nothing was posted. Please try again.');
        }
        $this->settings->log_audit('Approved Expense Claim', 'Expense Claims', "Claim #{$c['claim_no']} ({$c['amount']}) posted as journal #{$jid}");
        return json_encode(['status' => 'success', 'msg' => 'Expense claim approved and posted.' . $moved]);
    }

    public function reject(int $id, string $reason): string
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') return $this->fail('Enter a reason so the employee knows why.');
        return $this->simpleMove($id, 'pending', 'rejected', $reason, 'Rejected Expense Claim');
    }

    /** The claimant withdraws a pending claim (managers may too). */
    public function cancel(int $id): string
    {
        $c = $this->claim($id);
        if (!$c) return $this->fail('Claim not found.');
        $scope = $this->settings->employee_scope('expenses');
        if ($scope !== null && (int)$c['employee_id'] !== (int)$scope) return $this->fail('You can only cancel your own claims.');
        return $this->simpleMove($id, 'pending', 'cancelled', '', 'Cancelled Expense Claim');
    }

    /** Undo an approval that has not been paid: the approval journal is reversed. */
    public function void(int $id, string $reason): string
    {
        $t = $this->tenantId();
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') return $this->fail('Enter a reason for voiding the claim.');
        $c = $this->claim($id);
        if (!$c || $c['status'] !== 'approved' || empty($c['journal_id'])) return $this->fail('Only an approved claim that has not been paid can be voided.');
        $date = max(date('Y-m-d'), (string)$c['claim_date']);
        $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$c['journal_id'], $date, true), true);
        if (($res['status'] ?? '') !== 'success') return $this->fail($res['msg'] ?? 'The claim could not be voided.');
        $u = $this->conn->prepare("UPDATE expense_reports SET status = 'voided', approval_note = ? WHERE id = ? AND tenant_id = ?");
        $u->bind_param('sii', $reason, $id, $t);
        $u->execute();
        $this->settings->log_audit('Voided Expense Claim', 'Expense Claims', "Claim #{$c['claim_no']}: {$reason}");
        return json_encode(['status' => 'success', 'msg' => 'Claim voided and its posting reversed.']);
    }

    /**
     * Pay approved claims back from a bank account in one journal.
     * @param int[] $ids
     */
    public function reimburse(array $ids, int $bank, string $date, string $reference = ''): string
    {
        $t = $this->tenantId();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) return $this->fail('Select at least one approved claim.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return $this->fail('Enter the payment date.');
        if ($date > date('Y-m-d')) return $this->fail('The payment date cannot be in the future.');
        $b = $this->conn->prepare("SELECT id FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank'");
        $b->bind_param('ii', $bank, $t);
        $b->execute();
        if (!$b->get_result()->fetch_assoc()) return $this->fail('Choose one of this company\'s bank accounts.');
        if ((new FxService($this->conn, $this->settings))->bankCurrency($bank) !== null) return $this->fail('Claims are in the company currency. Choose a bank account held in that currency.');

        $lock = "expense_pay_{$t}";
        $g = $this->conn->prepare("SELECT GET_LOCK(?, 5) ok");
        $g->bind_param('s', $lock);
        $g->execute();
        if ((int)($g->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) return $this->fail('Another reimbursement is being recorded. Please try again.');
        try {
            return $this->reimburseLocked($ids, $bank, $date, mb_substr(trim($reference), 0, 100), $t);
        } finally {
            $r = $this->conn->prepare("SELECT RELEASE_LOCK(?)");
            $r->bind_param('s', $lock);
            $r->execute();
            $r->get_result();
        }
    }

    private function reimburseLocked(array $ids, int $bank, string $date, string $ref, int $t): string
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->conn->prepare("SELECT id, amount, claim_no, claim_date FROM expense_reports WHERE tenant_id = ? AND status = 'approved' AND id IN ({$marks})");
        $q->bind_param('i' . str_repeat('i', count($ids)), $t, ...$ids);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($rows) !== count($ids)) return $this->fail('Only approved, unpaid claims can be reimbursed. Refresh the list and try again.');
        $total = round(array_sum(array_map(fn($r) => (float)$r['amount'], $rows)), 2);
        $latest = max(array_column($rows, 'claim_date'));
        if ($date < $latest) return $this->fail('The payment date cannot be before the claim date (' . $latest . ').');
        try {
            $payable = $this->payableAccount(false);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        $nos = implode(', ', array_column($rows, 'claim_no'));
        $label = 'Expense reimbursement ' . mb_substr($nos, 0, 150) . ($ref !== '' ? " ({$ref})" : '');
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, $label, self::reimbursementLines($payable, $bank, $total, 'Expense claims paid'), 'posted'), true);
        if (($res['status'] ?? '') !== 'success') return $this->fail($res['msg'] ?? 'The payment could not be posted.');
        $jid = (int)$res['id'];
        $by = (int)$this->settings->userdata('id');
        $in = implode(',', array_map('intval', array_column($rows, 'id')));
        $u = $this->conn->prepare("UPDATE expense_reports SET status = 'reimbursed', payment_date = ?, payment_account_id = ?, payment_journal_id = ?, payment_reference = ?, reimbursed_by = ?
            WHERE tenant_id = ? AND status = 'approved' AND id IN ({$in})");
        $u->bind_param('siisii', $date, $bank, $jid, $ref, $by, $t);
        $u->execute();
        if ($u->affected_rows !== count($rows)) {
            $this->conn->query("UPDATE expense_reports SET status = 'approved', payment_date = NULL, payment_account_id = NULL, payment_journal_id = NULL, payment_reference = NULL, reimbursed_by = NULL WHERE payment_journal_id = {$jid}");
            (new JournalService($this->conn, $this->settings))->reverseJournal($jid, $date, true);
            return $this->fail('A claim changed while the payment was being recorded, so it was undone. Please try again.');
        }
        $this->settings->log_audit('Reimbursed Expense Claims', 'Expense Claims', "{$total} for " . count($rows) . " claim(s) on {$date}: {$nos}");
        return json_encode(['status' => 'success', 'msg' => 'Reimbursed ' . number_format($total, 2) . ' across ' . count($rows) . ' claim(s).']);
    }

    /** Undo a reimbursement: the payment journal is reversed and every claim paid by it is owed again. */
    public function reversePayment(int $id): string
    {
        $t = $this->tenantId();
        $c = $this->claim($id);
        if (!$c || $c['status'] !== 'reimbursed' || empty($c['payment_journal_id'])) return $this->fail('Only a reimbursed claim can have its payment reversed.');
        $jid = (int)$c['payment_journal_id'];
        $date = max(date('Y-m-d'), (string)$c['payment_date']);
        $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal($jid, $date, true), true);
        if (($res['status'] ?? '') !== 'success') return $this->fail($res['msg'] ?? 'The payment could not be reversed.');
        $this->conn->query("UPDATE expense_reports SET status = 'approved', payment_date = NULL, payment_account_id = NULL, payment_journal_id = NULL, payment_reference = NULL, reimbursed_by = NULL
            WHERE tenant_id = {$t} AND payment_journal_id = {$jid} AND status = 'reimbursed'");
        $this->settings->log_audit('Reversed Expense Reimbursement', 'Expense Claims', "Payment journal #{$jid}");
        return json_encode(['status' => 'success', 'msg' => 'Payment reversed. The claims are owed again.']);
    }

    /* ------------------------------ helpers ------------------------------ */

    private function simpleMove(int $id, string $from, string $to, string $note, string $audit): string
    {
        $t = $this->tenantId();
        $c = $this->claim($id);
        if (!$c || $c['status'] !== $from || !self::canMove($from, $to)) return $this->fail("Only a {$from} claim can be {$to}.");
        $by = (int)$this->settings->userdata('id');
        $u = $this->conn->prepare("UPDATE expense_reports SET status = ?, approval_note = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND tenant_id = ? AND status = ?");
        $u->bind_param('ssiiis', $to, $note, $by, $id, $t, $from);
        $u->execute();
        if ($u->affected_rows !== 1) return $this->fail('The claim changed. Refresh and try again.');
        $this->settings->log_audit($audit, 'Expense Claims', "Claim #{$c['claim_no']}" . ($note !== '' ? ": {$note}" : ''));
        return json_encode(['status' => 'success', 'msg' => 'Claim ' . $to . '.']);
    }

    public function claim(int $id): ?array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT * FROM expense_reports WHERE id = ? AND tenant_id = ?");
        $q->bind_param('ii', $id, $t);
        $q->execute();
        return $q->get_result()->fetch_assoc() ?: null;
    }

    private function isExpenseAccount(int $id): bool
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT type FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0 AND status = 1");
        $q->bind_param('ii', $id, $t);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        return $row && LedgerService::classify($row['type'])['class'] === 'expense';
    }

    /** The reimbursements payable account; created on first approval when $create. */
    private function payableAccount(bool $create): int
    {
        $t = $this->tenantId();
        [$name, $type, $desc] = self::PAYABLE;
        $find = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $find->bind_param('is', $t, $name);
        $find->execute();
        if ($row = $find->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        if (!$create) {
            throw new \RuntimeException("The {$name} account does not exist yet. Approve a claim first.");
        }
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount(['name' => $name, 'type' => $type, 'description' => $desc, 'status' => 1]), true);
        $find->execute();
        $id = (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
        if (($res['status'] ?? '') !== 'success' || $id === 0) {
            throw new \RuntimeException("Could not create the {$name} account.");
        }
        return $id;
    }

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
