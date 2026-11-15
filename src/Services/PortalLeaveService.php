<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PortalSettings;
use mysqli;

/**
 * Leave for the signed-in employee in the Employee Portal.
 *
 * Company and employee come from the portal session (constructor arguments); nothing here reads them from a request.
 * Applying and cancelling reuse the staff rules (balance check, half days, "pending only") through EmployeeService, with
 * PortalSettings making the employee a plain self-service user.
 */
class PortalLeaveService
{
    public const TYPE_LABELS = ['casual' => 'Casual leave', 'sick' => 'Sick leave', 'paid' => 'Annual / paid leave', 'unpaid' => 'Unpaid leave'];

    private PortalSettings $settings;

    public function __construct(
        private readonly mysqli $conn,
        private readonly int $tenantId,
        private readonly int $employeeId
    ) {
        $this->settings = new PortalSettings($conn, $tenantId, $employeeId);
    }

    /** Is leave switched on for this company (Payroll & HR module)? */
    public function enabled(): bool
    {
        return (bool)$this->settings->module_enabled('mod_payroll');
    }

    /** @return array<string,array{label:string,balance:float,accrued:float,taken:float}> tracked leave types only */
    public function balances(): array
    {
        $out = [];
        foreach ((new LeaveBalanceService($this->conn, $this->settings))->forEmployee($this->employeeId) as $type => $b) {
            $out[$type] = [
                'label' => (string)$b['label'],
                'balance' => (float)($b['balance'] ?? 0),
                'accrued' => (float)($b['accrued'] ?? 0),
                'taken' => (float)($b['taken'] ?? 0),
            ];
        }
        return $out;
    }

    /** The employee's requests, newest first, with working days used. */
    public function history(int $limit = 50): array
    {
        $q = $this->conn->prepare("SELECT id, leave_type, start_date, end_date, day_fraction, reason, status, date_created
            FROM leave_list WHERE tenant_id = ? AND employee_id = ? ORDER BY start_date DESC, id DESC LIMIT ?");
        // bind_param takes references, which a readonly property cannot give: bind local copies.
        $t = $this->tenantId;
        $e = $this->employeeId;
        $q->bind_param('iii', $t, $e, $limit);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $calc = new LeaveBalanceService($this->conn, $this->settings);
        foreach ($rows as &$r) {
            $r['days'] = $calc->requestDays((string)$r['start_date'], (string)$r['end_date'], $r['day_fraction']);
            $r['type_label'] = self::TYPE_LABELS[$r['leave_type']] ?? ucfirst((string)$r['leave_type']);
        }
        return $rows;
    }

    /** @param array $post leave_type, start_date, end_date, day_fraction, reason (anything else is ignored) */
    public function apply(array $post): string
    {
        if (!$this->enabled()) {
            return json_encode(['status' => 'failed', 'msg' => 'Leave requests are not available for your company.']);
        }
        $clean = [
            'leave_type' => (string)($post['leave_type'] ?? ''),
            'start_date' => (string)($post['start_date'] ?? ''),
            'end_date' => (string)($post['end_date'] ?? ''),
            'day_fraction' => $post['day_fraction'] ?? 1,
            'reason' => mb_substr(trim((string)($post['reason'] ?? '')), 0, 500),
        ];
        // No id: this can only ever create a new pending request.
        return (new EmployeeService($this->conn, $this->settings))->saveLeave($clean);
    }

    public function cancel(int $leaveId): string
    {
        return (new EmployeeService($this->conn, $this->settings))->cancelLeave($leaveId);
    }

    /** The employee's own profile (read-only): no pay, tax or bank details. */
    public function details(): ?array
    {
        $q = $this->conn->prepare("SELECT e.employee_code, e.firstname, e.lastname, e.email, e.phone, e.department, e.designation, e.date_of_joining,
                CONCAT(a.firstname, ' ', a.lastname) AS approver_name
            FROM employee_list e LEFT JOIN employee_list a ON a.id = e.approver_id AND a.tenant_id = e.tenant_id
            WHERE e.id = ? AND e.tenant_id = ?");
        $e = $this->employeeId;
        $t = $this->tenantId;
        $q->bind_param('ii', $e, $t);
        $q->execute();
        return $q->get_result()->fetch_assoc() ?: null;
    }
}
