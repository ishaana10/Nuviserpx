<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class JournalService
{
    /** Documents that create journals automatically: table => human label. */
    private const SOURCES = [
        'invoice_list' => ['label' => 'invoice', 'ref' => 'invoice_no'],
        'payment_list' => ['label' => 'payment', 'ref' => 'payment_no'],
        'payroll_list' => ['label' => 'payroll record', 'ref' => 'payroll_run_ref'],
    ];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Save a manual journal. Lines must balance (debits = credits) and use this
     * company's accounts; the header and lines are written in one transaction.
     */
    public function saveJournal(array $post): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $id = isset($post['id']) ? (int)$post['id'] : 0;

        $date = trim((string)($post['journal_date'] ?? ''));
        $description = trim((string)($post['description'] ?? ''));
        $status = in_array($post['status'] ?? 'posted', ['draft', 'posted'], true) ? (string)$post['status'] : 'posted';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter a valid journal date.');
        }
        if ($description === '') {
            return $this->fail('Enter a narration for this journal.');
        }

        $lines = $this->collectLines($post);
        $ledger = new LedgerService($this->conn, $tenant_id);
        $errors = LedgerService::validateLines($lines, $ledger->accountIds(), $status === 'posted');
        if ($errors) {
            return $this->fail(implode(' ', $errors), $errors);
        }

        $existing = null;
        if ($id > 0) {
            $existing = $this->journalRow($id, $tenant_id);
            if (!$existing) {
                return $this->fail('Journal not found.');
            }
            if ($this->isCancelled($existing['status'] ?? '')) {
                return $this->fail('Cancelled journals cannot be edited.');
            }
            if ($source = $this->sourceDocument($id, $tenant_id)) {
                return $this->fail("This journal was created by {$source}. Edit that document instead so the two stay in step.");
            }
        }

        $reversing = !empty($post['auto_reversing_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$post['auto_reversing_date'])
            ? (string)$post['auto_reversing_date'] : null;
        if ($reversing !== null && $reversing <= $date) {
            return $this->fail('The auto-reversing date must be after the journal date.');
        }
        $defaultNarration = isset($post['default_narration']) ? (int)$post['default_narration'] : 0;
        $cashBasis = isset($post['show_cash_basis']) ? (int)$post['show_cash_basis'] : 0;
        $taxMode = mb_substr(trim((string)($post['tax_mode'] ?? '')) ?: 'No Tax', 0, 50);
        $userId = (int)$this->settings->userdata('id');

        $this->conn->begin_transaction();
        try {
            if ($id === 0) {
                $code = $this->nextCode($tenant_id);
                $stmt = $this->conn->prepare("INSERT INTO journal_entries
                    (tenant_id, code, journal_date, auto_reversing_date, description, default_narration, show_cash_basis, tax_mode, status, user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issssiissi', $tenant_id, $code, $date, $reversing, $description, $defaultNarration, $cashBasis, $taxMode, $status, $userId);
                if (!$stmt->execute()) {
                    throw new \RuntimeException($this->conn->error);
                }
                $id = (int)$this->conn->insert_id;
            } else {
                $stmt = $this->conn->prepare("UPDATE journal_entries SET journal_date = ?, auto_reversing_date = ?, description = ?,
                    default_narration = ?, show_cash_basis = ?, tax_mode = ?, status = ? WHERE id = ? AND tenant_id = ?");
                $stmt->bind_param('sssiissii', $date, $reversing, $description, $defaultNarration, $cashBasis, $taxMode, $status, $id, $tenant_id);
                if (!$stmt->execute()) {
                    throw new \RuntimeException($this->conn->error);
                }
                $del = $this->conn->prepare("DELETE FROM journal_items WHERE journal_id = ?");
                $del->bind_param('i', $id);
                $del->execute();
            }

            $ins = $this->conn->prepare("INSERT INTO journal_items (journal_id, account_id, group_id, entry_side, amount, description, tax_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $l) {
                $dr = round((float)$l['debit'], 2);
                $cr = round((float)$l['credit'], 2);
                if ((int)$l['account_id'] === 0 || ($dr == 0.0 && $cr == 0.0)) {
                    continue;
                }
                $side = $dr > 0 ? 1 : 2;              // legacy group_id encoding: 1 = debit, 2 = credit
                $entrySide = $dr > 0 ? 'debit' : 'credit';
                // Bound as a decimal string so the value never passes through a binary float on the way to DECIMAL(18,4).
                $amount = number_format($dr > 0 ? $dr : $cr, 4, '.', '');
                $acc = (int)$l['account_id'];
                $desc = mb_substr((string)$l['description'], 0, 500);
                $tax = mb_substr((string)$l['tax_rate'], 0, 100) ?: 'No Tax (0%)';
                $ins->bind_param('iiissss', $id, $acc, $side, $entrySide, $amount, $desc, $tax);
                if (!$ins->execute()) {
                    throw new \RuntimeException($this->conn->error);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The journal could not be saved. Nothing was changed.');
        }

        $msg = $existing ? 'Journal updated.' : ($status === 'draft' ? 'Journal saved as a draft.' : 'Journal posted.');
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $id]);
    }

    /**
     * Post opening balances as ONE dated journal.
     *
     * $post: as_at (Y-m-d), account_id[], debit[], credit[]. Whatever the lines leave unbalanced is
     * taken to the tenant's "Opening Balance Equity" account, so the journal always balances and the
     * trial balance opens correctly. Use the day before the first transaction as the date.
     */
    public function postOpeningBalances(array $post): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $asAt = trim((string)($post['as_at'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt) || !strtotime($asAt)) {
            return $this->fail('Enter the date the opening balances are as at.');
        }

        $obe = $this->openingEquityAccountId($tenant_id);
        if ($obe === 0) {
            return $this->fail('The “Opening Balance Equity” account is missing. Reload the app to recreate it.');
        }

        $acc = $dr = $cr = [];
        $totalDr = $totalCr = 0.0;
        foreach ((array)($post['account_id'] ?? []) as $k => $accountId) {
            $d = round((float)($post['debit'][$k] ?? 0), 2);
            $c = round((float)($post['credit'][$k] ?? 0), 2);
            if ((int)$accountId === 0 || ($d == 0.0 && $c == 0.0)) {
                continue;
            }
            if ((int)$accountId === $obe) {
                return $this->fail('Opening Balance Equity is filled in automatically. Leave it out of the list.');
            }
            $acc[] = (int)$accountId;
            $dr[] = $d;
            $cr[] = $c;
            $totalDr += $d;
            $totalCr += $c;
        }
        if (!$acc) {
            return $this->fail('Enter at least one opening balance.');
        }

        $diff = round($totalDr - $totalCr, 2);
        if ($diff != 0.0) {
            $acc[] = $obe;
            $dr[] = $diff < 0 ? -$diff : 0.0;
            $cr[] = $diff > 0 ? $diff : 0.0;
        }

        return $this->saveJournal([
            'journal_date' => $asAt,
            'description' => 'Opening balances as at ' . $asAt,
            'status' => 'posted',
            'account_id' => $acc,
            'debit' => $dr,
            'credit' => $cr,
        ]);
    }

    private function openingEquityAccountId(int $tenant_id): int
    {
        $stmt = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND name = 'Opening Balance Equity' AND delete_flag = 0 LIMIT 1");
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    }

    /**
     * Delete a journal. Journals created by an invoice, payment or payroll run must be
     * changed from that document instead, so the ledger and the document stay in step.
     */
    public function deleteJournal(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        if (!$this->journalRow($id, $tenant_id)) {
            return $this->fail('Journal not found.');
        }
        if ($source = $this->sourceDocument($id, $tenant_id)) {
            return $this->fail("This journal belongs to {$source}. Edit or cancel that document instead.");
        }
        $this->conn->begin_transaction();
        $a = $this->conn->prepare("DELETE FROM journal_items WHERE journal_id = ?");
        $a->bind_param('i', $id);
        $b = $this->conn->prepare("DELETE FROM journal_entries WHERE id = ? AND tenant_id = ?");
        $b->bind_param('ii', $id, $tenant_id);
        if ($a->execute() && $b->execute()) {
            $this->conn->commit();
            $this->settings->set_flashdata('success', 'Journal deleted.');
            return json_encode(['status' => 'success']);
        }
        $this->conn->rollback();
        return $this->fail('The journal could not be deleted.');
    }

    /** Cancel (void) a journal: it stays on record but no longer affects any report. */
    public function cancelJournal(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        if (!$this->journalRow($id, $tenant_id)) {
            return $this->fail('Journal not found.');
        }
        if ($source = $this->sourceDocument($id, $tenant_id)) {
            return $this->fail("This journal belongs to {$source}. Cancel that document instead.");
        }
        $stmt = $this->conn->prepare("UPDATE journal_entries SET status = 'cancelled' WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $tenant_id);
        if ($stmt->execute()) {
            $this->settings->set_flashdata('success', 'Journal cancelled. It no longer affects your reports.');
            return json_encode(['status' => 'success']);
        }
        return $this->fail('The journal could not be cancelled.');
    }

    /** "invoice INV-0012" etc. when the journal was generated by another document. */
    public function sourceDocument(int $journalId, int $tenantId): ?string
    {
        foreach (self::SOURCES as $table => $meta) {
            $stmt = @$this->conn->prepare("SELECT `{$meta['ref']}` ref FROM `{$table}` WHERE journal_id = ? AND tenant_id = ? LIMIT 1");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('ii', $journalId, $tenantId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                return trim($meta['label'] . ' ' . ($row['ref'] ?? ''));
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */

    /** Normalise the posted line arrays into [account_id, debit, credit, description, tax_rate]. */
    private function collectLines(array $post): array
    {
        $lines = [];
        foreach ((array)($post['account_id'] ?? []) as $k => $acc) {
            $dr = isset($post['debit'][$k]) && $post['debit'][$k] !== '' ? (float)$post['debit'][$k] : 0.0;
            $cr = isset($post['credit'][$k]) && $post['credit'][$k] !== '' ? (float)$post['credit'][$k] : 0.0;
            if ($dr == 0.0 && $cr == 0.0 && isset($post['amount'][$k])) {
                // Legacy form: amount + group_id (1 debit / 2 credit).
                $amt = (float)$post['amount'][$k];
                (int)($post['group_id'][$k] ?? 1) === 2 ? $cr = $amt : $dr = $amt;
            }
            $lines[] = [
                'account_id' => (int)$acc,
                'debit' => $dr,
                'credit' => $cr,
                'description' => (string)($post['line_description'][$k] ?? ''),
                'tax_rate' => (string)($post['tax_rate'][$k] ?? 'No Tax (0%)'),
            ];
        }
        return $lines;
    }

    private function nextCode(int $tenant_id): string
    {
        $prefix = date('Ym-');
        $like = $prefix . '%';
        $stmt = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(code, 8) AS UNSIGNED)) n FROM journal_entries WHERE tenant_id = ? AND code LIKE ?");
        $stmt->bind_param('is', $tenant_id, $like);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        return $prefix . sprintf('%05d', $n + 1);
    }

    private function journalRow(int $id, int $tenant_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM journal_entries WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function isCancelled(string $status): bool
    {
        return in_array(strtolower($status), ['cancelled', 'canceled', 'void', '2', '3'], true);
    }

    private function fail(string $msg, array $errors = []): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg] + ($errors ? ['errors' => $errors] : []));
    }
}
