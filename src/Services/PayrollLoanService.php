<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Staff loans and advances recovered through payroll.
 *
 * Issuing a loan books Dr Staff Loans Receivable, Cr Bank. Each payroll then takes the instalment (never more than
 * the balance, and never more than leaves the employee with net pay) as a deduction, and the payroll journal credits
 * Staff Loans Receivable, so the receivable falls as it is recovered. Voiding or returning a payroll puts the
 * instalment back on the loan.
 */
class PayrollLoanService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: the instalment due now: the agreed instalment, or what is left if that is less. */
    public static function recovery(float $installment, float $balance): float
    {
        return round(max(0.0, min($installment, $balance)), 2);
    }

    /**
     * Pure: lower loan recoveries so deductions never push net pay below zero. Loan lines are reduced last-in first.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>> lines with reduced (or removed) loan amounts
     */
    public static function capToShortfall(array $lines, float $shortfall): array
    {
        $shortfall = round($shortfall, 2);
        for ($i = count($lines) - 1; $i >= 0 && $shortfall > 0; $i--) {
            if ((int)($lines[$i]['loan_id'] ?? 0) <= 0) {
                continue;
            }
            $cut = min((float)$lines[$i]['amount'], $shortfall);
            $lines[$i]['amount'] = round((float)$lines[$i]['amount'] - $cut, 2);
            $shortfall = round($shortfall - $cut, 2);
        }
        return array_values(array_filter($lines, fn ($l) => (int)($l['loan_id'] ?? 0) <= 0 || (float)$l['amount'] > 0));
    }

    /** Pure: validate a new loan. Returns error messages. */
    public static function validate(array $p): array
    {
        $err = [];
        if (!in_array($p['kind'] ?? '', ['loan', 'advance'], true)) $err[] = 'Choose loan or advance.';
        if (!is_numeric($p['principal'] ?? null) || (float)$p['principal'] <= 0) $err[] = 'Enter the amount lent.';
        if (!is_numeric($p['installment'] ?? null) || (float)$p['installment'] <= 0) $err[] = 'Enter the amount to recover from each payroll.';
        elseif (is_numeric($p['principal'] ?? null) && (float)$p['installment'] > (float)$p['principal']) $err[] = 'The instalment cannot be more than the amount lent.';
        $d = (string)($p['start_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) $err[] = 'Enter the date the loan is paid out.';
        return $err;
    }

    /** Pure: the journal lines [account_id, debit, credit, description] for paying a loan out. */
    public static function issueLines(int $loansAcc, int $bankAcc, float $amount, string $label): array
    {
        return [[$loansAcc, $amount, 0, $label], [$bankAcc, 0, $amount, $label]];
    }

    /** Active loans with instalments due in a payroll ending $periodEnd, as payroll deduction lines. */
    public function dueFor(int $employeeId, string $periodEnd): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT id, kind, description, installment, balance FROM employee_loans
            WHERE tenant_id = ? AND employee_id = ? AND status = 'active' AND balance > 0 AND start_date <= ? ORDER BY id");
        if (!$q) {
            return [];
        }
        $q->bind_param('iis', $t, $employeeId, $periodEnd);
        $q->execute();
        $out = [];
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $amt = self::recovery((float)$r['installment'], (float)$r['balance']);
            if ($amt <= 0) {
                continue;
            }
            $out[] = [
                'component_id' => 0, 'loan_id' => (int)$r['id'], 'code' => 'LOAN',
                'name' => ($r['kind'] === 'advance' ? 'Advance' : 'Loan') . ' recovery' . ($r['description'] ? ': ' . $r['description'] : ''),
                'type' => 'deduction', 'is_taxable' => 0, 'is_fnpf_subject' => 0, 'amount' => $amt,
            ];
        }
        return $out;
    }

    /** Take the recoveries on a saved payroll off the loans. Call inside the payroll's transaction. */
    public function apply(int $payrollId, array $lines): void
    {
        $t = (int)$this->settings->active_tenant_id();
        foreach ($lines as $l) {
            $loan = (int)($l['loan_id'] ?? 0);
            $amt = round((float)$l['amount'], 2);
            if ($loan <= 0 || $amt <= 0) {
                continue;
            }
            $q = $this->conn->prepare("SELECT balance FROM employee_loans WHERE id = ? AND tenant_id = ? AND status = 'active' FOR UPDATE");
            $q->bind_param('ii', $loan, $t);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            if (!$row || round((float)$row['balance'], 2) + 0.005 < $amt) {
                throw new \RuntimeException('A loan balance changed while processing. Please try again.');
            }
            $a = (string)$amt;
            $r = $this->conn->prepare("INSERT INTO loan_repayments (tenant_id, loan_id, payroll_id, amount) VALUES (?,?,?,?)");
            $r->bind_param('iiis', $t, $loan, $payrollId, $a);
            if (!$r->execute()) {
                throw new \RuntimeException('The loan recovery could not be recorded.');
            }
            $left = round((float)$row['balance'] - $amt, 2);
            $status = $left <= 0.004 ? 'paid' : 'active';
            $left = (string)max(0.0, $left);
            $u = $this->conn->prepare("UPDATE employee_loans SET balance = ?, status = ? WHERE id = ? AND tenant_id = ?");
            $u->bind_param('ssii', $left, $status, $loan, $t);
            $u->execute();
        }
    }

    /** Put a payroll's recoveries back on the loans (payroll voided, deleted or returned). */
    public function release(int $payrollId): void
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT loan_id, amount FROM loan_repayments WHERE payroll_id = ? AND tenant_id = ?");
        $q->bind_param('ii', $payrollId, $t);
        $q->execute();
        $u = $this->conn->prepare("UPDATE employee_loans SET balance = balance + ?, status = IF(status = 'paid', 'active', status) WHERE id = ? AND tenant_id = ? AND status <> 'cancelled'");
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $amt = (string)$r['amount']; $loan = (int)$r['loan_id'];
            $u->bind_param('sii', $amt, $loan, $t);
            $u->execute();
        }
        $d = $this->conn->prepare("DELETE FROM loan_repayments WHERE payroll_id = ? AND tenant_id = ?");
        $d->bind_param('ii', $payrollId, $t);
        $d->execute();
    }

    public function all(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT l.*, CONCAT(e.firstname, ' ', e.lastname) emp_name, e.employee_code
            FROM employee_loans l JOIN employee_list e ON e.id = l.employee_id AND e.tenant_id = l.tenant_id
            WHERE l.tenant_id = ? ORDER BY l.id DESC");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function issue(array $p): string
    {
        $t = (int)$this->settings->active_tenant_id();
        if ($errors = self::validate($p)) {
            return $this->fail(implode(' ', $errors));
        }
        $emp = (int)($p['employee_id'] ?? 0);
        $bank = (int)($p['bank_account_id'] ?? 0);
        $e = $this->conn->prepare("SELECT id FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $e->bind_param('ii', $emp, $t);
        $e->execute();
        if (!$e->get_result()->fetch_assoc()) {
            return $this->fail('Choose an employee from this company.');
        }
        $b = $this->conn->prepare("SELECT id FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank'");
        $b->bind_param('ii', $bank, $t);
        $b->execute();
        if (!$b->get_result()->fetch_assoc()) {
            return $this->fail('Choose one of this company\'s bank accounts.');
        }
        if ((new FxService($this->conn, $this->settings))->bankCurrency($bank) !== null) {
            return $this->fail('Loans are paid in the company currency. Choose a bank account held in that currency.');
        }
        $kind = (string)$p['kind'];
        $principal = round((float)$p['principal'], 2);
        $inst = round((float)$p['installment'], 2);
        $date = (string)$p['start_date'];
        $desc = mb_substr(trim((string)($p['description'] ?? '')), 0, 200);
        $user = (int)$this->settings->userdata('id');
        $loansAcc = (new PayrollPostingService($this->conn, $this->settings))->accounts()['loans'];

        $pr = (string)$principal; $in = (string)$inst;
        $ins = $this->conn->prepare("INSERT INTO employee_loans (tenant_id, employee_id, kind, description, principal, installment, start_date, balance, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->bind_param('iissssssi', $t, $emp, $kind, $desc, $pr, $in, $date, $pr, $user);
        if (!$ins->execute()) {
            return $this->fail('The loan could not be saved.');
        }
        $id = (int)$this->conn->insert_id;
        $label = ($kind === 'advance' ? 'Salary advance' : 'Staff loan') . " #{$id}" . ($desc !== '' ? " ({$desc})" : '');
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, $label, self::issueLines($loansAcc, $bank, $principal, $label), 'posted'), true);
        if (($res['status'] ?? '') !== 'success') {
            $this->conn->query("DELETE FROM employee_loans WHERE id = {$id}");
            return $this->fail($res['msg'] ?? 'The loan could not be posted.');
        }
        $jid = (int)$res['id'];
        $this->conn->query("UPDATE employee_loans SET journal_id = {$jid} WHERE id = {$id}");
        $this->settings->log_audit('Issued staff loan', 'Payroll', "{$label}: {$principal}, recovering {$inst} per payroll from {$date}.");
        return json_encode(['status' => 'success', 'msg' => "{$label} recorded. {$inst} will be recovered from each payroll."]);
    }

    /** Cancel a loan nobody has repaid yet: its payout journal is reversed. */
    public function cancel(int $id): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT id, journal_id, status, start_date FROM employee_loans WHERE id = ? AND tenant_id = ?");
        $q->bind_param('ii', $id, $t);
        $q->execute();
        $l = $q->get_result()->fetch_assoc();
        if (!$l) {
            return $this->fail('Loan not found.');
        }
        if ($l['status'] === 'cancelled') {
            return $this->fail('This loan is already cancelled.');
        }
        $r = $this->conn->prepare("SELECT COUNT(*) n FROM loan_repayments WHERE loan_id = ? AND tenant_id = ?");
        $r->bind_param('ii', $id, $t);
        $r->execute();
        if ((int)$r->get_result()->fetch_assoc()['n'] > 0) {
            return $this->fail('Payroll has already recovered part of this loan, so it cannot be cancelled.');
        }
        $msg = 'Loan cancelled.';
        if (!empty($l['journal_id'])) {
            $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal((int)$l['journal_id'], max(date('Y-m-d'), (string)$l['start_date']), true), true);
            if (($res['status'] ?? '') !== 'success') {
                return $this->fail((string)($res['msg'] ?? 'The payout could not be reversed.'));
            }
            $msg = "Loan cancelled; its payout was reversed by {$res['code']}.";
        }
        $this->conn->query("UPDATE employee_loans SET status = 'cancelled', balance = 0 WHERE id = {$id} AND tenant_id = {$t}");
        $this->settings->log_audit('Cancelled staff loan', 'Payroll', "Loan #{$id}.");
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
