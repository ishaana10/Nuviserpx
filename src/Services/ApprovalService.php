<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Maker/checker control for manual journals.
 *
 * When a company turns approval on, a manual journal at or above the threshold is not posted by the person
 * who prepares it. It is saved as "pending_approval", which keeps it out of every report, until someone
 * holding the Approve journals permission approves it. That person cannot be the preparer unless the
 * company has explicitly allowed self-approval. Returning a journal sends it back to draft with a note.
 * Journals produced by invoices, bills, payments and payroll are not affected.
 */
class ApprovalService
{
    public const PENDING = 'pending_approval';
    public const DOC_JOURNAL = 'journal';
    public const DOC_BILL = 'vendor_bill';
    public const DOC_BILL_PAYMENT = 'bill_payment';

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure rules ------------------------------ */

    /** Whether a journal of this size has to be approved. A threshold of 0 means every journal. */
    public static function requiresApproval(bool $enabled, float $threshold, float $total): bool
    {
        if (!$enabled) {
            return false;
        }
        return round($total, 2) >= round(max(0.0, $threshold), 2);
    }

    /** Why this person may not approve the journal, or null when they may. */
    public static function approvalBlock(int $makerId, int $checkerId, bool $hasPermission, bool $allowSelf): ?string
    {
        if (!$hasPermission) {
            return 'You do not have permission to approve journals.';
        }
        if (!$allowSelf && $makerId === $checkerId) {
            return 'You prepared this journal, so someone else has to approve it.';
        }
        return null;
    }

    /** A threshold typed into the form: blank or 0 means “all”, negatives and junk are rejected (null). */
    public static function cleanThreshold(mixed $v): ?float
    {
        $s = trim(str_replace(',', '', (string)$v));
        if ($s === '') {
            return 0.0;
        }
        if (!is_numeric($s) || (float)$s < 0 || (float)$s > 999999999999.0) {
            return null;
        }
        return round((float)$s, 2);
    }

    /* --------------------------------- policy --------------------------------- */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** The company's approval policy for a document type (journal or vendor_bill): enabled, threshold, allow_self. */
    public function policy(string $doc = self::DOC_JOURNAL): array
    {
        $t = $this->tenantId();
        $stmt = @$this->conn->prepare("SELECT enabled, threshold, allow_self FROM approval_policies WHERE tenant_id = ? AND doc_type = ?");
        if ($stmt) {
            $stmt->bind_param('is', $t, $doc);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                return ['enabled' => (bool)$row['enabled'], 'threshold' => (float)$row['threshold'], 'allow_self' => (bool)$row['allow_self']];
            }
        }
        return ['enabled' => false, 'threshold' => 0.0, 'allow_self' => false];
    }

    public function savePolicy(array $post): string
    {
        $t = $this->tenantId();
        $enabled = !empty($post['enabled']) ? 1 : 0;
        $allowSelf = !empty($post['allow_self']) ? 1 : 0;
        $threshold = self::cleanThreshold($post['threshold'] ?? '');
        if ($threshold === null) {
            return $this->fail('The threshold must be an amount of zero or more.');
        }
        $doc = in_array($post['doc_type'] ?? '', [self::DOC_BILL, self::DOC_BILL_PAYMENT], true) ? $post['doc_type'] : self::DOC_JOURNAL;
        $docLabel = ['journal' => 'Journal', 'vendor_bill' => 'Vendor bill', 'bill_payment' => 'Supplier payment'][$doc];
        $amt = number_format($threshold, 2, '.', '');
        $stmt = $this->conn->prepare("INSERT INTO approval_policies (tenant_id, doc_type, enabled, threshold, allow_self) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), threshold = VALUES(threshold), allow_self = VALUES(allow_self)");
        $stmt->bind_param('isisi', $t, $doc, $enabled, $amt, $allowSelf);
        if (!$stmt->execute()) {
            return $this->fail('The approval settings could not be saved.');
        }
        $this->settings->log_audit('Changed approval policy', 'Approvals',
            $docLabel . ' approval ' . ($enabled ? 'on' : 'off') . ", threshold {$amt}, self-approval " . ($allowSelf ? 'allowed' : 'not allowed'));
        $this->settings->set_flashdata('success', 'Approval settings saved.');
        return json_encode(['status' => 'success', 'msg' => 'Approval settings saved.']);
    }

    /* --------------------------------- queue ---------------------------------- */

    /** Journals waiting for approval, oldest first, with their totals and preparer. */
    public function pending(): array
    {
        $t = $this->tenantId();
        $side = LedgerService::SIDE_SQL;
        $stmt = $this->conn->prepare("SELECT j.id, j.code, j.journal_date, j.description, j.user_id,
                MAX(CONCAT(u.firstname, ' ', u.lastname)) author,
                COALESCE(SUM(CASE WHEN {$side} = 1 THEN ji.amount END), 0) total
            FROM journal_entries j
            LEFT JOIN journal_items ji ON ji.journal_id = j.id
            LEFT JOIN group_list g ON g.id = ji.group_id
            LEFT JOIN users u ON u.id = j.user_id
            WHERE j.tenant_id = ? AND j.status = ?
            GROUP BY j.id ORDER BY j.journal_date, j.id");
        $p = self::PENDING;
        $stmt->bind_param('is', $t, $p);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function approve(int $id): string
    {
        $t = $this->tenantId();
        $row = $this->pendingRow($id, $t);
        if (!$row) {
            return $this->fail('That journal is not waiting for approval.');
        }
        $me = (int)$this->settings->userdata('id');
        $block = self::approvalBlock((int)$row['user_id'], $me, $this->settings->can_access('journals_approve'), $this->policy()['allow_self']);
        if ($block) {
            return $this->fail($block);
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, (string)$row['journal_date'], $id)) {
            return $this->fail($msg);
        }
        // Re-check that it still balances: nothing unbalanced is ever posted.
        $side = LedgerService::SIDE_SQL;
        $q = $this->conn->prepare("SELECT COALESCE(SUM(CASE WHEN {$side} = 1 THEN ji.amount END),0) dr, COALESCE(SUM(CASE WHEN {$side} = 2 THEN ji.amount END),0) cr
            FROM journal_items ji LEFT JOIN group_list g ON g.id = ji.group_id WHERE ji.journal_id = ?");
        $q->bind_param('i', $id);
        $q->execute();
        $tot = $q->get_result()->fetch_assoc();
        if (abs((float)$tot['dr'] - (float)$tot['cr']) >= 0.005) {
            return $this->fail('This journal does not balance, so it cannot be approved. Return it for correction.');
        }

        $up = $this->conn->prepare("UPDATE journal_entries SET status = 'posted', approved_by = ?, approved_at = NOW(), approval_note = NULL
            WHERE id = ? AND tenant_id = ? AND status = ?");
        $p = self::PENDING;
        $up->bind_param('iiis', $me, $id, $t, $p);
        $up->execute();
        if ($up->affected_rows < 1) {
            return $this->fail('That journal was already dealt with by someone else.');
        }
        $this->settings->log_audit('Approved journal', 'Approvals', "{$row['code']} dated {$row['journal_date']}, total " . number_format((float)$tot['dr'], 2) . '.');
        $this->settings->set_flashdata('success', "Journal {$row['code']} approved and posted.");
        return json_encode(['status' => 'success', 'msg' => "Journal {$row['code']} approved and posted."]);
    }

    /** Send a pending journal back to draft with a reason. */
    public function reject(int $id, string $note): string
    {
        $t = $this->tenantId();
        $row = $this->pendingRow($id, $t);
        if (!$row) {
            return $this->fail('That journal is not waiting for approval.');
        }
        if (!$this->settings->can_access('journals_approve')) {
            return $this->fail('You do not have permission to review journals.');
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            return $this->fail('Say why the journal is being returned.');
        }
        $me = (int)$this->settings->userdata('id');
        $up = $this->conn->prepare("UPDATE journal_entries SET status = 'draft', approval_note = ?, approved_by = ?, approved_at = NULL
            WHERE id = ? AND tenant_id = ? AND status = ?");
        $p = self::PENDING;
        $up->bind_param('siiis', $note, $me, $id, $t, $p);
        $up->execute();
        if ($up->affected_rows < 1) {
            return $this->fail('That journal was already dealt with by someone else.');
        }
        $this->settings->log_audit('Returned journal', 'Approvals', "{$row['code']} returned to draft: {$note}");
        $this->settings->set_flashdata('success', "Journal {$row['code']} returned to draft.");
        return json_encode(['status' => 'success', 'msg' => "Journal {$row['code']} returned to draft."]);
    }

    /* ------------------------------ vendor bills ------------------------------ */

    /** Bills waiting for approval, oldest first. */
    public function pendingBills(): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT b.id, b.bill_no, b.bill_date, b.total, b.submitted_by, p.name vendor,
                CONCAT(u.firstname, ' ', u.lastname) author
            FROM vendor_bill_list b LEFT JOIN party_list p ON p.id = b.vendor_id LEFT JOIN users u ON u.id = b.submitted_by
            WHERE b.tenant_id = ? AND b.status = ? ORDER BY b.bill_date, b.id");
        $p = self::PENDING;
        $stmt->bind_param('is', $t, $p);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function pendingBill(int $id, int $tenantId): ?array
    {
        $stmt = $this->conn->prepare("SELECT id, bill_no, bill_date, total, paid_amount, journal_id, submitted_by FROM vendor_bill_list WHERE id = ? AND tenant_id = ? AND status = ?");
        $p = self::PENDING;
        $stmt->bind_param('iis', $id, $tenantId, $p);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** Approve a bill: the bill becomes payable and its journal posts to the ledger. */
    public function approveBill(int $id): string
    {
        $t = $this->tenantId();
        $bill = $this->pendingBill($id, $t);
        if (!$bill) {
            return $this->fail('That bill is not waiting for approval.');
        }
        $me = (int)$this->settings->userdata('id');
        $block = self::approvalBlock((int)$bill['submitted_by'], $me, $this->settings->can_access('bills_approve'), $this->policy(self::DOC_BILL)['allow_self']);
        if ($block) {
            return $this->fail(str_replace('journal', 'bill', $block));
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, (string)$bill['bill_date'])) {
            return $this->fail($msg);
        }
        $paid = (float)$bill['paid_amount'];
        $status = $paid >= (float)$bill['total'] && (float)$bill['total'] > 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid');

        $this->conn->begin_transaction();
        try {
            $up = $this->conn->prepare("UPDATE vendor_bill_list SET status = ?, approved_by = ?, approved_at = NOW(), approval_note = NULL WHERE id = ? AND tenant_id = ? AND status = ?");
            $p = self::PENDING;
            $up->bind_param('siiis', $status, $me, $id, $t, $p);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('taken');
            }
            if (!empty($bill['journal_id'])) {
                $j = $this->conn->prepare("UPDATE journal_entries SET status = 'posted' WHERE id = ? AND tenant_id = ?");
                $jid = (int)$bill['journal_id'];
                $j->bind_param('ii', $jid, $t);
                $j->execute();
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('That bill was already dealt with by someone else.');
        }
        $this->settings->log_audit('Approved vendor bill', 'Approvals', "{$bill['bill_no']} dated {$bill['bill_date']}, total " . number_format((float)$bill['total'], 2) . '.');
        $this->settings->set_flashdata('success', "Bill {$bill['bill_no']} approved.");
        return json_encode(['status' => 'success', 'msg' => "Bill {$bill['bill_no']} approved."]);
    }

    /** Return a pending bill to draft with a reason; its journal stays out of the ledger. */
    public function rejectBill(int $id, string $note): string
    {
        $t = $this->tenantId();
        $bill = $this->pendingBill($id, $t);
        if (!$bill) {
            return $this->fail('That bill is not waiting for approval.');
        }
        if (!$this->settings->can_access('bills_approve')) {
            return $this->fail('You do not have permission to review bills.');
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            return $this->fail('Say why the bill is being returned.');
        }
        $this->conn->begin_transaction();
        try {
            $up = $this->conn->prepare("UPDATE vendor_bill_list SET status = 'draft', approval_note = ? WHERE id = ? AND tenant_id = ? AND status = ?");
            $p = self::PENDING;
            $up->bind_param('siis', $note, $id, $t, $p);
            $up->execute();
            if ($up->affected_rows < 1) {
                throw new \RuntimeException('taken');
            }
            if (!empty($bill['journal_id'])) {
                $j = $this->conn->prepare("UPDATE journal_entries SET status = 'draft' WHERE id = ? AND tenant_id = ?");
                $jid = (int)$bill['journal_id'];
                $j->bind_param('ii', $jid, $t);
                $j->execute();
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('That bill was already dealt with by someone else.');
        }
        $this->settings->log_audit('Returned vendor bill', 'Approvals', "{$bill['bill_no']} returned to draft: {$note}");
        $this->settings->set_flashdata('success', "Bill {$bill['bill_no']} returned to draft.");
        return json_encode(['status' => 'success', 'msg' => "Bill {$bill['bill_no']} returned to draft."]);
    }

    private function pendingRow(int $id, int $tenantId): ?array
    {
        $stmt = $this->conn->prepare("SELECT id, code, journal_date, user_id FROM journal_entries WHERE id = ? AND tenant_id = ? AND status = ?");
        $p = self::PENDING;
        $stmt->bind_param('iis', $id, $tenantId, $p);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
