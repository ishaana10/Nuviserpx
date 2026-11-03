<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Pays approved salaries. Payroll posts net pay to Net Salaries Payable (see PayrollPostingService), so a run that
 * has been processed and approved is "owed, not yet paid". Paying it debits Net Salaries Payable and credits the
 * bank, and marks the pay slips paid. A paid payroll cannot be edited or deleted until the payment is reversed.
 */
class PayrollPaymentService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: validate a salary payment. Returns error messages. */
    public static function validate(array $p, float $total): array
    {
        $errors = [];
        $refs = $p['refs'] ?? [];
        if (!is_array($refs) || !array_filter($refs, fn ($r) => trim((string)$r) !== '')) {
            $errors[] = 'Choose at least one payroll run to pay.';
        } elseif ($total <= 0) {
            $errors[] = 'There is nothing left to pay on the chosen runs.';
        }
        $d = (string)($p['pay_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            $errors[] = 'Enter the payment date.';
        }
        if ((int)($p['bank_account_id'] ?? 0) <= 0) {
            $errors[] = 'Choose the bank account the salaries are paid from.';
        }
        return $errors;
    }

    /** Pure: balanced journal lines [account_id, debit, credit, description]. */
    public static function lines(int $payableAcc, int $bankAcc, float $amount, string $label): array
    {
        $amount = round($amount, 2);
        return [
            ['account_id' => $payableAcc, 'debit' => $amount, 'credit' => 0.0, 'description' => $label],
            ['account_id' => $bankAcc, 'debit' => 0.0, 'credit' => $amount, 'description' => $label],
        ];
    }

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** Approved payroll still to be paid, one row per run. */
    public function unpaidRuns(): array
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT payroll_run_ref, MAX(salary_month) salary_month, MAX(pay_date) pay_date, COUNT(*) employees, SUM(net_salary) net
            FROM payroll_list WHERE tenant_id = ? AND status = 'posted' AND payment_status = 'unpaid'
            GROUP BY payroll_run_ref ORDER BY MAX(pay_date), payroll_run_ref");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function payableAccount(): int
    {
        $name = PayrollPostingService::ACCOUNTS['net'][0];
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $q->bind_param('is', $t, $name);
        $q->execute();
        return (int)($q->get_result()->fetch_assoc()['id'] ?? 0);
    }

    public function pay(array $p): string
    {
        $t = $this->tenantId();
        $lock = "payroll_pay_{$t}";
        $g = $this->conn->prepare("SELECT GET_LOCK(?, 5) ok");
        $g->bind_param('s', $lock);
        $g->execute();
        if ((int)($g->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) {
            return $this->fail('Another salary payment is being recorded. Please try again.');
        }
        try {
            return $this->payLocked($p, $t);
        } finally {
            $r = $this->conn->prepare("SELECT RELEASE_LOCK(?)");
            $r->bind_param('s', $lock);
            $r->execute();
            $r->get_result();
        }
    }

    private function payLocked(array $p, int $t): string
    {
        $refs = array_values(array_unique(array_filter(array_map(fn ($r) => mb_substr(trim((string)$r), 0, 100), (array)($p['refs'] ?? [])), fn ($r) => $r !== '')));
        $rows = [];
        if ($refs) {
            $marks = implode(',', array_fill(0, count($refs), '?'));
            $q = $this->conn->prepare("SELECT id, net_salary FROM payroll_list WHERE tenant_id = ? AND status = 'posted' AND payment_status = 'unpaid' AND payroll_run_ref IN ({$marks})");
            $q->bind_param('i' . str_repeat('s', count($refs)), $t, ...$refs);
            $q->execute();
            $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $total = round(array_sum(array_map(fn ($r) => (float)$r['net_salary'], $rows)), 2);
        $errors = self::validate(['refs' => $refs] + $p, $total);
        if ($errors) {
            return $this->fail(implode(' ', $errors));
        }
        $bank = (int)$p['bank_account_id'];
        $b = $this->conn->prepare("SELECT id FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank'");
        $b->bind_param('ii', $bank, $t);
        $b->execute();
        if (!$b->get_result()->fetch_assoc()) {
            return $this->fail('Choose one of this company\'s bank accounts.');
        }
        if ((new FxService($this->conn, $this->settings))->bankCurrency($bank) !== null) {
            return $this->fail('Payroll is paid in the company currency. Choose a bank account held in that currency.');
        }
        $payable = $this->payableAccount();
        if ($payable === 0) {
            return $this->fail('The Net Salaries Payable account does not exist yet. Process payroll first.');
        }
        $date = (string)$p['pay_date'];
        $ref = mb_substr(trim((string)($p['reference'] ?? '')), 0, 100);
        $user = (int)$this->settings->userdata('id');
        $ids = array_map('intval', array_column($rows, 'id'));
        $count = count($ids);
        $amt = number_format($total, 2, '.', '');

        // Reserve the payment row first so a failure after posting can be undone, then post the journal.
        $ins = $this->conn->prepare("INSERT INTO payroll_payments (tenant_id, pay_date, amount, employees, bank_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('issiisi', $t, $date, $amt, $count, $bank, $ref, $user);
        if (!$ins->execute()) {
            return $this->fail('The payment could not be saved.');
        }
        $pid = (int)$this->conn->insert_id;
        $label = 'Salaries paid' . ($ref !== '' ? " ({$ref})" : '');
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, $label, self::lines($payable, $bank, $total, 'Net salaries'), 'posted'), true);
        if (($res['status'] ?? '') !== 'success') {
            $this->conn->query("DELETE FROM payroll_payments WHERE id = {$pid}");
            return $this->fail($res['msg'] ?? 'The payment could not be posted.');
        }
        $jid = (int)$res['id'];
        $in = implode(',', $ids);
        $this->conn->query("UPDATE payroll_list SET payment_status = 'paid', payment_id = {$pid} WHERE tenant_id = {$t} AND id IN ({$in}) AND status = 'posted' AND payment_status = 'unpaid'");
        if ($this->conn->affected_rows !== $count) {
            // Something changed under us: undo the whole payment.
            $this->conn->query("UPDATE payroll_list SET payment_status = 'unpaid', payment_id = NULL WHERE payment_id = {$pid}");
            (new JournalService($this->conn, $this->settings))->reverseJournal($jid, $date, true);
            $this->conn->query("UPDATE payroll_payments SET status = 'reversed', journal_id = {$jid} WHERE id = {$pid}");
            return $this->fail('The payroll changed while the payment was being recorded, so it was undone. Please try again.');
        }
        $this->conn->query("UPDATE payroll_payments SET journal_id = {$jid} WHERE id = {$pid}");
        $this->settings->log_audit('Paid Salaries', 'Payroll', "{$amt} to {$count} employee(s) on {$date}" . ($ref !== '' ? ", ref {$ref}" : '') . '.');
        return json_encode(['status' => 'success', 'msg' => "Salaries of {$amt} paid to {$count} employee(s).", 'id' => $pid]);
    }

    public function reverse(int $id): string
    {
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT * FROM payroll_payments WHERE id = ? AND tenant_id = ?");
        $q->bind_param('ii', $id, $t);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if (!$row || $row['status'] !== 'posted' || empty($row['journal_id'])) {
            return $this->fail('Only a posted salary payment can be reversed.');
        }
        $date = max(date('Y-m-d'), (string)$row['pay_date']);
        $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$row['journal_id'], $date, true), true);
        if (($res['status'] ?? '') !== 'success') {
            return $this->fail($res['msg'] ?? 'The payment could not be reversed.');
        }
        $this->conn->query("UPDATE payroll_list SET payment_status = 'unpaid', payment_id = NULL WHERE tenant_id = {$t} AND payment_id = " . (int)$id);
        $this->conn->query("UPDATE payroll_payments SET status = 'reversed' WHERE id = " . (int)$id);
        $this->settings->log_audit('Reversed Salary Payment', 'Payroll', "Salary payment #{$id}.");
        return json_encode(['status' => 'success', 'msg' => 'Payment reversed. The salaries are owed again.']);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
