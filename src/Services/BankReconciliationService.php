<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * BankReconciliationService — reconcile a bank/cash GL account against a bank statement.
 *
 * Flow:
 *   1. User picks a bank account (account_list.type = 'Bank' or containing 'cash')
 *      and starts a reconciliation with the statement's opening/closing balance and date.
 *   2. Statement lines are imported (CSV) or entered by hand. Amount is signed:
 *      positive = money in, negative = money out.
 *   3. Each statement line is matched to a posted journal entry that touches the bank
 *      account (matched on journal_entries.id — a stable PK — so core tables are untouched).
 *   4. When the matched book movement agrees with the statement movement
 *      (closing - opening), the reconciliation can be finalized.
 *
 * Signed bank movement of a journal on the bank account:
 *   debit lines (side 1) increase the bank balance (+), credit lines (side 2) decrease it (-).
 */
class BankReconciliationService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** account_list types that are bank/cash accounts, mirroring LedgerService::classify. */
    private function bankAccountWhere(string $alias = 'a'): string
    {
        return "(LOWER({$alias}.`type`) = 'bank' OR LOWER({$alias}.`type`) LIKE '%cash%')";
    }

    /**
     * The amount a statement is compared with. A company-currency account compares base amounts. A foreign-currency
     * account's statement is in its own currency, so it compares the foreign amounts recorded on the lines; lines
     * with no foreign amount (a revaluation, or an entry made before the account was foreign) don't move it.
     */
    private function movementAmountSql(int $accountId): string
    {
        $foreign = (new FxService($this->conn, $this->settings))->bankCurrency($accountId) !== null;
        return $foreign ? "(CASE WHEN ji.currency IS NOT NULL AND ji.currency <> '' THEN COALESCE(ji.amount_fc, 0) ELSE 0 END)" : 'ji.amount';
    }

    /**
     * Signed book movement of one journal entry on a given bank account.
     * Positive = net debit (money in), negative = net credit (money out).
     */
    private function journalBankMovement(int $journal_id, int $account_id, int $tenant_id): float
    {
        $side = LedgerService::SIDE_SQL;
        $amt = $this->movementAmountSql($account_id);
        $sql = "SELECT COALESCE(SUM(CASE WHEN {$side} = 1 THEN {$amt} ELSE -{$amt} END),0) mv
                FROM journal_items ji
                INNER JOIN journal_entries j ON j.id = ji.journal_id
                LEFT JOIN group_list g ON g.id = ji.group_id
                WHERE ji.journal_id = ? AND ji.account_id = ? AND j.tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('iii', $journal_id, $account_id, $tenant_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        return round((float)($row['mv'] ?? 0), 2);
    }

    /* ===================================================================
     *  Reconciliation sessions
     * =================================================================== */

    public function startReconciliation(array $post): string
    {
        $account_id = $post['account_id'] ?? null;
        $statement_date = $post['statement_date'] ?? null;
        $opening_balance = $post['opening_balance'] ?? null;
        $closing_balance = $post['closing_balance'] ?? null;
        $notes = $post['notes'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $account_id = isset($account_id) ? (int)$account_id : 0;
        $statement_date = !empty($statement_date) ? $this->conn->real_escape_string((string)$statement_date) : date('Y-m-d');
        $opening_balance = isset($opening_balance) ? (float)$opening_balance : 0.0;
        $closing_balance = isset($closing_balance) ? (float)$closing_balance : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';

        // Validate it is actually a bank account.
        $acc = $this->conn->query("SELECT id FROM `account_list` WHERE id = '{$account_id}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 AND " . $this->bankAccountWhere());
        if (!$acc || $acc->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Please select a valid bank or cash account.']);
        }

        $this->conn->query("INSERT INTO `bank_reconciliations` SET
            `tenant_id` = '{$tenant_id}',
            `account_id` = '{$account_id}',
            `statement_date` = '{$statement_date}',
            `opening_balance` = '{$opening_balance}',
            `closing_balance` = '{$closing_balance}',
            `status` = 'in_progress',
            `notes` = '{$notes}',
            `created_by` = '{$user_id}'");
        $rec_id = $this->conn->insert_id;

        if (!$rec_id) {
            return \App\Support\Db::fail($this->conn, 'Could not start reconciliation.');
        }
        $this->settings->set_flashdata('success', ' Reconciliation started.');
        return json_encode(['status' => 'success', 'id' => (int)$rec_id]);
    }

    public function updateReconciliation(array $post): string
    {
        $id = $post['id'] ?? null;
        $statement_date = $post['statement_date'] ?? null;
        $opening_balance = $post['opening_balance'] ?? null;
        $closing_balance = $post['closing_balance'] ?? null;
        $notes = $post['notes'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($id) ? (int)$id : 0;

        $statement_date = !empty($statement_date) ? $this->conn->real_escape_string((string)$statement_date) : date('Y-m-d');
        $opening_balance = isset($opening_balance) ? (float)$opening_balance : 0.0;
        $closing_balance = isset($closing_balance) ? (float)$closing_balance : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';

        $upd = $this->conn->query("UPDATE `bank_reconciliations` SET
            `statement_date` = '{$statement_date}',
            `opening_balance` = '{$opening_balance}',
            `closing_balance` = '{$closing_balance}',
            `notes` = '{$notes}'
            WHERE id = '{$id}' AND tenant_id = '{$tenant_id}' AND status = 'in_progress'");
        if ($upd) {
            $this->settings->set_flashdata('success', ' Reconciliation updated.');
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Could not update (it may already be finalized).']);
    }

    public function deleteReconciliation(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        // CASCADE removes statement lines + matches.
        $del = $this->conn->query("DELETE FROM `bank_reconciliations` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->set_flashdata('success', ' Reconciliation deleted.');
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    /* ===================================================================
     *  Statement lines
     * =================================================================== */

    /**
     * Add statement lines in bulk (from CSV import or the manual grid).
     * Expects parallel arrays: line_date[], description[], reference[], amount[].
     */
    public function addStatementLines(array $post): string
    {
        $reconciliation_id = $post['reconciliation_id'] ?? null;
        $line_date = $post['line_date'] ?? null;
        $amount = $post['amount'] ?? null;
        $description = $post['description'] ?? null;
        $reference = $post['reference'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $rec_id = isset($reconciliation_id) ? (int)$reconciliation_id : 0;

        $rec = $this->conn->query("SELECT id FROM `bank_reconciliations` WHERE id = '{$rec_id}' AND tenant_id = '{$tenant_id}' AND status = 'in_progress'");
        if (!$rec || $rec->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Reconciliation not found or already finalized.']);
        }

        if (!isset($line_date) || !is_array($line_date)) {
            return json_encode(['status' => 'failed', 'msg' => 'No statement lines provided.']);
        }

        $added = 0;
        foreach ($line_date as $k => $d) {
            $ld = $this->conn->real_escape_string((string)$d);
            $amt = isset($amount[$k]) ? (float)$amount[$k] : 0.0;
            if ($ld === '' || $amt == 0.0) {
                continue;
            }
            $desc = isset($description[$k]) ? $this->conn->real_escape_string((string)$description[$k]) : '';
            $ref = isset($reference[$k]) ? $this->conn->real_escape_string((string)$reference[$k]) : '';
            $this->conn->query("INSERT INTO `bank_statement_lines` SET
                `reconciliation_id` = '{$rec_id}',
                `tenant_id` = '{$tenant_id}',
                `line_date` = '{$ld}',
                `description` = '{$desc}',
                `reference` = '{$ref}',
                `amount` = '{$amt}'");
            $added++;
        }

        $this->settings->set_flashdata('success', " {$added} statement line(s) added.");
        return json_encode(['status' => 'success', 'added' => $added]);
    }

    public function deleteStatementLine(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        // Unmatch first (clears any match row), then delete the line.
        $line = $this->conn->query("SELECT reconciliation_id, matched_journal_id FROM `bank_statement_lines` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($line && $line->num_rows) {
            $this->conn->query("DELETE FROM `bank_reconciliation_matches` WHERE statement_line_id = '{$id}' AND tenant_id = '{$tenant_id}'");
        }
        $del = $this->conn->query("DELETE FROM `bank_statement_lines` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            return json_encode(['status' => 'success']);
        }
        return \App\Support\Db::fail($this->conn);
    }

    /* ===================================================================
     *  Matching
     * =================================================================== */

    /**
     * Confirm a match between a statement line and a journal entry on the bank account.
     * Records the signed book movement, flags the statement line matched.
     */
    public function matchLine(array $post): string
    {
        $statement_line_id = $post['statement_line_id'] ?? null;
        $journal_id = $post['journal_id'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $line_id = isset($statement_line_id) ? (int)$statement_line_id : 0;
        $journal_id = isset($journal_id) ? (int)$journal_id : 0;

        $line = $this->conn->query("SELECT sl.*, r.account_id, r.status rec_status
            FROM `bank_statement_lines` sl
            INNER JOIN `bank_reconciliations` r ON r.id = sl.reconciliation_id
            WHERE sl.id = '{$line_id}' AND sl.tenant_id = '{$tenant_id}'");
        if (!$line || $line->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Statement line not found.']);
        }
        $line = $line->fetch_assoc();
        if ($line['rec_status'] !== 'in_progress') {
            return json_encode(['status' => 'failed', 'msg' => 'This reconciliation is already finalized.']);
        }
        $rec_id = (int)$line['reconciliation_id'];
        $account_id = (int)$line['account_id'];

        $book_mv = $this->journalBankMovement($journal_id, $account_id, (int)$tenant_id);
        if ($book_mv == 0.0) {
            return json_encode(['status' => 'failed', 'msg' => 'That journal entry does not move this bank account.']);
        }

        // Guard: this journal is not already matched in this reconciliation.
        $dup = $this->conn->query("SELECT id FROM `bank_reconciliation_matches` WHERE reconciliation_id = '{$rec_id}' AND journal_id = '{$journal_id}' AND tenant_id = '{$tenant_id}'");
        if ($dup && $dup->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'That journal entry is already matched in this reconciliation.']);
        }

        // Clear any previous match on this statement line, then record the new one.
        $this->conn->query("DELETE FROM `bank_reconciliation_matches` WHERE statement_line_id = '{$line_id}' AND tenant_id = '{$tenant_id}'");
        $this->conn->query("INSERT INTO `bank_reconciliation_matches` SET
            `reconciliation_id` = '{$rec_id}',
            `tenant_id` = '{$tenant_id}',
            `statement_line_id` = '{$line_id}',
            `journal_id` = '{$journal_id}',
            `account_id` = '{$account_id}',
            `book_amount` = '{$book_mv}',
            `matched_by` = '{$user_id}'");
        $this->conn->query("UPDATE `bank_statement_lines` SET `status` = 'matched', `matched_journal_id` = '{$journal_id}' WHERE id = '{$line_id}'");

        return json_encode(['status' => 'success', 'book_amount' => $book_mv]);
    }

    public function unmatchLine(int $statement_line_id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $this->conn->query("DELETE FROM `bank_reconciliation_matches` WHERE statement_line_id = '{$statement_line_id}' AND tenant_id = '{$tenant_id}'");
        $this->conn->query("UPDATE `bank_statement_lines` SET `status` = 'unmatched', `matched_journal_id` = NULL WHERE id = '{$statement_line_id}' AND tenant_id = '{$tenant_id}'");
        return json_encode(['status' => 'success']);
    }

    public function finalize(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $summary = $this->summary($id, (int)$tenant_id);
        if (!$summary) {
            return json_encode(['status' => 'failed', 'msg' => 'Reconciliation not found.']);
        }
        if (abs($summary['difference']) > 0.009) {
            return json_encode(['status' => 'failed', 'msg' => 'Cannot finalize: the statement and book movements still differ by ' . number_format($summary['difference'], 2) . '.']);
        }
        $this->conn->query("UPDATE `bank_reconciliations` SET `status` = 'reconciled', `reconciled_at` = NOW() WHERE id = '{$id}' AND tenant_id = '{$tenant_id}' AND status = 'in_progress'");
        $this->settings->set_flashdata('success', ' Reconciliation completed and locked.');
        return json_encode(['status' => 'success']);
    }

    public function reopen(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $this->conn->query("UPDATE `bank_reconciliations` SET `status` = 'in_progress', `reconciled_at` = NULL WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        $this->settings->set_flashdata('success', ' Reconciliation reopened.');
        return json_encode(['status' => 'success']);
    }

    /* ===================================================================
     *  Read helpers (used by the UI)
     * =================================================================== */

    /**
     * Totals for a reconciliation: matched book movement vs the statement movement,
     * and the remaining difference. Difference == 0 means it balances.
     *
     * @return array<string,mixed>|null
     */
    public function summary(int $id, ?int $tenant_id = null): ?array
    {
        $tenant_id ??= (int)$this->settings->active_tenant_id();
        $rec = $this->conn->query("SELECT * FROM `bank_reconciliations` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$rec || $rec->num_rows == 0) {
            return null;
        }
        $rec = $rec->fetch_assoc();

        $m = $this->conn->query("SELECT COALESCE(SUM(book_amount),0) matched, COUNT(*) n
            FROM `bank_reconciliation_matches` WHERE reconciliation_id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        $matched = round((float)$m['matched'], 2);

        $statement_movement = round((float)$rec['closing_balance'] - (float)$rec['opening_balance'], 2);
        $difference = round($statement_movement - $matched, 2);

        $unm = $this->conn->query("SELECT COUNT(*) n FROM `bank_statement_lines` WHERE reconciliation_id = '{$id}' AND status = 'unmatched'")->fetch_assoc();

        return [
            'reconciliation'     => $rec,
            'matched_movement'   => $matched,
            'matched_count'      => (int)$m['n'],
            'statement_movement' => $statement_movement,
            'difference'         => $difference,
            'unmatched_lines'    => (int)$unm['n'],
            'balanced'           => abs($difference) <= 0.009,
            'currency'           => $cur = (new FxService($this->conn, $this->settings))->bankCurrency((int)$rec['account_id']),
            'untracked_base'     => $cur !== null ? (new FxService($this->conn, $this->settings))->untrackedBase((int)$rec['account_id'], (string)$rec['statement_date']) : 0.0,
        ];
    }

    /**
     * Posted journal entries that touch the bank account and are not yet matched in
     * this reconciliation — candidates to match statement lines against.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unmatchedBookEntries(int $rec_id, int $account_id, ?string $up_to = null): array
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $side = LedgerService::SIDE_SQL;
        $posted = LedgerService::POSTED_SQL;
        $dateClause = $up_to ? "AND j.journal_date <= ?" : '';
        $amt = $this->movementAmountSql($account_id);

        $sql = "SELECT j.id journal_id, j.code, j.journal_date, j.description,
                    COALESCE(SUM(CASE WHEN {$side} = 1 THEN {$amt} ELSE -{$amt} END),0) movement
                FROM journal_items ji
                INNER JOIN journal_entries j ON j.id = ji.journal_id
                LEFT JOIN group_list g ON g.id = ji.group_id
                WHERE ji.account_id = ? AND j.tenant_id = ? AND {$posted}
                    {$dateClause}
                    AND j.id NOT IN (SELECT journal_id FROM `bank_reconciliation_matches` WHERE reconciliation_id = ?)
                GROUP BY j.id, j.code, j.journal_date, j.description
                HAVING movement <> 0
                ORDER BY j.journal_date DESC, j.id DESC";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($up_to) {
            $stmt->bind_param('iisi', $account_id, $tenant_id, $up_to, $rec_id);
        } else {
            $stmt->bind_param('iii', $account_id, $tenant_id, $rec_id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** All bank/cash GL accounts for the tenant. */
    public function bankAccounts(): array
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $rows = [];
        $res = $this->conn->query("SELECT id, code, name, type FROM `account_list`
            WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND " . $this->bankAccountWhere() . "
            ORDER BY name ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        return $rows;
    }
}
