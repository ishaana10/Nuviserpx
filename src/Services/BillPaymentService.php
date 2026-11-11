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

    /**
     * Dr Accounts Payable (the whole amount settled), Cr Bank (what is actually paid), and when tax is withheld
     * Cr Withholding Tax Payable (what is kept back for the tax authority).
     */
    public static function lines(int $apAcc, int $bankAcc, float $amount, int $whtAcc = 0, float $wht = 0.0): array
    {
        $amount = round($amount, 2);
        $wht = round($wht, 2);
        if ($wht <= 0 || $whtAcc <= 0) {
            return [[$apAcc, TaxService::DEBIT, $amount], [$bankAcc, TaxService::CREDIT, $amount]];
        }
        return [[$apAcc, TaxService::DEBIT, $amount], [$bankAcc, TaxService::CREDIT, round($amount - $wht, 2)], [$whtAcc, TaxService::CREDIT, $wht]];
    }

    /** Pure: tax to withhold from a gross payment at a percentage rate, to the cent. */
    public static function withheld(float $gross, float $ratePercent): float
    {
        if ($gross <= 0 || $ratePercent <= 0) {
            return 0.0;
        }
        return round($gross * min($ratePercent, 100.0) / 100, 2);
    }

    /** Pure: why a withheld amount can't be used, or null. It may be nothing, but never the whole payment or more. */
    public static function whtError(float $gross, float $wht): ?string
    {
        if ($wht < 0) {
            return 'Tax withheld cannot be negative.';
        }
        if ($wht > 0 && round($wht, 2) >= round($gross, 2)) {
            return 'Tax withheld must be less than the payment.';
        }
        return null;
    }

    /** Pure: a typed withholding rate. Blank is 0; otherwise 0 to 50 percent. Returns [rate, error]. */
    public static function cleanRate($raw): array
    {
        $t = trim(str_replace('%', '', (string)$raw));
        if ($t === '') {
            return [0.0, null];
        }
        if (!is_numeric($t) || (float)$t < 0 || (float)$t > 50) {
            return [0.0, 'Withholding tax rate must be between 0 and 50 percent.'];
        }
        return [round((float)$t, 2), null];
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

    /** The liability account withheld tax is held in, created the first time it is needed. */
    private function whtAccount(): int
    {
        $t = $this->tenantId();
        $find = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND (name = 'Withholding Tax Payable' OR name LIKE '%withholding%') ORDER BY (name = 'Withholding Tax Payable') DESC, id LIMIT 1");
        $find->bind_param('i', $t);
        $find->execute();
        if ($row = $find->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount([
            'name' => 'Withholding Tax Payable', 'type' => 'Current Liability',
            'description' => 'Tax kept back from supplier payments, owed to the tax authority', 'status' => 1,
        ]), true);
        if (($res['status'] ?? '') !== 'success') {
            return 0;
        }
        $find->execute();
        return (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
    }

    /**
     * Payments with tax withheld in a period, for paying it over and for supplier certificates.
     *
     * @return array<int,array<string,mixed>>
     */
    public function withholdingReport(string $from, string $to): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT p.id, p.payment_no, p.payment_date, p.amount, p.wht_amount, v.name vendor, v.tin
            FROM bill_payments p LEFT JOIN party_list v ON v.id = p.vendor_id
            WHERE p.tenant_id = ? AND p.status = 'posted' AND p.wht_amount > 0 AND p.payment_date BETWEEN ? AND ?
            ORDER BY v.name, p.payment_date, p.id");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss', $t, $from, $to);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** The default withholding rate set on a supplier. */
    public function vendorRate(int $vendorId): float
    {
        $t = $this->tenantId();
        $r = @$this->conn->query("SELECT wht_rate FROM party_list WHERE id = '{$vendorId}' AND tenant_id = '{$t}'");
        return ($r && ($row = $r->fetch_assoc())) ? round((float)$row['wht_rate'], 2) : 0.0;
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
        $wht = round((float)($post['wht_amount'] ?? 0), 2);
        if ($err = self::whtError($amount, $wht)) {
            return $this->fail($err);
        }

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
        $whtAcc = 0;
        if ($wht > 0) {
            $whtAcc = $this->whtAccount();
            if ($whtAcc === 0) {
                return $this->fail('The Withholding Tax Payable account could not be created. Add one under Chart of Accounts.');
            }
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
            $fx = new FxService($this->conn, $this->settings);
            $bankCur = $fx->bankCurrency($bankAcc);
            if ($bankCur !== null && $wht > 0) {
                throw new \RuntimeException('Tax cannot be withheld on a payment from a foreign-currency account. Pay from a base-currency account.');
            }
            if ($bankCur !== null) {
                // Paying base-currency bills from a foreign-currency account: take the units out at their carried value.
                $payRate = $fx->documentRate($bankCur, $date, null);
                if ($payRate === null) {
                    throw new \RuntimeException("No exchange rate for {$bankCur} on {$date}. Add one under Exchange rates.");
                }
                $pos = $fx->bankPosition($bankAcc, $date);
                $out = FxService::bankOutflow($amount, $payRate, $pos['fc_net'], $pos['base_net']);
                $fxAcc = $out['gain'] != 0.0 ? $fx->realisedAccount() : 0;
                if ($out['gain'] != 0.0 && $fxAcc === 0) {
                    throw new \RuntimeException('The ' . FxService::REALISED_ACCOUNT . ' account could not be created.');
                }
                $jl = FxService::bankOutflowLines($apAcc, $bankAcc, $fxAcc, $amount, $out, $bankCur);
            } else {
                $jl = self::lines($apAcc, $bankAcc, $amount, $whtAcc, $wht);
            }
            if ($journalId <= 0 || !(new TaxService($this->conn, $this->settings))->writeLines($journalId, $jl)) {
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
            if ($wht > 0 && !$this->conn->query("UPDATE bill_payments SET wht_amount = '{$wht}', wht_account_id = '{$whtAcc}' WHERE id = {$paymentId} AND tenant_id = {$t}")) {
                throw new \RuntimeException('The withheld tax could not be recorded.');
            }

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
        $this->settings->log_audit($pending ? 'Submitted supplier payment for approval' : 'Paid supplier bills', 'Purchases', "{$no}: " . number_format($amount, 2) . ' across ' . count($allocs) . ' bill(s)' . ($wht > 0 ? ', tax withheld ' . number_format($wht, 2) . ', paid ' . number_format($amount - $wht, 2) : '') . '.');
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
