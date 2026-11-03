<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Pays what payroll owes: pension contributions, income tax withheld, employer levies and other deductions.
 * Payroll credits these liability accounts (see PayrollPostingService); a remittance debits the liability and
 * credits the bank, so the balance owed falls as payments are made.
 */
class PayrollRemittanceService
{
    /** key => [label, PayrollPostingService::ACCOUNTS key] */
    public const LIABILITIES = [
        'pension' => ['Pension contributions', 'pension'],
        'tax'     => ['Income tax withheld', 'tax'],
        'levies'  => ['Employer levies', 'levies'],
        'other'   => ['Other payroll deductions', 'other'],
    ];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: validate a remittance against the amount currently owed. Returns error messages. */
    public static function validate(array $p, float $outstanding): array
    {
        $errors = [];
        if (!isset(self::LIABILITIES[(string)($p['liability'] ?? '')])) {
            $errors[] = 'Choose what you are paying.';
        }
        $amount = $p['amount'] ?? null;
        if (!is_numeric($amount) || round((float)$amount, 2) <= 0) {
            $errors[] = 'Enter an amount above zero.';
        } elseif (round((float)$amount, 2) > round($outstanding, 2) + 0.004) {
            $errors[] = 'That is more than is owed (' . number_format($outstanding, 2) . ').';
        }
        $d = (string)($p['remit_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            $errors[] = 'Enter the payment date.';
        }
        if ((int)($p['bank_account_id'] ?? 0) <= 0) {
            $errors[] = 'Choose the bank account the payment came from.';
        }
        return $errors;
    }

    /** Pure: balanced journal lines [account_id, debit, credit, description]. */
    public static function lines(int $liabilityAcc, int $bankAcc, float $amount, string $label): array
    {
        $amount = round($amount, 2);
        return [
            ['account_id' => $liabilityAcc, 'debit' => $amount, 'credit' => 0.0, 'description' => $label],
            ['account_id' => $bankAcc, 'debit' => 0.0, 'credit' => $amount, 'description' => $label],
        ];
    }

    /** Liability account id for a key, or 0 if payroll has not created it yet. */
    private function accountId(string $key): int
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $name = PayrollPostingService::ACCOUNTS[self::LIABILITIES[$key][1]][0];
        $q = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $q->bind_param('is', $tenant, $name);
        $q->execute();
        return (int)($q->get_result()->fetch_assoc()['id'] ?? 0);
    }

    /** Amount still to be paid on a liability: credits less debits on posted journals, less payments awaiting approval. */
    public function outstanding(string $key): float
    {
        $acc = $this->accountId($key);
        if ($acc === 0) {
            return 0.0;
        }
        $tenant = (int)$this->settings->active_tenant_id();
        $side = LedgerService::SIDE_SQL;
        $posted = LedgerService::POSTED_SQL;
        $q = $this->conn->prepare("SELECT COALESCE(SUM(CASE WHEN {$side} = 2 THEN ji.amount ELSE -ji.amount END), 0) bal
            FROM journal_items ji
            JOIN journal_entries j ON j.id = ji.journal_id AND j.tenant_id = ? AND {$posted}
            LEFT JOIN group_list g ON g.id = ji.group_id
            WHERE ji.account_id = ?");
        $q->bind_param('ii', $tenant, $acc);
        $q->execute();
        $posted = round((float)($q->get_result()->fetch_assoc()['bal'] ?? 0), 2);

        // Payments waiting for approval are already spoken for.
        $p = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) a FROM payroll_remittances WHERE tenant_id = ? AND liability = ? AND status = 'pending_approval'");
        $p->bind_param('is', $tenant, $key);
        $p->execute();
        return round($posted - (float)($p->get_result()->fetch_assoc()['a'] ?? 0), 2);
    }

    /** All four balances: key => [label, owed]. */
    public function balances(): array
    {
        $out = [];
        foreach (self::LIABILITIES as $k => [$label]) {
            $out[$k] = ['label' => $label, 'owed' => $this->outstanding($k)];
        }
        return $out;
    }

    public function remit(array $p): string
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $key = (string)($p['liability'] ?? '');
        if (!isset(self::LIABILITIES[$key])) {
            return $this->fail('Choose what you are paying.');
        }
        $lock = "payroll_remit_{$tenant}_{$key}";
        $g = $this->conn->prepare("SELECT GET_LOCK(?, 5) ok");
        $g->bind_param('s', $lock);
        $g->execute();
        if ((int)($g->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) {
            return $this->fail('Another payment is being recorded. Please try again.');
        }
        try {
            return $this->remitLocked($p, $key, $tenant);
        } finally {
            $r = $this->conn->prepare("SELECT RELEASE_LOCK(?)");
            $r->bind_param('s', $lock);
            $r->execute();
            $r->get_result();
        }
    }

    private function remitLocked(array $p, string $key, int $tenant): string
    {
        $errors = self::validate($p, $this->outstanding($key));
        if ($errors) {
            return $this->fail(implode(' ', $errors));
        }
        $bank = (int)$p['bank_account_id'];
        $b = $this->conn->prepare("SELECT id FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank'");
        $b->bind_param('ii', $bank, $tenant);
        $b->execute();
        if (!$b->get_result()->fetch_assoc()) {
            return $this->fail('Choose one of this company\'s bank accounts.');
        }
        if ((new FxService($this->conn, $this->settings))->bankCurrency($bank) !== null) {
            return $this->fail('Payroll is paid in the company currency. Choose a bank account held in that currency.');
        }
        $liabilityAcc = $this->accountId($key);
        $amount = round((float)$p['amount'], 2);
        $date = (string)$p['remit_date'];
        $ref = mb_substr(trim((string)($p['reference'] ?? '')), 0, 100);
        $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 500);
        $label = self::LIABILITIES[$key][0];
        $user = (int)$this->settings->userdata('id');

        // Payments at or above the company's threshold wait for a second person.
        $policy = (new ApprovalService($this->conn, $this->settings))->policy(ApprovalService::DOC_PAYROLL_REMITTANCE);
        $held = ApprovalService::requiresApproval($policy['enabled'], $policy['threshold'], $amount);
        $status = $held ? 'pending_approval' : 'posted';

        // Reserve the row first so a failure after posting can be undone, then post the journal.
        $amt = number_format($amount, 2, '.', '');
        $ins = $this->conn->prepare("INSERT INTO payroll_remittances (tenant_id, liability, remit_date, amount, bank_account_id, reference, note, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isssissis', $tenant, $key, $date, $amt, $bank, $ref, $note, $user, $status);
        if (!$ins->execute()) {
            return $this->fail('The payment could not be saved.');
        }
        $rid = (int)$this->conn->insert_id;

        $desc = "Payroll remittance: {$label}" . ($ref !== '' ? " ({$ref})" : '');
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, $desc, self::lines($liabilityAcc, $bank, $amount, $label), $status), true);
        if (($res['status'] ?? '') !== 'success') {
            $this->conn->query("DELETE FROM payroll_remittances WHERE id = {$rid}");
            return $this->fail($res['msg'] ?? 'The payment could not be posted.');
        }
        $jid = (int)$res['id'];
        $this->conn->query("UPDATE payroll_remittances SET journal_id = {$jid} WHERE id = {$rid}");
        $this->settings->log_audit('Payroll Remittance', 'Payroll', "{$label}: {$amt} on {$date}" . ($ref !== '' ? ", ref {$ref}" : '') . '.');
        $msg = $held ? "Payment of {$amt} for {$label} is waiting for approval." : "Payment of {$amt} recorded for {$label}.";
        return json_encode(['status' => 'success', 'msg' => $msg, 'id' => $rid]);
    }

    public function reverse(int $id): string
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT * FROM payroll_remittances WHERE id = ? AND tenant_id = ?");
        $q->bind_param('ii', $id, $tenant);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if (!$row || $row['status'] !== 'posted' || empty($row['journal_id'])) {
            return $this->fail('Only a posted payment can be reversed.');
        }
        $date = max(date('Y-m-d'), (string)$row['remit_date']);
        $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$row['journal_id'], $date, true), true);
        if (($res['status'] ?? '') !== 'success') {
            return $this->fail($res['msg'] ?? 'The payment could not be reversed.');
        }
        $this->conn->query("UPDATE payroll_remittances SET status = 'reversed' WHERE id = " . (int)$id);
        $this->settings->log_audit('Reversed Payroll Remittance', 'Payroll', "Remittance #{$id}.");
        return json_encode(['status' => 'success', 'msg' => 'Payment reversed. The amount is owed again.']);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
