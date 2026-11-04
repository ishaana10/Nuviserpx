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
        'invoice_cogs' => ['table' => 'invoice_list', 'col' => 'cogs_journal_id', 'label' => 'cost of sales for invoice', 'ref' => 'invoice_no'],
        'stock_movement' => ['table' => 'inventory_logs', 'label' => 'stock movement', 'ref' => 'reference'],
        'inventory_opening' => ['table' => 'inventory_settings', 'col' => 'opening_journal_id', 'label' => 'opening inventory from', 'ref' => 'enabled_on'],
        'payment_list' => ['label' => 'payment', 'ref' => 'payment_no'],
        'payroll_list' => ['label' => 'payroll record', 'ref' => 'payroll_run_ref'],
        'payroll_payments' => ['label' => 'salary payment', 'ref' => 'reference'],
        'payroll_remittances' => ['label' => 'payroll remittance', 'ref' => 'reference'],
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
        if (array_filter($lines, fn ($l) => trim((string)($l['currency'] ?? '')) !== '')) {
            $fx = new FxService($this->conn, $this->settings);
            [$lines, $fxErrors] = FxService::convertLines($lines, $fx->baseCurrency(), fn ($c) => $fx->rateOn($c, $date));
            if ($fxErrors) {
                return $this->fail(implode(' ', $fxErrors), $fxErrors);
            }
        }
        $ledger = new LedgerService($this->conn, $tenant_id);
        $errors = LedgerService::validateLines($lines, $ledger->accountIds(), $status === 'posted');
        if ($errors) {
            return $this->fail(implode(' ', $errors), $errors);
        }

        // Maker/checker: a journal over the company's threshold waits for someone else to approve it.
        $submitted = false;
        if ($status === 'posted') {
            $policy = (new ApprovalService($this->conn, $this->settings))->policy();
            $total = array_sum(array_map(fn ($l) => round((float)$l['debit'], 2), $lines));
            if (ApprovalService::requiresApproval($policy['enabled'], $policy['threshold'], $total)) {
                $status = ApprovalService::PENDING;
                $submitted = true;
            }
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
            if ($reason = self::lockedByStatus($existing['status'] ?? '')) {
                return $this->fail($reason);
            }
            // The edit form has no currency column; saving would silently turn foreign lines into base amounts.
            $fcq = $this->conn->prepare("SELECT COUNT(*) n FROM journal_items WHERE journal_id = ? AND currency IS NOT NULL AND currency <> ''");
            $fcq->bind_param('i', $id);
            $fcq->execute();
            $hasFc = (int)($fcq->get_result()->fetch_assoc()['n'] ?? 0) > 0;
            if ($hasFc && !array_filter((array)($post['currency'] ?? []), fn ($c) => trim((string)$c) !== '')) {
                return $this->fail('This journal has foreign-currency lines. Send the currency of each line with the save, or reverse it and post a corrected journal.');
            }
        }
        if ($msg = PeriodLockService::guard($this->conn, $tenant_id, $date, $id ?: null)) {
            return $this->fail($msg);
        }
        $beforeLines = $existing ? $this->describeStored($id) : '';
        $journalCode = (string)($existing['code'] ?? '');

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
                $journalCode = $code;
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

            $ins = $this->conn->prepare("INSERT INTO journal_items (journal_id, account_id, group_id, entry_side, amount, description, tax_rate, currency, fx_rate, amount_fc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                $cur = !empty($l['currency']) ? (string)$l['currency'] : null;
                $fxRate = $cur !== null ? number_format((float)$l['fx_rate'], 8, '.', '') : null;
                $fc = $cur !== null ? number_format((float)$l['amount_fc'], 4, '.', '') : null;
                $ins->bind_param('iiisssssss', $id, $acc, $side, $entrySide, $amount, $desc, $tax, $cur, $fxRate, $fc);
                if (!$ins->execute()) {
                    throw new \RuntimeException($this->conn->error);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The journal could not be saved. Nothing was changed.');
        }

        $msg = $submitted ? "Journal {$journalCode} submitted for approval. It will post once an approver signs it off."
            : ($existing ? 'Journal updated.' : ($status === 'draft' ? 'Journal saved as a draft.' : 'Journal posted.'));
        $after = self::describeLines($lines);
        $this->settings->log_audit(
            $existing ? ($status === 'posted' ? 'Posted draft journal' : 'Edited draft journal') : ($status === 'draft' ? 'Created draft journal' : 'Posted journal'),
            'Journals',
            "{$journalCode} dated {$date} — {$description}. " . ($existing ? "Before: {$beforeLines}. After: {$after}." : "Lines: {$after}.")
        );
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

        $fx = new FxService($this->conn, $this->settings);
        [$form, $errors] = self::openingBalanceForm($post, $obe, $fx->baseCurrency(), fn ($c) => $fx->rateOn($c, $asAt));
        if ($errors) {
            return $this->fail(implode(' ', $errors), $errors);
        }

        return $this->saveJournal($form + [
            'journal_date' => $asAt,
            'description' => 'Opening balances as at ' . $asAt,
            'status' => 'posted',
        ]);
    }

    /**
     * Build the journal form arrays for an opening-balance posting. A line may name a foreign currency
     * (currency[], optional fx_rate[]); its debit/credit are then in that currency and are converted at the
     * as-at rate, so the foreign balance is kept (amount_fc) and the account can be reconciled in that currency.
     * Whatever the converted lines leave unbalanced goes to Opening Balance Equity in base currency.
     * Returns [form arrays, errors].
     */
    public static function openingBalanceForm(array $post, int $obe, string $base, callable $rateFor): array
    {
        $lines = [];
        foreach ((array)($post['account_id'] ?? []) as $k => $accountId) {
            $d = round((float)($post['debit'][$k] ?? 0), 2);
            $c = round((float)($post['credit'][$k] ?? 0), 2);
            if ((int)$accountId === 0 || ($d == 0.0 && $c == 0.0)) {
                continue;
            }
            if ((int)$accountId === $obe) {
                return [[], ['Opening Balance Equity is filled in automatically. Leave it out of the list.']];
            }
            if ($d > 0 && $c > 0) {
                return [[], ['Enter either a debit or a credit on each line, not both.']];
            }
            if ($d < 0 || $c < 0) {
                return [[], ['Opening balances cannot be negative. Put the amount on the other side instead.']];
            }
            $lines[] = [
                'account_id' => (int)$accountId, 'debit' => $d, 'credit' => $c,
                'currency' => (string)($post['currency'][$k] ?? ''),
                'fx_rate' => (string)($post['fx_rate'][$k] ?? ''),
            ];
        }
        if (!$lines) {
            return [[], ['Enter at least one opening balance.']];
        }

        [$converted, $errors] = FxService::convertLines($lines, $base, $rateFor);
        if ($errors) {
            return [[], $errors];
        }
        $totalDr = $totalCr = 0.0;
        foreach ($converted as $l) {
            $totalDr += round((float)$l['debit'], 2);
            $totalCr += round((float)$l['credit'], 2);
        }

        $form = ['account_id' => [], 'debit' => [], 'credit' => [], 'currency' => [], 'fx_rate' => []];
        foreach ($lines as $i => $l) {
            $foreign = !empty($converted[$i]['currency']);
            $form['account_id'][] = $l['account_id'];
            $form['debit'][] = $l['debit'];
            $form['credit'][] = $l['credit'];
            $form['currency'][] = $foreign ? $converted[$i]['currency'] : '';
            $form['fx_rate'][] = $foreign ? $converted[$i]['fx_rate'] : '';
        }
        $diff = round($totalDr - $totalCr, 2);
        if ($diff != 0.0) {
            $form['account_id'][] = $obe;
            $form['debit'][] = $diff < 0 ? -$diff : 0.0;
            $form['credit'][] = $diff > 0 ? $diff : 0.0;
            $form['currency'][] = '';
            $form['fx_rate'][] = '';
        }
        return [$form, []];
    }

    /**
     * Post a journal produced by the system (depreciation, disposals): already in base currency, balanced,
     * and checked against the period lock. Lines: [account_id, debit, credit, description]. Not reachable from a request.
     */
    public function postSystemJournal(string $date, string $description, array $lines, string $status = 'posted'): string
    {
        return $this->postRevaluation($date, $description, $lines, $status);
    }

    /**
     * Post a foreign-currency revaluation journal. Lines arrive already in base currency, tagged with the
     * currency they restate (amount_fc 0), so only FxService calls this; it is not reachable from a request.
     */
    public function postRevaluation(string $date, string $description, array $lines, string $status = 'posted'): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $errors = LedgerService::validateLines($lines, (new LedgerService($this->conn, $tenant_id))->accountIds(), true);
        if ($errors) {
            return $this->fail(implode(' ', $errors), $errors);
        }
        if ($msg = PeriodLockService::guard($this->conn, $tenant_id, $date)) {
            return $this->fail($msg);
        }
        $userId = (int)$this->settings->userdata('id');
        $this->conn->begin_transaction();
        try {
            $code = $this->nextCode($tenant_id);
            $h = $this->conn->prepare("INSERT INTO journal_entries (tenant_id, code, journal_date, description, tax_mode, status, user_id) VALUES (?, ?, ?, ?, 'No Tax', ?, ?)");
            $h->bind_param('issssi', $tenant_id, $code, $date, $description, $status, $userId);
            if (!$h->execute()) {
                throw new \RuntimeException($this->conn->error);
            }
            $id = (int)$this->conn->insert_id;
            $ins = $this->conn->prepare("INSERT INTO journal_items (journal_id, account_id, group_id, entry_side, amount, description, currency, fx_rate, amount_fc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $l) {
                $dr = round((float)$l['debit'], 2);
                $side = $dr > 0 ? 1 : 2;
                $entry = $dr > 0 ? 'debit' : 'credit';
                $amount = number_format($dr > 0 ? $dr : round((float)$l['credit'], 2), 4, '.', '');
                $acc = (int)$l['account_id'];
                $desc = mb_substr((string)$l['description'], 0, 500);
                $cur = !empty($l['currency']) ? (string)$l['currency'] : null;
                $rate = $cur !== null ? number_format((float)$l['fx_rate'], 8, '.', '') : null;
                $fc = $cur !== null ? number_format((float)($l['amount_fc'] ?? 0), 4, '.', '') : null;
                $ins->bind_param('iiissssss', $id, $acc, $side, $entry, $amount, $desc, $cur, $rate, $fc);
                if (!$ins->execute()) {
                    throw new \RuntimeException($this->conn->error);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The revaluation could not be posted. Nothing was changed.');
        }
        $this->settings->log_audit('Posted FX revaluation', 'Journals', "{$code} dated {$date}. Lines: " . self::describeLines($lines) . '.');
        $msg = "Revaluation posted as journal {$code}.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $id, 'code' => $code]);
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
        $row = $this->journalRow($id, $tenant_id);
        if (!$row) {
            return $this->fail('Journal not found.');
        }
        if ($source = $this->sourceDocument($id, $tenant_id)) {
            return $this->fail("This journal belongs to {$source}. Edit or cancel that document instead.");
        }
        if ($reason = self::lockedByStatus($row['status'] ?? '', 'deleted')) {
            return $this->fail($reason);
        }
        if ($msg = PeriodLockService::guard($this->conn, $tenant_id, null, $id)) {
            return $this->fail($msg);
        }
        $lines = $this->describeStored($id);
        $this->conn->begin_transaction();
        $a = $this->conn->prepare("DELETE FROM journal_items WHERE journal_id = ?");
        $a->bind_param('i', $id);
        $b = $this->conn->prepare("DELETE FROM journal_entries WHERE id = ? AND tenant_id = ?");
        $b->bind_param('ii', $id, $tenant_id);
        if ($a->execute() && $b->execute()) {
            $this->conn->commit();
            $this->settings->log_audit('Deleted draft journal', 'Journals', "{$row['code']} dated {$row['journal_date']} — {$row['description']}. Lines: {$lines}.");
            $this->settings->set_flashdata('success', 'Draft journal deleted.');
            return json_encode(['status' => 'success']);
        }
        $this->conn->rollback();
        return $this->fail('The journal could not be deleted.');
    }

    /**
     * Reverse a posted journal: post a new journal, dated $date (default today), with every line on the
     * opposite side, linked to the original. The original is never touched, so the ledger keeps the full
     * story — what was booked, and that it was undone, by whom and when.
     */
    public function reverseJournal(int $id, ?string $date = null, bool $fromSource = false): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $orig = $this->journalRow($id, $tenant_id);
        if (!$orig) {
            return $this->fail('Journal not found.');
        }
        if (!$fromSource && ($source = $this->sourceDocument($id, $tenant_id))) {
            return $this->fail("This journal belongs to {$source}. Cancel or credit that document instead.");
        }
        if (self::statusKind($orig['status'] ?? '') !== 'posted') {
            return $this->fail('Only posted journals can be reversed. A draft can simply be deleted.');
        }
        if (!empty($orig['reverses_id'])) {
            return $this->fail('This journal is itself a reversal. Post a new journal if it needs correcting.');
        }
        $stmt = $this->conn->prepare("SELECT code FROM journal_entries WHERE reverses_id = ? AND tenant_id = ? LIMIT 1");
        $stmt->bind_param('ii', $id, $tenant_id);
        $stmt->execute();
        if ($done = $stmt->get_result()->fetch_assoc()) {
            return $this->fail("This journal has already been reversed by {$done['code']}.");
        }

        $date = trim((string)$date) === '' ? date('Y-m-d') : trim((string)$date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter a valid reversal date.');
        }
        if ($date < (string)$orig['journal_date']) {
            return $this->fail('A reversal cannot be dated before the journal it reverses (' . $orig['journal_date'] . ').');
        }
        if ($msg = PeriodLockService::guard($this->conn, $tenant_id, $date)) {
            return $this->fail($msg);
        }

        $side = LedgerService::SIDE_SQL;
        $q = $this->conn->prepare("SELECT ji.account_id, ji.amount, ji.description, ji.tax_rate, ji.currency, ji.fx_rate, ji.amount_fc, {$side} side
            FROM journal_items ji LEFT JOIN group_list g ON g.id = ji.group_id WHERE ji.journal_id = ?");
        $q->bind_param('i', $id);
        $q->execute();
        $lines = self::reverseLines($q->get_result()->fetch_all(MYSQLI_ASSOC));
        if (!$lines) {
            return $this->fail('This journal has no lines to reverse.');
        }

        $userId = (int)$this->settings->userdata('id');
        $description = mb_substr('Reversal of ' . $orig['code'] . ' — ' . $orig['description'], 0, 1000);
        $taxMode = (string)($orig['tax_mode'] ?? 'No Tax');
        $this->conn->begin_transaction();
        try {
            $code = $this->nextCode($tenant_id);
            $h = $this->conn->prepare("INSERT INTO journal_entries (tenant_id, code, journal_date, description, tax_mode, status, reverses_id, user_id)
                VALUES (?, ?, ?, ?, ?, 'posted', ?, ?)");
            $h->bind_param('issssii', $tenant_id, $code, $date, $description, $taxMode, $id, $userId);
            if (!$h->execute()) {
                throw new \RuntimeException($this->conn->error);
            }
            $newId = (int)$this->conn->insert_id;
            $ins = $this->conn->prepare("INSERT INTO journal_items (journal_id, account_id, group_id, entry_side, amount, description, tax_rate, currency, fx_rate, amount_fc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $l) {
                $groupId = (int)$l['side'];
                $entrySide = $groupId === 1 ? 'debit' : 'credit';
                $amount = is_numeric($l['amount']) ? (string)$l['amount'] : '0'; // exact DECIMAL string, never via float
                $acc = (int)$l['account_id'];
                $desc = (string)($l['description'] ?? '');
                $tax = (string)($l['tax_rate'] ?? 'No Tax (0%)');
                $cur = !empty($l['currency']) ? (string)$l['currency'] : null;
                $fxRate = $cur !== null ? (string)$l['fx_rate'] : null;
                $fc = $cur !== null ? (string)$l['amount_fc'] : null;
                $ins->bind_param('iiisssssss', $newId, $acc, $groupId, $entrySide, $amount, $desc, $tax, $cur, $fxRate, $fc);
                if (!$ins->execute()) {
                    throw new \RuntimeException($this->conn->error);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The reversal could not be posted. Nothing was changed.');
        }

        $this->settings->log_audit('Reversed journal', 'Journals', "{$orig['code']} reversed by {$code} dated {$date}. Lines: " . self::describeLines($lines) . '.');
        $msg = "Journal {$orig['code']} reversed by {$code}.";
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $newId, 'code' => $code]);
    }

    /** Cancel (void) a journal: it stays on record but no longer affects any report. */
    public function cancelJournal(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $row = $this->journalRow($id, $tenant_id);
        if (!$row) {
            return $this->fail('Journal not found.');
        }
        if ($source = $this->sourceDocument($id, $tenant_id)) {
            return $this->fail("This journal belongs to {$source}. Cancel that document instead.");
        }
        if ($reason = self::lockedByStatus($row['status'] ?? '', 'cancelled')) {
            return $this->fail($reason);
        }
        if ($msg = PeriodLockService::guard($this->conn, $tenant_id, null, $id)) {
            return $this->fail($msg);
        }
        $stmt = $this->conn->prepare("UPDATE journal_entries SET status = 'cancelled' WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $tenant_id);
        if ($stmt->execute()) {
            $this->settings->log_audit('Cancelled draft journal', 'Journals', "{$row['code']} dated {$row['journal_date']} — {$row['description']}.");
            $this->settings->set_flashdata('success', 'Draft journal cancelled.');
            return json_encode(['status' => 'success']);
        }
        return $this->fail('The journal could not be cancelled.');
    }

    /* ------------------------------------------------------------------
     * Pure rules (unit-tested without a database)
     * ------------------------------------------------------------------ */

    /** 'draft', 'cancelled' or 'posted' — legacy rows with no status count as posted. */
    public static function statusKind(?string $status): string
    {
        $s = strtolower(trim((string)$status));
        if ($s === 'draft') {
            return 'draft';
        }
        if ($s === ApprovalService::PENDING) {
            return 'pending';
        }
        return in_array($s, ['cancelled', 'canceled', 'void', '2', '3'], true) ? 'cancelled' : 'posted';
    }

    /**
     * Why a journal in this state cannot be edited / deleted / cancelled, or null when it can.
     * Only drafts may change: a posted journal is part of the record and is corrected by reversing it.
     */
    public static function lockedByStatus(?string $status, string $verb = 'changed'): ?string
    {
        return match (self::statusKind($status)) {
            'draft' => null,
            'pending' => "This journal is waiting for approval and cannot be {$verb}. An approver can approve it or return it to draft.",
            'cancelled' => "A cancelled journal is kept on record and cannot be {$verb}.",
            default => "A posted journal cannot be {$verb}. Reverse it to cancel its effect, then post a corrected journal.",
        };
    }

    /**
     * The lines of a reversing journal: every line on the opposite side, same account and amount.
     * Lines are [account_id, side (1 debit / 2 credit), amount, description, tax_rate].
     */
    public static function reverseLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if ((int)($l['account_id'] ?? 0) === 0 || (float)($l['amount'] ?? 0) == 0.0) {
                continue;
            }
            $l['side'] = (int)$l['side'] === 1 ? 2 : 1;
            $out[] = $l;
        }
        return $out;
    }

    /**
     * One-line summary for the audit log: "Dr #12 100.00; Cr #40 100.00". Accepts form lines
     * ([account_id, debit, credit]) or stored lines ([account_id, side, amount]).
     */
    public static function describeLines(array $lines): string
    {
        $parts = [];
        foreach ($lines as $l) {
            if (isset($l['side'])) {
                $dr = (int)$l['side'] === 1;
                $amt = (float)$l['amount'];
            } else {
                $dr = (float)($l['debit'] ?? 0) > 0;
                $amt = $dr ? (float)$l['debit'] : (float)($l['credit'] ?? 0);
            }
            if ((int)($l['account_id'] ?? 0) === 0 || $amt == 0.0) {
                continue;
            }
            $parts[] = ($dr ? 'Dr' : 'Cr') . ' #' . (int)$l['account_id'] . ' ' . number_format($amt, 2, '.', '');
        }
        return $parts ? implode('; ', $parts) : 'no lines';
    }

    /** The saved lines of a journal, as describeLines() prints them. */
    private function describeStored(int $journalId): string
    {
        $side = LedgerService::SIDE_SQL;
        $stmt = $this->conn->prepare("SELECT ji.account_id, ji.amount, {$side} side
            FROM journal_items ji LEFT JOIN group_list g ON g.id = ji.group_id WHERE ji.journal_id = ? ORDER BY ji.account_id");
        $stmt->bind_param('i', $journalId);
        $stmt->execute();
        return self::describeLines($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    /** "invoice INV-0012" etc. when the journal was generated by another document. */
    public function sourceDocument(int $journalId, int $tenantId): ?string
    {
        foreach (self::SOURCES as $key => $meta) {
            $table = $meta['table'] ?? $key;
            $col = $meta['col'] ?? 'journal_id';
            $stmt = @$this->conn->prepare("SELECT `{$meta['ref']}` ref FROM `{$table}` WHERE `{$col}` = ? AND tenant_id = ? LIMIT 1");
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
                'currency' => (string)($post['currency'][$k] ?? ''),
                'fx_rate' => (string)($post['fx_rate'][$k] ?? ''),
            ];
        }
        return $lines;
    }

    private function nextCode(int $tenant_id): string
    {
        return SequenceService::journalCode($this->conn, $tenant_id);
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
