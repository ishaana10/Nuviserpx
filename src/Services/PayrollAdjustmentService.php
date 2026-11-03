<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Retroactive pay. A back-dated salary adjustment is recorded as pending and paid with the employee's next
 * payroll: it is added to that run as taxable, pensionable lump-sum pay, then marked applied. Deleting the
 * payroll puts it back to pending, so it is never lost or paid twice.
 */
class PayrollAdjustmentService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: validate a new adjustment. Returns error messages. */
    public static function validate(array $p): array
    {
        $errors = [];
        if ((int)($p['employee_id'] ?? 0) <= 0) {
            $errors[] = 'Choose an employee.';
        }
        $amount = $p['amount'] ?? null;
        if (!is_numeric($amount) || (float)$amount <= 0) {
            $errors[] = 'Enter an amount above zero.';
        } elseif ((float)$amount > 100000000) {
            $errors[] = 'That amount is too large.';
        }
        $d = (string)($p['effective_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            $errors[] = 'Enter the date the adjustment is effective from.';
        }
        if (mb_strlen(trim((string)($p['reason'] ?? ''))) < 3) {
            $errors[] = 'Give a short reason for the adjustment.';
        }
        return $errors;
    }

    public function create(array $p): string
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $errors = self::validate($p);
        if ($errors) {
            return json_encode(['status' => 'failed', 'msg' => implode(' ', $errors)]);
        }
        $emp = (int)$p['employee_id'];
        $chk = $this->conn->prepare("SELECT id FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $chk->bind_param('ii', $emp, $tenant);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            return json_encode(['status' => 'failed', 'msg' => 'Employee not found in this company.']);
        }
        $amount = number_format((float)$p['amount'], 2, '.', '');
        $date = (string)$p['effective_date'];
        $reason = mb_substr(trim((string)$p['reason']), 0, 500);
        $user = (int)$this->settings->userdata('id');
        $ins = $this->conn->prepare("INSERT INTO payroll_adjustments (tenant_id, employee_id, effective_date, amount, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->bind_param('iisssi', $tenant, $emp, $date, $amount, $reason, $user);
        if (!$ins->execute()) {
            return json_encode(['status' => 'failed', 'msg' => 'The adjustment could not be saved.']);
        }
        $this->settings->log_audit('Recorded Retroactive Pay', 'Payroll', "Employee #{$emp}, amount {$amount}, effective {$date}.");
        return json_encode(['status' => 'success', 'msg' => 'Retroactive pay recorded. It will be paid with this employee\'s next payroll.']);
    }

    public function cancel(int $id): string
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $u = $this->conn->prepare("UPDATE payroll_adjustments SET status = 'cancelled' WHERE id = ? AND tenant_id = ? AND status = 'pending'");
        $u->bind_param('ii', $id, $tenant);
        $u->execute();
        if ($u->affected_rows < 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Only a pending adjustment can be cancelled.']);
        }
        $this->settings->log_audit('Cancelled Retroactive Pay', 'Payroll', "Adjustment #{$id}.");
        return json_encode(['status' => 'success', 'msg' => 'Adjustment cancelled.']);
    }

    /** Pending adjustments for an employee effective on or before $upTo (Y-m-d). Inside a transaction, locks them. */
    public function pending(int $employeeId, string $upTo, bool $lock = false): array
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $sql = "SELECT id, amount FROM payroll_adjustments WHERE tenant_id = ? AND employee_id = ? AND status = 'pending' AND effective_date <= ? ORDER BY id" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->conn->prepare($sql);
        $q->bind_param('iis', $tenant, $employeeId, $upTo);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function total(array $rows): float
    {
        return round(array_sum(array_map(fn ($r) => (float)$r['amount'], $rows)), 2);
    }

    public function markApplied(array $ids, int $payrollId): void
    {
        $u = $this->conn->prepare("UPDATE payroll_adjustments SET status = 'applied', applied_payroll_id = ?, applied_at = NOW() WHERE id = ? AND status = 'pending'");
        foreach ($ids as $id) {
            $id = (int)$id;
            $u->bind_param('ii', $payrollId, $id);
            $u->execute();
            if ($u->affected_rows !== 1) {
                throw new \RuntimeException('A retroactive pay adjustment was already used. Please try again.');
            }
        }
    }

    /** A deleted payroll gives its adjustments back. */
    public function release(int $payrollId): void
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $u = $this->conn->prepare("UPDATE payroll_adjustments SET status = 'pending', applied_payroll_id = NULL, applied_at = NULL WHERE applied_payroll_id = ? AND tenant_id = ? AND status = 'applied'");
        $u->bind_param('ii', $payrollId, $tenant);
        $u->execute();
    }
}
