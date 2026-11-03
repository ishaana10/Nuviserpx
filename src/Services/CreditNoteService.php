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

    /** What can still be credited on a document: its total less credit already given. Paid documents can be credited too. */
    public static function creditable(float $total, float $credited): float
    {
        return round(max(0.0, $total - $credited), 2);
    }

    /** Money to hand back: paid and credited beyond the document's total, less refunds already made. */
    public static function refundable(float $total, float $paid, float $credited, float $refunded): float
    {
        return round(max(0.0, $paid + $credited - $total - $refunded), 2);
    }

    /** A note can be voided only if the refunds already made still fit within the overpayment that remains afterwards. */
    public static function canVoidNote(float $total, float $paid, float $credited, float $noteTotal, float $refunded): bool
    {
        return $refunded <= round(max(0.0, $paid + ($credited - $noteTotal) - $total), 2) + 0.005;
    }

    /** Why a refund of this size can't be made, or null. */
    public static function refundError(float $amount, float $refundable): ?string
    {
        if ($amount <= 0) {
            return 'Enter the amount to refund.';
        }
        if ($amount > $refundable + 0.005) {
            return 'Only ' . number_format($refundable, 2) . ' can be refunded: that is what has been paid or credited beyond the document\'s total.';
        }
        return null;
    }

    /** Refund journal lines [account_id, debit, credit, description]. Customer refund: Dr Receivables, Cr Bank. Supplier refund: Dr Bank, Cr Payables. */
    public static function refundLines(string $kind, int $controlAcc, int $bankAcc, float $amount): array
    {
        $amount = round($amount, 2);
        if ($kind === self::CREDIT) {
            return [
                ['account_id' => $controlAcc, 'debit' => $amount, 'credit' => 0.0, 'description' => 'Refund settles customer credit'],
                ['account_id' => $bankAcc, 'debit' => 0.0, 'credit' => $amount, 'description' => 'Refund paid to customer'],
            ];
        }
        return [
            ['account_id' => $bankAcc, 'debit' => $amount, 'credit' => 0.0, 'description' => 'Refund received from supplier'],
            ['account_id' => $controlAcc, 'debit' => 0.0, 'credit' => $amount, 'description' => 'Refund settles supplier credit'],
        ];
    }

    /**
     * Totals from note lines. Rows: [description, qty, price, tax_rate]. Blank rows are skipped. Returns
     * ['lines' => [...], 'net', 'tax'] or ['error' => message]. Tax is worked out per line at that line's rate.
     */
    public static function lineTotals(array $rows): array
    {
        $lines = [];
        $net = $tax = 0.0;
        foreach ($rows as $i => $r) {
            $desc = mb_substr(trim((string)($r['description'] ?? '')), 0, 250);
            $qtyRaw = $r['qty'] ?? '';
            $priceRaw = $r['price'] ?? '';
            if ($desc === '' && trim((string)$qtyRaw) === '' && trim((string)$priceRaw) === '') {
                continue;
            }
            $n = $i + 1;
            $qty = BudgetService::cleanAmount($qtyRaw === '' ? '1' : $qtyRaw);
            $price = BudgetService::cleanAmount($priceRaw);
            $rate = TaxService::cleanRate(($r['tax_rate'] ?? '') === '' ? '0' : $r['tax_rate']);
            if ($desc === '') {
                return ['error' => "Line {$n}: describe what is being credited."];
            }
            if ($qty === null || $qty <= 0 || $price === null || $price <= 0 || $rate === null) {
                return ['error' => "Line {$n}: enter a quantity, a price above zero and a tax rate of 0-100."];
            }
            $lineNet = round($qty * $price, 2);
            $lineTax = TaxService::taxOn($lineNet, $rate);
            $lines[] = ['description' => $desc, 'qty' => $qty, 'price' => $price, 'tax_rate' => $rate, 'net' => $lineNet, 'tax' => $lineTax];
            $net += $lineNet;
            $tax += $lineTax;
        }
        if (!$lines) {
            return ['error' => 'Add at least one line to the note.'];
        }
        return ['lines' => $lines, 'net' => round($net, 2), 'tax' => round($tax, 2)];
    }

    /** Why a note of this size can't be raised against the document, or null. $limit is what can still be credited. */
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
                . ' that can still be credited on the document. Raise it for no more than that.';
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
            $sql = "SELECT d.id, d.invoice_no doc_no, d.invoice_date doc_date, d.total, d.tax_amount, d.paid_amount, d.credited_amount, d.currency, d.total_fc, d.paid_fc, d.credited_fc, p.name party
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
            // A foreign-currency invoice is credited in its own currency, and only for what is still unpaid.
            $r['outstanding'] = !empty($r['currency'])
                ? round(max(0.0, (float)$r['total_fc'] - (float)$r['paid_fc'] - (float)$r['credited_fc']), 2)
                : self::creditable((float)$r['total'], (float)$r['credited_amount']);
            $r['currency'] = $r['currency'] ?? null;
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
                COALESCE(i.invoice_no, b.bill_no) doc_no,
                COALESCE(i.total, b.total, 0) doc_total, COALESCE(i.paid_amount, b.paid_amount, 0) doc_paid, COALESCE(i.credited_amount, b.credited_amount, 0) doc_credited,
                (SELECT COALESCE(SUM(r.amount), 0) FROM credit_note_refunds r INNER JOIN credit_notes cn ON cn.id = r.credit_note_id
                    WHERE cn.tenant_id = n.tenant_id AND cn.note_type = n.note_type AND cn.document_id = n.document_id AND r.status = 'issued') doc_refunded
            FROM credit_notes n LEFT JOIN party_list p ON p.id = n.party_id
            LEFT JOIN invoice_list i ON n.note_type = 'credit' AND i.id = n.document_id
            LEFT JOIN vendor_bill_list b ON n.note_type = 'debit' AND b.id = n.document_id
            WHERE n.tenant_id = ? ORDER BY n.note_date DESC, n.id DESC");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['refundable'] = $r['status'] === 'issued'
                ? self::refundable((float)$r['doc_total'], (float)$r['doc_paid'], (float)$r['doc_credited'], (float)$r['doc_refunded'])
                : 0.0;
        }
        return $rows;
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
        $reason = mb_substr(trim((string)($post['reason'] ?? '')), 0, 500);
        $noteLines = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter the date of the note.');
        }
        if (isset($post['line_desc']) && is_array($post['line_desc'])) {
            $rows = [];
            foreach ($post['line_desc'] as $i => $d) {
                $rows[] = ['description' => $d, 'qty' => $post['line_qty'][$i] ?? '', 'price' => $post['line_price'][$i] ?? '', 'tax_rate' => $post['line_tax'][$i] ?? ''];
            }
            $lt = self::lineTotals($rows);
            if (isset($lt['error'])) {
                return $this->fail($lt['error']);
            }
            $noteLines = $lt['lines'];
            $net = $lt['net'];
            $tax = $lt['tax'];
        } else {
            // A single amount and rate (older callers).
            $net = BudgetService::cleanAmount($post['amount'] ?? '');
            $rate = TaxService::cleanRate($post['tax_rate'] ?? '0');
            if ($net === null || $rate === null) {
                return $this->fail('Enter the amount and tax rate as numbers (the amount cannot be negative; the rate is 0–100).');
            }
            $tax = TaxService::taxOn($net, $rate);
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
        $fcInfo = null;
        if ($kind === self::CREDIT) {
            $fcq = $this->conn->query("SELECT currency, fx_rate, total_fc, paid_fc, credited_fc FROM invoice_list WHERE id = " . (int)$docId . " AND tenant_id = " . (int)$t)->fetch_assoc();
            if (!empty($fcq['currency'])) {
                $fcInfo = $fcq;
            }
        }
        if ($date < $doc['doc_date']) {
            return $this->fail('The note cannot be dated before the ' . ($kind === self::CREDIT ? 'invoice' : 'bill') . ' (' . $doc['doc_date'] . ').');
        }

        $total = round($net + $tax, 2);
        $noteFc = null;
        if ($fcInfo) {
            // The note is written in the invoice's currency and limited to its unpaid foreign balance.
            $outstanding = round(max(0.0, (float)$fcInfo['total_fc'] - (float)$fcInfo['paid_fc'] - (float)$fcInfo['credited_fc']), 2);
        } else {
            $outstanding = self::creditable((float)$doc['total'], (float)$doc['credited_amount']);
        }
        if ($err = self::amountError($net, $tax, $outstanding)) {
            return $this->fail($fcInfo ? str_replace('document', $fcInfo['currency'] . ' invoice', $err) : $err);
        }
        if ($fcInfo) {
            // Carry it in base at the invoice's own rate; the note that clears the last foreign unit takes the remaining base value.
            $noteFc = $total;
            $rate = (float)$fcInfo['fx_rate'];
            $remBase = self::outstanding((float)$doc['total'], (float)$doc['paid_amount'], (float)$doc['credited_amount']);
            $total = FxService::allocationBase($noteFc, $outstanding, $remBase, $rate);
            $tax = min(FxService::toBase($tax, $rate), $total);
            $net = round($total - $tax, 2);
            if ($net <= 0) {
                return $this->fail('That note is too small to post in the company currency.');
            }
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
        if ($fcInfo) {
            // The receivable line carries the foreign amount so revaluation sees the balance fall.
            foreach ($lines as &$ln) {
                if ($ln['account_id'] === $control && $ln['credit'] > 0) {
                    $ln['currency'] = strtoupper((string)$fcInfo['currency']);
                    $ln['fx_rate'] = (float)$fcInfo['fx_rate'];
                    $ln['amount_fc'] = $noteFc;
                    break;
                }
            }
            unset($ln);
        }

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
            if ($noteLines) {
                $li = $this->conn->prepare("INSERT INTO credit_note_lines (note_id, description, qty, unit_price, tax_rate, net, tax) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($noteLines as $l) {
                    $q = (string)$l['qty']; $pr = (string)$l['price']; $tr = (string)$l['tax_rate'];
                    $ln = number_format($l['net'], 2, '.', ''); $lx = number_format($l['tax'], 2, '.', '');
                    $li->bind_param('issssss', $noteId, $l['description'], $q, $pr, $tr, $ln, $lx);
                    $li->execute();
                }
            }
            $this->applyToDocument($table, $docId, $t, $total);
            if ($fcInfo) {
                $cur = strtoupper((string)$fcInfo['currency']);
                $rt = number_format((float)$fcInfo['fx_rate'], 8, '.', '');
                $fcAmt = number_format((float)$noteFc, 4, '.', '');
                $fu = $this->conn->prepare("UPDATE credit_notes SET currency = ?, fx_rate = ?, total_fc = ? WHERE id = ? AND tenant_id = ?");
                $fu->bind_param('sssii', $cur, $rt, $fcAmt, $noteId, $t);
                $fu->execute();
                $this->conn->query("UPDATE invoice_list SET credited_fc = credited_fc + {$fcAmt} WHERE id = " . (int)$docId . " AND tenant_id = {$t}");
            }
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
            if ($fcInfo) {
                $this->conn->query("UPDATE invoice_list SET credited_fc = GREATEST(0, credited_fc - " . number_format((float)$noteFc, 4, '.', '') . ") WHERE id = " . (int)$docId . " AND tenant_id = {$t}");
            }
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
        $tbl = $n['note_type'] === self::CREDIT ? 'invoice_list' : 'vendor_bill_list';
        $dq = $this->conn->query("SELECT total, paid_amount, credited_amount FROM {$tbl} WHERE id = " . (int)$n['document_id'] . " AND tenant_id = {$t}")->fetch_assoc();
        $rq = $this->conn->query("SELECT COALESCE(SUM(r.amount), 0) s FROM credit_note_refunds r INNER JOIN credit_notes cn ON cn.id = r.credit_note_id
            WHERE cn.tenant_id = {$t} AND cn.note_type = '{$n['note_type']}' AND cn.document_id = " . (int)$n['document_id'] . " AND r.status = 'issued'")->fetch_assoc();
        if ($dq && !self::canVoidNote((float)$dq['total'], (float)$dq['paid_amount'], (float)$dq['credited_amount'], (float)$n['total'], (float)$rq['s'])) {
            return $this->fail('Money has been refunded against this note. Void the refund first, then void the note.');
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
        if (!empty($n['currency']) && $n['total_fc'] !== null) {
            $this->conn->query("UPDATE invoice_list SET credited_fc = GREATEST(0, credited_fc - " . number_format((float)$n['total_fc'], 4, '.', '') . ") WHERE id = " . (int)$n['document_id'] . " AND tenant_id = {$t}");
        }
        $u = $this->conn->prepare("UPDATE credit_notes SET status = 'void' WHERE id = ? AND tenant_id = ? AND status = 'issued'");
        $u->bind_param('ii', $id, $t);
        $u->execute();
        $this->conn->commit();

        $this->settings->log_audit('Voided note', 'Credit notes', "{$n['note_no']} voided; reversed by {$res['code']}.");
        $msg = "Note {$n['note_no']} voided and its ledger entry reversed.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /** Refund money tied to a note's document: cash back to the customer, or received back from the supplier. */
    public function refund(array $post): string
    {
        $t = $this->tenantId();
        $noteId = (int)($post['note_id'] ?? 0);
        $date = trim((string)($post['refund_date'] ?? ''));
        $amount = BudgetService::cleanAmount($post['amount'] ?? '');
        $bankAcc = (int)($post['bank_account_id'] ?? 0);
        $method = mb_substr(trim((string)($post['method'] ?? 'bank_transfer')), 0, 50) ?: 'bank_transfer';
        $reference = mb_substr(trim((string)($post['reference'] ?? '')), 0, 100);

        $stmt = $this->conn->prepare("SELECT * FROM credit_notes WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $noteId, $t);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc();
        if (!$n || $n['status'] !== 'issued') {
            return $this->fail('Choose an issued note to refund against.');
        }
        $kind = $n['note_type'];
        $need = $kind === self::CREDIT ? 'invoices_edit' : 'purchases_edit';
        if (!$this->settings->can_access($need)) {
            return $this->fail('You do not have permission to record refunds.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date < $n['note_date']) {
            return $this->fail('Enter a refund date on or after the note (' . $n['note_date'] . ').');
        }
        if ($amount === null) {
            return $this->fail('Enter the amount to refund.');
        }
        if ($msg = PeriodLockService::guard($this->conn, $t, $date)) {
            return $this->fail($msg);
        }
        $table = $kind === self::CREDIT ? 'invoice_list' : 'vendor_bill_list';
        $noteNo = (string)$n['note_no'];

        $control = $kind === self::CREDIT ? $this->find(['receivable', 'asset'], 1) : $this->find(['payable', 'liabilit'], 3);
        if ($bankAcc <= 0) {
            $bankAcc = $this->find(['bank', 'cash'], 1);
        }
        $no = $this->nextRefundNumber($date);
        $userId = (int)$this->settings->userdata('id');
        $amt = number_format($amount, 2, '.', '');

        // Reserve the refund under a lock on the document so two refunds cannot both claim the same money.
        $this->conn->begin_transaction();
        try {
            $d = $this->conn->query("SELECT total, paid_amount, credited_amount FROM {$table} WHERE id = " . (int)$n['document_id'] . " AND tenant_id = {$t} FOR UPDATE")->fetch_assoc();
            $rf = $this->conn->query("SELECT COALESCE(SUM(r.amount), 0) s FROM credit_note_refunds r INNER JOIN credit_notes cn ON cn.id = r.credit_note_id
                WHERE cn.tenant_id = {$t} AND cn.note_type = '{$kind}' AND cn.document_id = " . (int)$n['document_id'] . " AND r.status = 'issued'")->fetch_assoc();
            $refundable = self::refundable((float)$d['total'], (float)$d['paid_amount'], (float)$d['credited_amount'], (float)$rf['s']);
            if ($err = self::refundError($amount, $refundable)) {
                throw new \RuntimeException($err);
            }
            $ins = $this->conn->prepare("INSERT INTO credit_note_refunds (tenant_id, credit_note_id, refund_no, refund_date, amount, method, bank_account_id, reference, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?)");
            $ins->bind_param('iissssisi', $t, $noteId, $no, $date, $amt, $method, $bankAcc, $reference, $userId);
            $ins->execute();
            $refundId = (int)$this->conn->insert_id;
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'The refund could not be saved.');
        }

        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal(
            $date, "Refund {$no} on {$noteNo}" . ($reference !== '' ? " ({$reference})" : ''), self::refundLines($kind, $control, $bankAcc, $amount)
        ), true);
        if (($res['status'] ?? '') !== 'success') {
            $del = $this->conn->prepare("DELETE FROM credit_note_refunds WHERE id = ? AND tenant_id = ?");
            $del->bind_param('ii', $refundId, $t);
            $del->execute();
            return $this->fail('The ledger entry could not be posted, so the refund was not recorded: ' . ($res['msg'] ?? ''));
        }
        $jid = (int)$res['id'];
        $up = $this->conn->prepare("UPDATE credit_note_refunds SET journal_id = ? WHERE id = ? AND tenant_id = ?");
        $up->bind_param('iii', $jid, $refundId, $t);
        $up->execute();
        $this->settings->log_audit('Recorded refund', 'Credit notes', "{$no} on {$noteNo}: " . number_format($amount, 2));
        $msg = "Refund {$no} recorded for " . number_format($amount, 2) . '.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /** Void a refund: reverse its journal so the money is owed again. */
    public function voidRefund(int $id): string
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT r.*, n.note_type, n.note_no FROM credit_note_refunds r INNER JOIN credit_notes n ON n.id = r.credit_note_id WHERE r.id = ? AND r.tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        if (!$r || $r['status'] !== 'issued') {
            return $this->fail('That refund is not available to void.');
        }
        if (!$this->settings->can_access($r['note_type'] === self::CREDIT ? 'invoices_edit' : 'purchases_edit')) {
            return $this->fail('You do not have permission to void refunds.');
        }
        $date = date('Y-m-d') < $r['refund_date'] ? $r['refund_date'] : date('Y-m-d');
        $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$r['journal_id'], $date), true);
        if (($res['status'] ?? '') !== 'success') {
            return $this->fail((string)($res['msg'] ?? 'The ledger entry could not be reversed.'));
        }
        $u = $this->conn->prepare("UPDATE credit_note_refunds SET status = 'void' WHERE id = ? AND tenant_id = ? AND status = 'issued'");
        $u->bind_param('ii', $id, $t);
        $u->execute();
        $this->settings->log_audit('Voided refund', 'Credit notes', "{$r['refund_no']} voided; reversed by {$res['code']}.");
        $msg = "Refund {$r['refund_no']} voided and its ledger entry reversed.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /** Refunds, newest first. */
    public function refunds(): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT r.*, n.note_no, n.note_type FROM credit_note_refunds r INNER JOIN credit_notes n ON n.id = r.credit_note_id WHERE r.tenant_id = ? ORDER BY r.refund_date DESC, r.id DESC");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Lines of one note. */
    public function lines(int $noteId): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT l.* FROM credit_note_lines l INNER JOIN credit_notes n ON n.id = l.note_id WHERE l.note_id = ? AND n.tenant_id = ? ORDER BY l.id");
        $stmt->bind_param('ii', $noteId, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function nextRefundNumber(string $date): string
    {
        $t = $this->tenantId();
        $ym = date('Ym', strtotime($date));
        $prefix = "RF-{$ym}-";
        $n = SequenceService::next($this->conn, $t, "refund-{$ym}", function () use ($t, $prefix): int {
            $like = $prefix . '%';
            $s = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(refund_no, ?) AS UNSIGNED)) n FROM credit_note_refunds WHERE tenant_id = ? AND refund_no LIKE ?");
            $from = strlen($prefix) + 1;
            $s->bind_param('iis', $from, $t, $like);
            $s->execute();
            return (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
        });
        return $prefix . sprintf('%04d', $n);
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
