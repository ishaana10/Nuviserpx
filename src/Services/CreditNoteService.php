<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Credit notes (to a customer, against an invoice) and debit notes (to a supplier, against a bill).
 *
 * A credit note undoes part or all of a sale: Dr Revenue (net), Dr VAT Output (tax), Cr Receivables (total).
 * A debit note undoes part or all of a purchase: Dr Payables (total), Cr Expense (net), Cr VAT Input (tax).
 * Both are mirror images of the original posting (TaxService::postingLines with the sides swapped), so VAT
 * is corrected along with the amount. The note reduces what is still owed on the document it is raised
 * against, up to the outstanding balance, and is tracked in credited_amount so payments and the document's
 * own total are left alone. A note can be voided, which reverses its journal and restores the balance.
 */
class CreditNoteService
{
    public const CREDIT = 'credit';   // customer credit note, against an invoice
    public const DEBIT = 'debit';     // supplier debit note, against a vendor bill

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure rules ------------------------------ */

    /** What is still owed on a document after payments and credit notes. */
    public static function outstanding(float $total, float $paid, float $credited): float
    {
        return round(max(0.0, $total - $paid - $credited), 2);
    }

    /** payment status after credits: paid when nothing is left to pay, partially_paid when something has been settled. */
    public static function settledStatus(float $total, float $paid, float $credited, string $current): string
    {
        if (in_array($current, ['draft', 'cancelled'], true)) {
            return $current;
        }
        if ($total > 0 && $paid + $credited >= $total - 0.005) {
            return 'paid';
        }
        return ($paid + $credited) > 0 ? 'partially_paid' : 'unpaid';
    }

    /** Why a note of this size can't be raised against the document, or null. */
    public static function amountError(float $net, float $tax, float $outstanding): ?string
    {
        $total = round($net + $tax, 2);
        if ($net <= 0) {
            return 'Enter the amount of the note before tax.';
        }
        if ($tax < 0) {
            return 'Tax cannot be negative.';
        }
        if ($total > $outstanding + 0.005) {
            return 'The note (' . number_format($total, 2) . ') is more than the ' . number_format($outstanding, 2)
                . ' still owed on the document. Raise it for no more than that.';
        }
        return null;
    }

    /**
     * Journal lines [account_id, debit, credit, description] for a note: the original posting, sides swapped.
     * $mainAcc is revenue (credit note) or expense (debit note); $controlAcc receivables or payables.
     */
    public static function noteLines(string $kind, float $total, float $tax, int $mainAcc, int $taxAcc, int $controlAcc): array
    {
        $orig = TaxService::postingLines($kind === self::CREDIT ? 'sale' : 'purchase', $total, $tax, $mainAcc, $taxAcc, $controlAcc);
        $lines = [];
        foreach ($orig as [$acc, $side, $amt]) {
            $debit = $side === TaxService::CREDIT;   // swapped
            $lines[] = [
                'account_id' => $acc,
                'debit' => $debit ? $amt : 0.0,
                'credit' => $debit ? 0.0 : $amt,
                'description' => $acc === $controlAcc ? ($kind === self::CREDIT ? 'Reduce amount owed by customer' : 'Reduce amount owed to supplier')
                    : ($acc === $taxAcc && $taxAcc > 0 ? 'Tax on note' : ($kind === self::CREDIT ? 'Credit given' : 'Purchase returned')),
            ];
        }
        return $lines;
    }

    /* --------------------------------- issue ---------------------------------- */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** Outstanding invoices (credit) or bills (debit) a note can be raised against. */
    public function documents(string $kind): array
    {
        $t = $this->tenantId();
        if ($kind === self::CREDIT) {
            $sql = "SELECT d.id, d.invoice_no doc_no, d.invoice_date doc_date, d.total, d.tax_amount, d.paid_amount, d.credited_amount, p.name party
                    FROM invoice_list d LEFT JOIN party_list p ON p.id = d.party_id
                    WHERE d.tenant_id = ? AND d.status NOT IN ('draft','cancelled') ORDER BY d.invoice_date DESC, d.id DESC LIMIT 300";
        } else {
            $sql = "SELECT d.id, d.bill_no doc_no, d.bill_date doc_date, d.total, d.tax_amount, d.paid_amount, d.credited_amount, p.name party
                    FROM vendor_bill_list d LEFT JOIN party_list p ON p.id = d.vendor_id
                    WHERE d.tenant_id = ? AND d.status NOT IN ('draft','pending_approval','cancelled') ORDER BY d.bill_date DESC, d.id DESC LIMIT 300";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $t);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $r['outstanding'] = self::outstanding((float)$r['total'], (float)$r['paid_amount'], (float)$r['credited_amount']);
            $net = (float)$r['total'] - (float)$r['tax_amount'];
            $r['tax_rate'] = $net > 0 ? round((float)$r['tax_amount'] / $net * 100, 2) : 0.0;
            if ($r['outstanding'] > 0) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    public function notes(): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT n.*, p.name party,
                CASE WHEN n.note_type = 'credit' THEN (SELECT invoice_no FROM invoice_list WHERE id = n.document_id) ELSE (SELECT bill_no FROM vendor_bill_list WHERE id = n.document_id) END doc_no
            FROM credit_notes n LEFT JOIN party_list p ON p.id = n.party_id WHERE n.tenant_id = ? ORDER BY n.note_date DESC, n.id DESC");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Raise a note. $post: note_type, document_id, note_date, amount (before tax), tax_rate (%), reason. */
    public function issue(array $post): string
    {
        $t = $this->tenantId();
        $kind = ($post['note_type'] ?? '') === self::DEBIT ? self::DEBIT : self::CREDIT;
        $need = $kind === self::CREDIT ? 'invoices_create' : 'purchases_create';
        if (!$this->settings->can_access($need)) {
            return $this->fail('You do not have permission to raise ' . ($kind === self::CREDIT ? 'credit' : 'debit') . ' notes.');
        }
        $docId = (int)($post['document_id'] ?? 0);
        $date = trim((string)($post['note_date'] ?? ''));
        $net = BudgetService::cleanAmount($post['amount'] ?? '');
        $rate = TaxService::cleanRate($post['tax_rate'] ?? '0');
        $reason = mb_substr(trim((string)($post['reason'] ?? '')), 0, 500);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter the date of the note.');
        }
        if ($net === null || $rate === null) {
            return $this->fail('Enter the amount and tax rate as numbers (the amount cannot be negative; the rate is 0–100).');
        }
        if ($reason === '') {
            return $this->fail('Say why the note is being raised.');
        }

        $table = $kind === self::CREDIT ? 'invoice_list' : 'vendor_bill_list';
        $partyCol = $kind === self::CREDIT ? 'party_id' : 'vendor_id';
        $noCol = $kind === self::CREDIT ? 'invoice_no' : 'bill_no';
        $dateCol = $kind === self::CREDIT ? 'invoice_date' : 'bill_date';
        $stmt = $this->conn->prepare("SELECT id, {$noCol} doc_no, {$partyCol} party_id, {$dateCol} doc_date, total, paid_amount, credited_amount, status FROM {$table} WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $docId, $t);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        if (!$doc || in_array($doc['status'], ['draft', 'pending_approval', 'cancelled'], true)) {
            return $this->fail('Choose an issued ' . ($kind === self::CREDIT ? 'invoice' : 'bill') . ' to raise the note against.');
        }
        if ($kind === self::CREDIT) {
            $fcq = $this->conn->query("SELECT currency FROM invoice_list WHERE id = " . (int)$docId . " AND tenant_id = " . (int)$t)->fetch_assoc();
            if (!empty($fcq['currency'])) {
                return $this->fail('This invoice is in ' . $fcq['currency'] . '. Credit notes against foreign-currency invoices are not supported yet; reverse the invoice or contact support.');
            }
        }
        if ($date < $doc['doc_date']) {
            return $this->fail('The note cannot be dated before the ' . ($kind === self::CREDIT ? 'invoice' : 'bill') . ' (' . $doc['doc_date'] . ').');
        }

        $tax = TaxService::taxOn($net, $rate);
        $total = round($net + $tax, 2);
        $outstanding = self::outstanding((float)$doc['total'], (float)$doc['paid_amount'], (float)$doc['credited_amount']);
        if ($err = self::amountError($net, $tax, $outstanding)) {
            return $this->fail($err);
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, $date)) {
            return $this->fail($msg);
        }

        // Accounts: the same ones the original document posts to.
        $taxSvc = new TaxService($this->conn, $this->settings);
        if ($kind === self::CREDIT) {
            $control = $this->find(['receivable', 'asset'], 1);
            $main = $this->find(['sales', 'revenue'], 2);
            $taxAcc = $tax > 0 ? $taxSvc->controlAccount('output') : 0;
        } else {
            $control = $this->find(['payable', 'liabilit'], 3);
            $main = $this->find(['purchase', 'inventory', 'cost of goods', 'expense'], 5);
            $taxAcc = $tax > 0 ? $taxSvc->controlAccount('input') : 0;
        }
        $lines = self::noteLines($kind, $total, $tax, $main, $taxAcc, $control);

        // Record the note and reduce the document first, then post the journal; undo both if the journal fails.
        $noteNo = $this->nextNumber($kind, $date);
        $userId = (int)$this->settings->userdata('id');
        $this->conn->begin_transaction();
        try {
            $ins = $this->conn->prepare("INSERT INTO credit_notes (tenant_id, note_type, note_no, party_id, document_id, note_date, reason, subtotal, tax_amount, total, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?)");
            $n = number_format($net, 2, '.', '');
            $tx = number_format($tax, 2, '.', '');
            $tt = number_format($total, 2, '.', '');
            $party = (int)$doc['party_id'];
            $ins->bind_param('issiisssssi', $t, $kind, $noteNo, $party, $docId, $date, $reason, $n, $tx, $tt, $userId);
            if (!$ins->execute()) {
                throw new \RuntimeException($this->conn->error);
            }
            $noteId = (int)$this->conn->insert_id;
            $this->applyToDocument($table, $docId, $t, $total);
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The note could not be saved. Nothing was changed.');
        }

        $label = $kind === self::CREDIT ? 'Credit note' : 'Debit note';
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal(
            $date, "{$label} {$noteNo} against {$doc['doc_no']}: {$reason}", $lines
        ), true);
        if (($res['status'] ?? '') !== 'success') {
            $this->conn->begin_transaction();
            $this->applyToDocument($table, $docId, $t, -$total);
            $d = $this->conn->prepare("DELETE FROM credit_notes WHERE id = ? AND tenant_id = ?");
            $d->bind_param('ii', $noteId, $t);
            $d->execute();
            $this->conn->commit();
            return $this->fail('The ledger entry could not be posted, so the note was not raised: ' . ($res['msg'] ?? ''));
        }
        $jid = (int)$res['id'];
        $u = $this->conn->prepare("UPDATE credit_notes SET journal_id = ? WHERE id = ? AND tenant_id = ?");
        $u->bind_param('iii', $jid, $noteId, $t);
        $u->execute();

        $this->settings->log_audit("Raised {$kind} note", 'Credit notes', "{$noteNo} against {$doc['doc_no']}: net {$n}, tax {$tx}, total {$tt}. {$reason}");
        $msg = "{$label} {$noteNo} raised for " . number_format($total, 2) . '.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $noteId]);
    }

    /** Void a note: reverse its journal and give the balance back to the document. */
    public function void(int $id): string
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT * FROM credit_notes WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc();
        if (!$n) {
            return $this->fail('Note not found.');
        }
        if ($n['status'] !== 'issued') {
            return $this->fail('This note has already been voided.');
        }
        $need = $n['note_type'] === self::CREDIT ? 'invoices_edit' : 'purchases_edit';
        if (!$this->settings->can_access($need)) {
            return $this->fail('You do not have permission to void this note.');
        }
        $date = date('Y-m-d') < $n['note_date'] ? $n['note_date'] : date('Y-m-d');
        $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$n['journal_id'], $date), true);
        if (($res['status'] ?? '') !== 'success') {
            return $this->fail((string)($res['msg'] ?? 'The ledger entry could not be reversed.'));
        }
        $table = $n['note_type'] === self::CREDIT ? 'invoice_list' : 'vendor_bill_list';
        $total = (float)$n['total'];
        $this->conn->begin_transaction();
        $this->applyToDocument($table, (int)$n['document_id'], $t, -$total);
        $u = $this->conn->prepare("UPDATE credit_notes SET status = 'void' WHERE id = ? AND tenant_id = ? AND status = 'issued'");
        $u->bind_param('ii', $id, $t);
        $u->execute();
        $this->conn->commit();

        $this->settings->log_audit('Voided note', 'Credit notes', "{$n['note_no']} voided; reversed by {$res['code']}.");
        $msg = "Note {$n['note_no']} voided and its ledger entry reversed.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /** Add (or, if negative, take back) credit on a document and recompute its balance and status. */
    private function applyToDocument(string $table, int $docId, int $t, float $delta): void
    {
        $table = $table === 'invoice_list' ? 'invoice_list' : 'vendor_bill_list';
        $r = $this->conn->prepare("SELECT total, paid_amount, credited_amount, status FROM {$table} WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $r->bind_param('ii', $docId, $t);
        $r->execute();
        $d = $r->get_result()->fetch_assoc();
        if (!$d) {
            throw new \RuntimeException('Document missing.');
        }
        $credited = round(max(0.0, (float)$d['credited_amount'] + $delta), 2);
        $balance = self::outstanding((float)$d['total'], (float)$d['paid_amount'], $credited);
        $status = self::settledStatus((float)$d['total'], (float)$d['paid_amount'], $credited, (string)$d['status']);
        $u = $this->conn->prepare("UPDATE {$table} SET credited_amount = ?, balance = ?, status = ? WHERE id = ? AND tenant_id = ?");
        $u->bind_param('ddsii', $credited, $balance, $status, $docId, $t);
        $u->execute();
    }

    private function find(array $keywords, int $default): int
    {
        $t = $this->tenantId();
        $clauses = array_map(fn ($k) => "`name` LIKE '%" . $this->conn->real_escape_string($k) . "%'", $keywords);
        $q = $this->conn->query("SELECT id FROM account_list WHERE (" . implode(' OR ', $clauses) . ") AND tenant_id = '{$t}' AND delete_flag = 0 LIMIT 1");
        return ($q && $q->num_rows) ? (int)$q->fetch_assoc()['id'] : $default;
    }

    private function nextNumber(string $kind, string $date): string
    {
        $t = $this->tenantId();
        $ym = date('Ym', strtotime($date));
        $prefix = ($kind === self::CREDIT ? 'CN-' : 'DN-') . $ym . '-';
        $n = SequenceService::next($this->conn, $t, "{$kind}note-{$ym}", function () use ($t, $prefix): int {
            $like = $prefix . '%';
            $s = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(note_no, ?) AS UNSIGNED)) n FROM credit_notes WHERE tenant_id = ? AND note_no LIKE ?");
            $from = strlen($prefix) + 1;
            $s->bind_param('iis', $from, $t, $like);
            $s->execute();
            return (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
        });
        return $prefix . sprintf('%04d', $n);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
