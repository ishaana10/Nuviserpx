<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Payments to suppliers against vendor bills.
 *
 * A payment is allocated, in full, to one or more of one vendor's open bills and posts Dr Accounts Payable / Cr Bank.
 * Bills held for approval, draft or cancelled cannot be paid; a payment cannot exceed what a bill still owes after
 * earlier payments and debit notes. Voiding a payment puts its journal out of the ledger and gives the amount back
 * to the bills. There are no vendor prepayments: the amount must be allocated to bills.
 */
class BillPaymentService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure rules ------------------------------ */

    /** Bill states a payment may be made against. */
    public static function payable(string $status): bool
    {
        return in_array($status, ['unpaid', 'partially_paid'], true);
    }

    /**
     * Why these allocations can't be saved, or null. $allocs: bill_id => amount to pay; $open: bill_id => still owed.
     * The allocations must add up exactly to the payment.
     */
    public static function allocationError(float $amount, array $allocs, array $open): ?string
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 'Enter the amount paid.';
        }
        $sum = 0.0;
        $count = 0;
        foreach ($allocs as $billId => $a) {
            $a = round((float)$a, 2);
            if ($a <= 0) {
                continue;
            }
            $count++;
            if (!array_key_exists($billId, $open)) {
                return 'One of the bills is not open for payment.';
            }
            if ($a > round((float)$open[$billId], 2) + 0.005) {
                return 'A payment is larger than what that bill still owes (' . number_format((float)$open[$billId], 2) . ').';
            }
            $sum += $a;
        }
        if ($count === 0) {
            return 'Choose at least one bill to pay.';
        }
        if (abs(round($sum, 2) - $amount) > 0.005) {
            return 'The bills add up to ' . number_format($sum, 2) . ' but the payment is ' . number_format($amount, 2) . '. Allocate the whole payment to bills.';
        }
        return null;
    }

    /** Paid-so-far and status for a bill after its payments change. */
    public static function settle(float $total, float $paid, float $credited, string $current): array
    {
        $paid = max(0.0, round($paid, 2));
        return [
            'paid' => $paid,
            'balance' => CreditNoteService::outstanding($total, $paid, $credited),
            'status' => CreditNoteService::settledStatus($total, $paid, $credited, $current),
        ];
    }

    /** Dr Accounts Payable, Cr Bank, as journal lines. */
    public static function lines(int $apAcc, int $bankAcc, float $amount): array
    {
        $amount = round($amount, 2);
        return [[$apAcc, TaxService::DEBIT, $amount], [$bankAcc, TaxService::CREDIT, $amount]];
    }

    /* -------------------------------- database ------------------------------- */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** Open bills for a vendor: still owed, oldest first. Bills in $include (already paid by an edited payment) are kept. */
    public function openBills(int $vendorId): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT b.id, b.bill_no, b.bill_date, b.due_date, b.total, b.paid_amount, b.credited_amount,
                (SELECT COALESCE(SUM(a.amount), 0) FROM bill_payment_allocations a INNER JOIN bill_payments p ON p.id = a.payment_id AND p.status = 'pending_approval' WHERE a.bill_id = b.id) reserved
            FROM vendor_bill_list b WHERE b.tenant_id = ? AND b.vendor_id = ? AND b.status IN ('unpaid','partially_paid') ORDER BY b.bill_date, b.id");
        $stmt->bind_param('ii', $t, $vendorId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            // Money already promised in a payment awaiting approval is not available to pay again.
            $r['owed'] = CreditNoteService::outstanding((float)$r['total'], (float)$r['paid_amount'] + (float)$r['reserved'], (float)$r['credited_amount']);
        }
        return $rows;
    }

    public function payments(int $limit = 200): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT p.*, v.name vendor FROM bill_payments p LEFT JOIN party_list v ON v.id = p.vendor_id
            WHERE p.tenant_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $t, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function allocationsFor(int $paymentId): array
    {
        $stmt = $this->conn->prepare("SELECT a.bill_id, a.amount, b.bill_no FROM bill_payment_allocations a LEFT JOIN vendor_bill_list b ON b.id = a.bill_id WHERE a.payment_id = ?");
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function find(array $keywords, int $type): int
    {
        $t = $this->tenantId();
        foreach ($keywords as $k) {
            $like = '%' . $k . '%';
            $stmt = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name LIKE ? ORDER BY id LIMIT 1");
            $stmt->bind_param('is', $t, $like);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                return (int)$row['id'];
            }
        }
        return 0;
    }

    private function nextNumber(string $date): string
    {
        $t = $this->tenantId();
        $ym = date('Ym', strtotime($date));
        $prefix = "BPAY-{$ym}-";
        $n = SequenceService::next($this->conn, $t, "billpay-{$ym}", function () use ($t, $prefix): int {
            $like = $prefix . '%';
            $s = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(payment_no, ?) AS UNSIGNED)) n FROM bill_payments WHERE tenant_id = ? AND payment_no LIKE ?");
            $from = strlen($prefix) + 1;
            $s->bind_param('iis', $from, $t, $like);
            $s->execute();
            return (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
        });
        return SequenceService::format($prefix, $n);
    }

    /** Pay one vendor's bills. Post: vendor_id, payment_date, amount, bank_account_id, method, reference, notes, alloc[bill_id]. */
    public function pay(array $post): string
    {
        $t = $this->tenantId();
        $userId = (int)$this->settings->userdata('id');
        $vendorId = (int)($post['vendor_id'] ?? 0);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['payment_date'] ?? '')) ? (string)$post['payment_date'] : date('Y-m-d');
        $amount = round((float)($post['amount'] ?? 0), 2);
        $bankAcc = (int)($post['bank_account_id'] ?? 0);
        $method = mb_substr(trim((string)($post['method'] ?? 'bank_transfer')), 0, 50) ?: 'bank_transfer';
        $reference = mb_substr(trim((string)($post['reference'] ?? '')), 0, 100);
        $notes = mb_substr(trim((string)($post['notes'] ?? '')), 0, 500);

        $v = $this->conn->prepare("SELECT id FROM party_list WHERE id = ? AND tenant_id = ?");
        $v->bind_param('ii', $vendorId, $t);
        $v->execute();
        if (!$v->get_result()->fetch_assoc()) {
            return $this->fail('Choose the vendor being paid.');
        }
        if ($date > date('Y-m-d', strtotime('+1 day'))) {
            return $this->fail('A payment cannot be dated in the future.');
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, $date)) {
            return $this->fail($msg);
        }
        $apAcc = $this->find(['payable', 'liabilit'], 3);
        if ($bankAcc <= 0) {
            $bankAcc = $this->find(['bank', 'cash'], 1);
        }
        if ($apAcc === 0 || $bankAcc === 0) {
            return $this->fail('Set up an Accounts Payable account and a bank or cash account first.');
        }
        $allocs = [];
        foreach ((array)($post['alloc'] ?? []) as $billId => $a) {
            if ((float)$a > 0) {
                $allocs[(int)$billId] = round((float)$a, 2);
            }
        }

        $this->conn->begin_transaction();
        try {
            // Lock the bills being paid, then check against what they truly owe now.
            $open = [];
            $bills = [];
            if ($allocs) {
                $ids = implode(',', array_map('intval', array_keys($allocs)));
                $q = $this->conn->query("SELECT id, bill_no, total, paid_amount, credited_amount, status FROM vendor_bill_list
                    WHERE tenant_id = {$t} AND vendor_id = {$vendorId} AND id IN ({$ids}) FOR UPDATE");
                while ($q && ($b = $q->fetch_assoc())) {
                    if (self::payable((string)$b['status'])) {
                        $rs = $this->conn->query("SELECT COALESCE(SUM(a.amount), 0) r FROM bill_payment_allocations a INNER JOIN bill_payments p ON p.id = a.payment_id AND p.status = 'pending_approval' WHERE a.bill_id = " . (int)$b['id'])->fetch_assoc();
                        $open[(int)$b['id']] = CreditNoteService::outstanding((float)$b['total'], (float)$b['paid_amount'] + (float)$rs['r'], (float)$b['credited_amount']);
                        $bills[(int)$b['id']] = $b;
                    }
                }
            }
            if ($err = self::allocationError($amount, $allocs, $open)) {
                throw new \RuntimeException($err);
            }

            $policy = (new ApprovalService($this->conn, $this->settings))->policy(ApprovalService::DOC_BILL_PAYMENT);
            $pending = ApprovalService::requiresApproval($policy['enabled'], $policy['threshold'], $amount);
            $pstatus = $pending ? ApprovalService::PENDING : 'posted';
            $no = $this->nextNumber($date);
            $desc = $this->conn->real_escape_string("Supplier payment {$no} - " . implode(', ', array_map(fn($b) => $b['bill_no'], $bills)));
            $code = SequenceService::journalCode($this->conn, $t);
            $this->conn->query("INSERT INTO journal_entries (tenant_id, code, journal_date, description, user_id) VALUES ('{$t}', '{$code}', '{$date}', '{$desc}', '{$userId}')");
            $journalId = (int)$this->conn->insert_id;
            if ($journalId <= 0 || !(new TaxService($this->conn, $this->settings))->writeLines($journalId, self::lines($apAcc, $bankAcc, $amount))) {
                throw new \RuntimeException('The payment journal could not be posted.');
            }

            if ($pending) {
                $this->conn->query("UPDATE journal_entries SET status = 'pending_approval' WHERE id = {$journalId} AND tenant_id = {$t}");
            }
            $ins = $this->conn->prepare("INSERT INTO bill_payments (tenant_id, payment_no, vendor_id, payment_date, amount, method, bank_account_id, reference, notes, status, journal_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $amt = number_format($amount, 2, '.', '');
            $ins->bind_param('isisssisssii', $t, $no, $vendorId, $date, $amt, $method, $bankAcc, $reference, $notes, $pstatus, $journalId, $userId);
            $ins->execute();
            $paymentId = (int)$this->conn->insert_id;

            $al = $this->conn->prepare("INSERT INTO bill_payment_allocations (payment_id, bill_id, amount) VALUES (?, ?, ?)");
            foreach ($allocs as $billId => $a) {
                $aa = number_format($a, 2, '.', '');
                $al->bind_param('iis', $paymentId, $billId, $aa);
                $al->execute();
                $this->refreshBill($billId);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payment could not be saved.');
        }
        $this->settings->log_audit($pending ? 'Submitted supplier payment for approval' : 'Paid supplier bills', 'Purchases', "{$no}: " . number_format($amount, 2) . ' across ' . count($allocs) . ' bill(s).');
        $msg = $pending ? "Payment {$no} recorded and sent for approval. The bills stay unpaid until it is approved." : "Payment {$no} recorded.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $paymentId, 'pending' => $pending]);
    }

    /** Void a payment: its journal leaves the ledger and its bills owe the amount again. */
    public function void(int $id): string
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT id, payment_no, payment_date, status, journal_id FROM bill_payments WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        if (!$p) {
            return $this->fail('Payment not found.');
        }
        if (!in_array($p['status'], ['posted', 'pending_approval'], true)) {
            return $this->fail('That payment is already void.');
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, (string)$p['payment_date'])) {
            return $this->fail($msg);
        }
        $this->conn->begin_transaction();
        try {
            $bills = array_column($this->allocationsFor($id), 'bill_id');
            $up = $this->conn->prepare("UPDATE bill_payments SET status = 'void' WHERE id = ? AND tenant_id = ? AND status IN ('posted','pending_approval')");
            $up->bind_param('ii', $id, $t);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('That payment was already voided.');
            }
            if (!empty($p['journal_id'])) {
                $j = $this->conn->prepare("UPDATE journal_entries SET status = 'cancelled' WHERE id = ? AND tenant_id = ?");
                $jid = (int)$p['journal_id'];
                $j->bind_param('ii', $jid, $t);
                $j->execute();
            }
            foreach ($bills as $billId) {
                $this->refreshBill((int)$billId);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payment could not be voided.');
        }
        $this->settings->log_audit('Voided supplier payment', 'Purchases', "{$p['payment_no']} voided.");
        $this->settings->set_flashdata('success', "Payment {$p['payment_no']} voided.");
        return json_encode(['status' => 'success', 'msg' => "Payment {$p['payment_no']} voided."]);
    }

    /** Payments waiting for approval, oldest first. */
    public function pendingApproval(): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT p.id, p.payment_no, p.payment_date, p.amount, p.created_by, v.name vendor, CONCAT(u.firstname, ' ', u.lastname) author
            FROM bill_payments p LEFT JOIN party_list v ON v.id = p.vendor_id LEFT JOIN users u ON u.id = p.created_by
            WHERE p.tenant_id = ? AND p.status = 'pending_approval' ORDER BY p.payment_date, p.id");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function pendingPayment(int $id, int $t): ?array
    {
        $stmt = $this->conn->prepare("SELECT id, payment_no, payment_date, amount, created_by, journal_id FROM bill_payments WHERE id = ? AND tenant_id = ? AND status = 'pending_approval'");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** Approve a payment: it posts to the ledger and its bills are paid down. */
    public function approve(int $id): string
    {
        $t = $this->tenantId();
        $p = $this->pendingPayment($id, $t);
        if (!$p) {
            return $this->fail('That payment is not waiting for approval.');
        }
        $me = (int)$this->settings->userdata('id');
        $policy = (new ApprovalService($this->conn, $this->settings))->policy(ApprovalService::DOC_BILL_PAYMENT);
        $block = ApprovalService::approvalBlock((int)$p['created_by'], $me, $this->settings->can_access('bill_payments_approve'), $policy['allow_self']);
        if ($block) {
            return $this->fail(str_replace('journal', 'payment', $block));
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, (string)$p['payment_date'])) {
            return $this->fail($msg);
        }
        $this->conn->begin_transaction();
        try {
            $bills = $this->allocationsFor($id);
            foreach ($bills as $a) {
                $b = $this->conn->query("SELECT total, paid_amount, credited_amount, status FROM vendor_bill_list WHERE id = " . (int)$a['bill_id'] . " AND tenant_id = {$t} FOR UPDATE")->fetch_assoc();
                if (!$b || !self::payable((string)$b['status'])
                    || (float)$a['amount'] > CreditNoteService::outstanding((float)$b['total'], (float)$b['paid_amount'], (float)$b['credited_amount']) + 0.005) {
                    throw new \RuntimeException("Bill {$a['bill_no']} no longer owes this much, so the payment cannot be approved. Return it and enter a new one.");
                }
            }
            $up = $this->conn->prepare("UPDATE bill_payments SET status = 'posted', approved_by = ?, approved_at = NOW(), approval_note = NULL WHERE id = ? AND tenant_id = ? AND status = 'pending_approval'");
            $up->bind_param('iii', $me, $id, $t);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('That payment was already dealt with by someone else.');
            }
            if (!empty($p['journal_id'])) {
                $this->conn->query("UPDATE journal_entries SET status = 'posted' WHERE id = " . (int)$p['journal_id'] . " AND tenant_id = {$t}");
            }
            foreach ($bills as $a) {
                $this->refreshBill((int)$a['bill_id']);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payment could not be approved.');
        }
        $this->settings->log_audit('Approved supplier payment', 'Approvals', "{$p['payment_no']} for " . number_format((float)$p['amount'], 2) . '.');
        $this->settings->set_flashdata('success', "Payment {$p['payment_no']} approved.");
        return json_encode(['status' => 'success', 'msg' => "Payment {$p['payment_no']} approved."]);
    }

    /** Return a pending payment with a reason: it is voided, its journal never posts, and the bills are free again. */
    public function reject(int $id, string $note): string
    {
        $t = $this->tenantId();
        $p = $this->pendingPayment($id, $t);
        if (!$p) {
            return $this->fail('That payment is not waiting for approval.');
        }
        if (!$this->settings->can_access('bill_payments_approve')) {
            return $this->fail('You do not have permission to review payments.');
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            return $this->fail('Say why the payment is being returned.');
        }
        $this->conn->begin_transaction();
        try {
            $up = $this->conn->prepare("UPDATE bill_payments SET status = 'void', approval_note = ? WHERE id = ? AND tenant_id = ? AND status = 'pending_approval'");
            $up->bind_param('sii', $note, $id, $t);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('That payment was already dealt with by someone else.');
            }
            if (!empty($p['journal_id'])) {
                $this->conn->query("UPDATE journal_entries SET status = 'cancelled' WHERE id = " . (int)$p['journal_id'] . " AND tenant_id = {$t}");
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The payment could not be returned.');
        }
        $this->settings->log_audit('Returned supplier payment', 'Approvals', "{$p['payment_no']} returned: {$note}");
        $this->settings->set_flashdata('success', "Payment {$p['payment_no']} returned.");
        return json_encode(['status' => 'success', 'msg' => "Payment {$p['payment_no']} returned."]);
    }

    /** Recompute a bill's paid amount, balance and status from its live (non-void) payments. */
    private function refreshBill(int $billId): void
    {
        $r = $this->conn->query("SELECT COALESCE(SUM(a.amount), 0) paid FROM bill_payment_allocations a
            INNER JOIN bill_payments p ON p.id = a.payment_id AND p.status = 'posted' WHERE a.bill_id = {$billId}")->fetch_assoc();
        $b = $this->conn->query("SELECT total, credited_amount, status FROM vendor_bill_list WHERE id = {$billId}")->fetch_assoc();
        if (!$b) {
            return;
        }
        $s = self::settle((float)$b['total'], (float)$r['paid'], (float)$b['credited_amount'], (string)$b['status']);
        $this->conn->query("UPDATE vendor_bill_list SET paid_amount = '{$s['paid']}', balance = '{$s['balance']}', status = '{$s['status']}' WHERE id = {$billId}");
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
