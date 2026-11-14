<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Salary components (payheads) in payroll. A component is assigned to an employee with an optional amount (else its
 * default). Every new payroll picks up the employee's active components; the lines are stored with the payroll as a
 * snapshot, so editing or deleting a component later never changes a payroll that was already run.
 * Earnings follow the component's own flags (PAYE taxable, counts towards pension); deductions come off net pay.
 */
class PayrollComponentService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Pure: engine-ready lines from assignment rows. Amount falls back to the component default; zero lines are dropped.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function lines(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $amt = ($r['amount'] ?? null) !== null && $r['amount'] !== '' ? (float)$r['amount'] : (float)($r['default_amount'] ?? 0);
            $amt = round($amt, 2);
            if ($amt <= 0) {
                continue;
            }
            $out[] = [
                'component_id' => (int)($r['component_id'] ?? 0),
                'loan_id' => (int)($r['loan_id'] ?? 0),
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'type' => ($r['type'] ?? 'earning') === 'deduction' ? 'deduction' : 'earning',
                'is_taxable' => (int)!empty($r['is_taxable']),
                'is_fnpf_subject' => (int)!empty($r['is_fnpf_subject']),
                'description' => trim((string)($r['description'] ?? '')),
                'amount' => $amt,
            ];
        }
        return $out;
    }

    /** Components assigned to the employee that are active on $asAt. */
    public function assignedFor(int $employeeId, string $asAt): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT a.component_id, a.amount, c.code, c.name, c.type, c.is_taxable, c.is_fnpf_subject, c.default_amount,
                TRIM(CONCAT_WS(' - ', NULLIF(c.description, ''), NULLIF(a.note, ''))) AS description
            FROM employee_salary_components a
            JOIN salary_components c ON c.id = a.component_id AND c.tenant_id = a.tenant_id AND c.status = 1
            WHERE a.tenant_id = ? AND a.employee_id = ? AND (a.start_date IS NULL OR a.start_date <= ?) AND (a.end_date IS NULL OR a.end_date >= ?)
            ORDER BY c.type, c.name");
        if (!$q) {
            return [];
        }
        $q->bind_param('iiss', $t, $employeeId, $asAt, $asAt);
        $q->execute();
        return self::lines($q->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    /** The lines stored with a payroll. */
    public function forPayroll(int $payrollId): array
    {
        $q = $this->conn->prepare("SELECT i.component_id, i.loan_id, i.amount, i.code, i.name, i.type, i.is_taxable, i.is_fnpf_subject, i.description
            FROM payroll_component_items i JOIN payroll_list p ON p.id = i.payroll_id AND p.tenant_id = ? WHERE i.payroll_id = ? ORDER BY i.id");
        if (!$q) {
            return [];
        }
        $t = (int)$this->settings->active_tenant_id();
        $q->bind_param('ii', $t, $payrollId);
        $q->execute();
        return self::lines($q->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    /** Replace the stored lines of a payroll. Call inside the payroll's transaction. */
    public function saveItems(int $payrollId, array $lines): void
    {
        $d = $this->conn->prepare("DELETE FROM payroll_component_items WHERE payroll_id = ?");
        $d->bind_param('i', $payrollId);
        $d->execute();
        $i = $this->conn->prepare("INSERT INTO payroll_component_items (payroll_id, component_id, loan_id, code, name, type, is_taxable, is_fnpf_subject, amount, description) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $l) {
            $cid = (int)$l['component_id']; $loanId = (int)($l['loan_id'] ?? 0) ?: null; $code = (string)$l['code']; $name = (string)$l['name']; $type = (string)$l['type'];
            $tax = (int)$l['is_taxable']; $fn = (int)$l['is_fnpf_subject']; $amt = (string)$l['amount']; $desc = (string)($l['description'] ?? '');
            $i->bind_param('iiisssiiss', $payrollId, $cid, $loanId, $code, $name, $type, $tax, $fn, $amt, $desc);
            if (!$i->execute()) {
                throw new \RuntimeException('Salary component lines could not be saved.');
            }
        }
    }

    /** Every assignment of the active company, for the settings page. */
    public function assignments(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT a.id, a.employee_id, a.amount, a.start_date, a.end_date, a.note, c.code, c.name comp_name, c.type, c.default_amount, c.description,
                CONCAT(e.firstname, ' ', e.lastname) emp_name, e.employee_code
            FROM employee_salary_components a
            JOIN salary_components c ON c.id = a.component_id AND c.tenant_id = a.tenant_id
            JOIN employee_list e ON e.id = a.employee_id AND e.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? ORDER BY e.firstname, c.name");
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function assign(array $post): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $emp = (int)($post['employee_id'] ?? 0);
        $comp = (int)($post['component_id'] ?? 0);
        $amountRaw = trim((string)($post['amount'] ?? ''));
        $amount = $amountRaw === '' ? null : round((float)$amountRaw, 2);
        $start = trim((string)($post['start_date'] ?? ''));
        $end = trim((string)($post['end_date'] ?? ''));
        foreach ([$start, $end] as $d) {
            if ($d !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d))) {
                return $this->fail('Enter valid dates.');
            }
        }
        if ($start !== '' && $end !== '' && $end < $start) {
            return $this->fail('The end date cannot be before the start date.');
        }
        if ($amount !== null && $amount < 0) {
            return $this->fail('The amount cannot be negative.');
        }
        $ok = $this->conn->prepare("SELECT (SELECT COUNT(*) FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0) e,
            (SELECT COUNT(*) FROM salary_components WHERE id = ? AND tenant_id = ? AND status = 1) c");
        $ok->bind_param('iiii', $emp, $t, $comp, $t);
        $ok->execute();
        $r = $ok->get_result()->fetch_assoc();
        if (!(int)$r['e'] || !(int)$r['c']) {
            return $this->fail('Choose an employee and an active component from this company.');
        }
        $s = $start === '' ? null : $start;
        $e = $end === '' ? null : $end;
        $amt = $amount === null ? null : (string)$amount;
        $note = mb_substr(trim((string)($post['note'] ?? '')), 0, 255);
        $i = $this->conn->prepare("INSERT INTO employee_salary_components (tenant_id, employee_id, component_id, amount, start_date, end_date, note) VALUES (?,?,?,?,?,?,?)");
        $i->bind_param('iiissss', $t, $emp, $comp, $amt, $s, $e, $note);
        if (!$i->execute()) {
            return $this->fail('The component could not be assigned.');
        }
        $this->settings->log_audit('Assigned salary component', 'Payroll', "Component #{$comp} to employee #{$emp}.");
        return json_encode(['status' => 'success', 'msg' => 'Component assigned. It applies to this employee\'s next payroll.']);
    }

    public function unassign(int $id): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $d = $this->conn->prepare("DELETE FROM employee_salary_components WHERE id = ? AND tenant_id = ?");
        $d->bind_param('ii', $id, $t);
        $d->execute();
        if ($d->affected_rows < 1) {
            return $this->fail('Assignment not found.');
        }
        $this->settings->log_audit('Removed salary component assignment', 'Payroll', "Assignment #{$id}. Payroll already run is not changed.");
        return json_encode(['status' => 'success', 'msg' => 'Assignment removed. Payroll already run is not changed.']);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
