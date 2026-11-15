<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LeaveAttachment;
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
        $q = $this->conn->prepare("SELECT id, leave_type, start_date, end_date, day_fraction, reason, status, date_created, attachment_name
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

    /** Validate a request without saving it, for the live check on the form. */
    public function preview(array $post): array
    {
        $type = (string)($post['leave_type'] ?? '');
        $start = (string)($post['start_date'] ?? '');
        $end = (string)($post['end_date'] ?? '');
        $frac = LeaveBalanceService::fraction($post['day_fraction'] ?? 1);
        $out = ['status' => 'success', 'ok' => false, 'days' => 0.0, 'tracked' => false, 'balance' => null, 'after' => null, 'msg' => ''];
        if (!isset(self::TYPE_LABELS[$type])) {
            return $out;
        }
        $dateOk = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
        if (!$dateOk($start) || !$dateOk($end)) {
            return $out;
        }
        if ($end < $start) {
            $out['msg'] = 'The end date cannot be before the start date.';
            return $out;
        }
        if ($err = LeaveBalanceService::fractionError($start, $end, $frac)) {
            $out['msg'] = $err;
            return $out;
        }
        $calc = new LeaveBalanceService($this->conn, $this->settings);
        $days = $calc->requestDays($start, $end, $frac);
        $out['days'] = $days;
        if ($days <= 0) {
            $out['msg'] = 'Those dates have no working days (weekends and public holidays are not counted).';
            return $out;
        }
        if ($this->overlaps($start, $end)) {
            $out['msg'] = 'You already have a pending or approved leave request on some of those dates.';
            return $out;
        }
        $bal = $calc->forEmployee($this->employeeId, $end)[$type] ?? null;
        if ($bal !== null) {
            $out['tracked'] = true;
            $out['balance'] = (float)$bal['balance'];
            $out['after'] = round((float)$bal['balance'] - $days, 2);
        }
        if ($short = $calc->check($this->employeeId, $type, $start, $end, 0, $frac)) {
            $out['msg'] = $short;
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /** Does the employee already have a pending or approved request touching these dates? */
    private function overlaps(string $start, string $end): bool
    {
        $t = $this->tenantId;
        $e = $this->employeeId;
        $q = $this->conn->prepare("SELECT 1 FROM leave_list WHERE tenant_id = ? AND employee_id = ? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ? LIMIT 1");
        $q->bind_param('iiss', $t, $e, $end, $start);
        $q->execute();
        return $q->get_result()->num_rows > 0;
    }

    /** Pending and approved leave plus public holidays, for the calendar on the request form. */
    public function calendar(): array
    {
        $t = $this->tenantId;
        $e = $this->employeeId;
        $from = date('Y-m-d', strtotime('-1 year'));
        $to = date('Y-m-d', strtotime('+2 years'));
        $q = $this->conn->prepare("SELECT start_date s, end_date e, status st, leave_type ty, day_fraction f FROM leave_list
            WHERE tenant_id = ? AND employee_id = ? AND status IN ('pending','approved') AND end_date >= ? AND start_date <= ?");
        $q->bind_param('iiss', $t, $e, $from, $to);
        $q->execute();
        $leave = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $hq = $this->conn->prepare("SELECT holiday_date d, name n FROM public_holidays WHERE tenant_id = ? AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date");
        $hq->bind_param('iss', $t, $from, $to);
        $hq->execute();
        return ['leave' => $leave, 'holidays' => $hq->get_result()->fetch_all(MYSQLI_ASSOC)];
    }

    /**
     * @param array $post  leave_type, start_date, end_date, day_fraction, reason (anything else is ignored)
     * @param array|null $file the optional $_FILES entry (a medical certificate, say)
     */
    public function apply(array $post, ?array $file = null): string
    {
        if (!$this->enabled()) {
            return json_encode(['status' => 'failed', 'msg' => 'Leave requests are not available for your company.']);
        }
        $p = $this->preview($post);
        if (!$p['ok'] && $p['msg'] !== '') {
            return json_encode(['status' => 'failed', 'msg' => $p['msg']]);
        }
        if ($bad = LeaveAttachment::problem($file)) {
            return json_encode(['status' => 'failed', 'msg' => $bad]);
        }
        $clean = [
            'leave_type' => (string)($post['leave_type'] ?? ''),
            'start_date' => (string)($post['start_date'] ?? ''),
            'end_date' => (string)($post['end_date'] ?? ''),
            'day_fraction' => $post['day_fraction'] ?? 1,
            'reason' => mb_substr(trim((string)($post['reason'] ?? '')), 0, 500),
        ];
        // No id: this can only ever create a new pending request.
        $res = (new EmployeeService($this->conn, $this->settings))->saveLeave($clean);
        $r = json_decode($res, true);
        if (is_array($r) && ($r['status'] ?? '') === 'success' && !empty($r['id']) && $file !== null && ($file['error'] ?? 4) === UPLOAD_ERR_OK) {
            $this->attach((int)$r['id'], $file);
        }
        return $res;
    }

    private function attach(int $leaveId, array $file): void
    {
        $stored = LeaveAttachment::store($file, $this->tenantId, $this->employeeId);
        if ($stored === null) {
            return;
        }
        $t = $this->tenantId;
        $e = $this->employeeId;
        $u = $this->conn->prepare("UPDATE leave_list SET attachment_path = ?, attachment_name = ?, attachment_size = ? WHERE id = ? AND tenant_id = ? AND employee_id = ?");
        $u->bind_param('ssiiii', $stored['path'], $stored['name'], $stored['size'], $leaveId, $t, $e);
        $u->execute();
    }

    public function cancel(int $leaveId): string
    {
        return (new EmployeeService($this->conn, $this->settings))->cancelLeave($leaveId);
    }

    /** One of the employee's own attachments as [path, name], or null. */
    public function attachment(int $leaveId): ?array
    {
        $t = $this->tenantId;
        $e = $this->employeeId;
        $q = $this->conn->prepare("SELECT attachment_path p, attachment_name n FROM leave_list WHERE id = ? AND tenant_id = ? AND employee_id = ? AND attachment_path IS NOT NULL");
        $q->bind_param('iii', $leaveId, $t, $e);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        return $r ? [(string)$r['p'], (string)$r['n']] : null;
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
