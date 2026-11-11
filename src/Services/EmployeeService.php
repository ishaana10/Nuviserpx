<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class EmployeeService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveEmployee(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($id) ? intval($id) : 0;
        $dataStr = "";
        $before = $id > 0 ? ($this->conn->query("SELECT * FROM `employee_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: []) : [];
        // Only real, editable columns are written: a posted field name is never used as SQL unless it is one of them.
        foreach (self::editableFields($post, $this->employeeColumns()) as $k => $v) {
            if (!in_array($k, ['id'])) {
                // Nullable foreign keys: store NULL when left blank rather than ''
                if ($k === 'approver_id' && ($v === '' || $v === null)) {
                    if (!empty($dataStr)) $dataStr .= ",";
                    $dataStr .= " `approver_id`=NULL ";
                    continue;
                }
                if (in_array($k, ['termination_date', 'date_of_joining'], true) && ($v === '' || $v === null)) {
                    if (!empty($dataStr)) $dataStr .= ",";
                    $dataStr .= " `{$k}`=NULL ";
                    continue;
                }
                if (!is_numeric($v)) {
                    $v = $this->conn->real_escape_string((string)$v);
                }
                if (!empty($dataStr)) $dataStr .= ",";
                $dataStr .= " `{$k}`='{$v}' ";
            }
        }
        if (empty($id)) {
            $sql = "INSERT INTO `employee_list` set `tenant_id`='{$tenant_id}', {$dataStr} ";
        } else {
            $sql = "UPDATE `employee_list` set {$dataStr} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
        }
        $emailVal = trim((string)($post['email'] ?? ''));
        if ($emailVal !== '' && !EmployeePortalService::validEmail($emailVal)) {
            return json_encode(['status' => 'failed', 'msg' => ' Enter a valid email address.']);
        }
        $empCode = isset($employee_code) ? $this->conn->real_escape_string((string)$employee_code) : '';
        $check = $this->conn->query("SELECT * FROM `employee_list` where `employee_code` = '{$empCode}' and tenant_id = '{$tenant_id}' and delete_flag = 0 " . ($id > 0 ? " and id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Employee Code already exists.";
        } else {
            $save = $this->conn->query($sql);
            $savedId = $id > 0 ? $id : (int)$this->conn->insert_id;
            if ($save) {
                $this->auditEmployee($id <= 0, $savedId, $before, $post);
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? " Employee has been successfully added." : " Employee details have been updated successfully.";
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = "An error occurred.";
                $resp['err'] = $this->conn->error;
            }
        }
        if ($resp['status'] === 'success') {
            if ($id > 0 && $emailVal !== '') {
                // Keep a portal account's sign-in address in step with the employee record.
                $pe = $this->conn->prepare("UPDATE employee_portal_users SET email = ? WHERE employee_id = ? AND tenant_id = ?");
                if ($pe) {
                    $tid = (int)$tenant_id;
                    $pe->bind_param('sii', $emailVal, $id, $tid);
                    $pe->execute();
                }
            }
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deleteEmployee(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $was = $this->conn->query("SELECT employee_code, firstname, lastname FROM `employee_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("UPDATE `employee_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Employee', 'HR & Attendance', "Deleted {$was['firstname']} {$was['lastname']} ({$was['employee_code']}), #{$id}.");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Employee has been deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function saveAttendance(array $post): string
    {
        $scope = $this->settings->employee_scope('attendance');
        if ($scope !== null) {
            if ($scope <= 0) {
                return json_encode(['status' => 'failed', 'msg' => 'Your account is not linked to an employee profile. Ask your administrator.']);
            }
            $post['employee_id'] = $scope;
        }
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($id) ? intval($id) : 0;
        $employee_id = isset($employee_id) ? $this->conn->real_escape_string((string)$employee_id) : '0';
        $attendance_date = isset($attendance_date) ? $this->conn->real_escape_string((string)$attendance_date) : date('Y-m-d');
        $status = isset($status) ? $this->conn->real_escape_string((string)$status) : 'Present';

        $check = $this->conn->query("SELECT * FROM `attendance_list` where `employee_id` = '{$employee_id}' and `attendance_date` = '{$attendance_date}' and tenant_id = '{$tenant_id}' " . (!empty($id) && $id > 0 ? " and id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Attendance record for this employee on the selected date already exists.";
            return json_encode($resp);
        }
        $check_in = !empty($check_in) ? "'" . $this->conn->real_escape_string((string)$check_in) . "'" : "NULL";
        $check_out = !empty($check_out) ? "'" . $this->conn->real_escape_string((string)$check_out) . "'" : "NULL";

        if (empty($id)) {
            $sql = "INSERT INTO `attendance_list` (`tenant_id`, `employee_id`, `attendance_date`, `check_in`, `check_out`, `status`) VALUES ('{$tenant_id}', '{$employee_id}', '{$attendance_date}', {$check_in}, {$check_out}, '{$status}')";
        } else {
            $sql = "UPDATE `attendance_list` SET `employee_id`='{$employee_id}', `attendance_date`='{$attendance_date}', `check_in`={$check_in}, `check_out`={$check_out}, `status`='{$status}' WHERE id = '{$id}' and tenant_id = '{$tenant_id}'";
        }
        $save = $this->conn->query($sql);
        if ($save) {
            $this->settings->log_audit(empty($id) ? 'Saved Attendance' : 'Updated Attendance', 'HR & Attendance', "Employee #{$employee_id} on {$attendance_date}: {$status}, in " . ($check_in === 'NULL' ? '-' : trim($check_in, "'")) . ", out " . ($check_out === 'NULL' ? '-' : trim($check_out, "'")) . (empty($id) ? '' : ", record #{$id}") . '.');
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Attendance record saved successfully." : " Attendance record updated successfully.";
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving attendance.";
            $resp['err'] = $this->conn->error;
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deleteAttendance(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $was = $this->conn->query("SELECT employee_id, attendance_date, status FROM `attendance_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("DELETE FROM `attendance_list` where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Attendance', 'HR & Attendance', "Deleted record #{$id}: employee #" . ($was['employee_id'] ?? '?') . ' on ' . ($was['attendance_date'] ?? '?') . ' (' . ($was['status'] ?? '?') . ').');
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Attendance record deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function saveLeave(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($post['id']) ? intval($post['id']) : 0;
        // Whitelist columns (no arbitrary POST keys as column names).
        $post = array_intersect_key($post, array_flip(['employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 'status', 'day_fraction']));
        $scope = $this->settings->employee_scope('leaves');
        if ($scope !== null) {
            // Self-service: own employee only, always starts pending.
            if ($scope <= 0) {
                return json_encode(['status' => 'failed', 'msg' => 'Your account is not linked to an employee profile. Ask your administrator.']);
            }
            $post['employee_id'] = $scope;
            unset($post['status']);
            if ($id > 0) {
                $own = $this->conn->query("SELECT status FROM `leave_list` WHERE id = '{$id}' AND employee_id = '{$scope}' AND tenant_id = '{$tenant_id}'");
                if (!$own || $own->num_rows === 0) {
                    return json_encode(['status' => 'failed', 'msg' => 'You can only change your own leave requests.']);
                }
                // Once a request is approved/rejected/cancelled it can no longer be edited by the employee.
                $existing = $own->fetch_assoc();
                if (strtolower($existing['status']) !== 'pending') {
                    return json_encode(['status' => 'failed', 'msg' => 'This leave request has already been processed and can no longer be edited.']);
                }
            }
        } elseif (!empty($post['employee_id'])) {
            $eid = (int)$post['employee_id'];
            $chk = $this->conn->query("SELECT id FROM `employee_list` WHERE id = '{$eid}' AND tenant_id = '{$tenant_id}'");
            if (!$chk || $chk->num_rows === 0) {
                return json_encode(['status' => 'failed', 'msg' => 'Invalid employee.']);
            }
        }
        $dateOk = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) === 1 && strtotime((string)$d) !== false;
        if (!$dateOk($post['start_date'] ?? '') || !$dateOk($post['end_date'] ?? '') || $post['end_date'] < $post['start_date']) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter a start date and an end date that is not before it.']);
        }
        if (!in_array((string)($post['leave_type'] ?? ''), ['casual', 'sick', 'paid', 'unpaid'], true)) {
            return json_encode(['status' => 'failed', 'msg' => 'Choose a leave type.']);
        }
        $post['day_fraction'] = LeaveBalanceService::fraction($post['day_fraction'] ?? 1);
        if ($err = LeaveBalanceService::fractionError((string)$post['start_date'], (string)$post['end_date'], $post['day_fraction'])) {
            return json_encode(['status' => 'failed', 'msg' => $err]);
        }
        // A request that would overdraw a tracked leave balance is refused up front.
        if (($post['status'] ?? 'pending') !== 'rejected') {
            $short = (new LeaveBalanceService($this->conn, $this->settings))->check((int)$post['employee_id'], (string)$post['leave_type'], (string)$post['start_date'], (string)$post['end_date'], $id, $post['day_fraction']);
            if ($short !== null) {
                return json_encode(['status' => 'failed', 'msg' => $short]);
            }
        }
        $dataStr = "";
        foreach ($post as $k => $v) {
            if (!in_array($k, ['id'])) {
                if (!is_numeric($v)) {
                    $v = $this->conn->real_escape_string((string)$v);
                }
                if (!empty($dataStr)) $dataStr .= ",";
                $dataStr .= " `{$k}`='{$v}' ";
            }
        }
        if (empty($id)) {
            // New requests always start pending unless an administrator set a status explicitly.
            $statusCol = isset($post['status']) ? '' : " `status`='pending', ";
            $sql = "INSERT INTO `leave_list` set `tenant_id`='{$tenant_id}', {$statusCol} {$dataStr} ";
        } else {
            $sql = "UPDATE `leave_list` set {$dataStr} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
        }
        $save = $this->conn->query($sql);
        $leaveId = empty($id) ? (int)$this->conn->insert_id : (int)$id;
        if ($save) {
            $this->settings->log_audit(empty($id) ? 'Submitted Leave' : 'Updated Leave', 'HR & Attendance', "Leave #{$leaveId} for employee #" . ($post['employee_id'] ?? '?') . ' (' . ($post['leave_type'] ?? '') . ', ' . ($post['start_date'] ?? '') . ' to ' . ($post['end_date'] ?? '') . ', ' . ($post['day_fraction'] ?? 'full') . ').');
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Leave request submitted successfully." : " Leave request updated successfully.";
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving leave request.";
            $resp['err'] = $this->conn->error;
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    /**
     * Approve or reject a leave request.
     * Only the employee's assigned approver (employee_list.approver_id) or a
     * super administrator may do this, and only while the request is pending.
     */
    public function updateLeaveStatus(int $id, string $status): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $status = strtolower(trim($status));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid status.']);
        }

        $q = $this->conn->query("SELECT l.id, l.status, l.employee_id, l.leave_type, l.start_date, l.end_date, l.day_fraction, e.approver_id FROM `leave_list` l INNER JOIN `employee_list` e ON l.employee_id = e.id WHERE l.id = '{$id}' AND l.tenant_id = '{$tenant_id}'");
        if (!$q || $q->num_rows === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Leave request not found.']);
        }
        $leave = $q->fetch_assoc();

        $my_emp   = (int)$this->settings->own_employee_id();
        $is_super = $this->settings->is_super_admin();
        $is_approver = $is_super || ($my_emp > 0 && (int)$leave['approver_id'] === $my_emp);

        if (!$is_approver) {
            return json_encode(['status' => 'failed', 'msg' => 'Only the assigned approver can approve or reject this leave request.']);
        }
        // Nobody approves their own request.
        if (!$is_super && $my_emp > 0 && (int)$leave['employee_id'] === $my_emp) {
            return json_encode(['status' => 'failed', 'msg' => 'You cannot approve or reject your own leave request.']);
        }
        if (strtolower($leave['status']) !== 'pending') {
            return json_encode(['status' => 'failed', 'msg' => 'This leave request has already been processed.']);
        }
        if ($status === 'approved') {
            $short = (new LeaveBalanceService($this->conn, $this->settings))->check((int)$leave['employee_id'], (string)$leave['leave_type'], (string)$leave['start_date'], (string)$leave['end_date'], (int)$leave['id'], $leave['day_fraction']);
            if ($short !== null) {
                return json_encode(['status' => 'failed', 'msg' => $short]);
            }
        }

        $update = $this->conn->query("UPDATE `leave_list` set `status` = '{$status}' where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($update) {
            $this->settings->log_audit($status === 'approved' ? 'Approved Leave' : 'Rejected Leave', 'HR & Attendance', "Leave #{$id} for employee #{$leave['employee_id']} ({$leave['leave_type']}, {$leave['start_date']} to {$leave['end_date']}) {$status}.");
            $this->settings->set_flashdata('success', " Leave request has been {$status}.");
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'An error occurred.', 'error' => $this->conn->error]);
    }

    /**
     * Cancel (withdraw) a leave request.
     * The owner may cancel their own request while it is still pending; a super
     * administrator may cancel any pending request.
     */
    public function cancelLeave(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $q = $this->conn->query("SELECT id, status, employee_id FROM `leave_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if (!$q || $q->num_rows === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Leave request not found.']);
        }
        $leave = $q->fetch_assoc();

        $my_emp   = (int)$this->settings->own_employee_id();
        $is_super = $this->settings->is_super_admin();
        $is_owner = ($my_emp > 0 && (int)$leave['employee_id'] === $my_emp);

        if (!$is_owner && !$is_super) {
            return json_encode(['status' => 'failed', 'msg' => 'You can only cancel your own leave request.']);
        }
        if (strtolower($leave['status']) !== 'pending') {
            return json_encode(['status' => 'failed', 'msg' => 'Only a pending leave request can be cancelled.']);
        }

        $update = $this->conn->query("UPDATE `leave_list` set `status` = 'cancelled' where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($update) {
            $this->settings->log_audit('Cancelled Leave', 'HR & Attendance', "Leave #{$id} for employee #{$leave['employee_id']} cancelled.");
            $this->settings->set_flashdata('success', " Leave request has been cancelled.");
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'An error occurred.', 'error' => $this->conn->error]);
    }

    public function deleteLeave(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $was = $this->conn->query("SELECT employee_id, leave_type, start_date, end_date, status FROM `leave_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("DELETE FROM `leave_list` where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Leave', 'HR & Attendance', "Deleted leave #{$id}: employee #" . ($was['employee_id'] ?? '?') . ' ' . ($was['leave_type'] ?? '') . ' ' . ($was['start_date'] ?? '') . ' to ' . ($was['end_date'] ?? '') . ' (' . ($was['status'] ?? '') . ').');
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Leave request deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    /** Columns that may never be set from a form. */
    private const LOCKED_COLUMNS = ['id', 'tenant_id', 'delete_flag', 'date_created', 'date_updated'];

    /** Pure: the posted fields that are real, editable columns with plain (non-array) values. */
    public static function editableFields(array $post, array $columns): array
    {
        $ok = array_flip(array_diff($columns, self::LOCKED_COLUMNS));
        $out = [];
        foreach ($post as $k => $v) {
            if (is_string($k) && isset($ok[$k]) && (is_scalar($v) || $v === null)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function employeeColumns(): array
    {
        $cols = [];
        $q = $this->conn->query("SHOW COLUMNS FROM `employee_list`");
        if ($q) {
            foreach ($q->fetch_all(MYSQLI_ASSOC) as $c) {
                $cols[] = $c['Field'];
            }
        }
        return $cols;
    }

    private function auditEmployee(bool $created, int $id, array $before, array $post): void
    {
        $who = trim((string)($post['firstname'] ?? '') . ' ' . (string)($post['lastname'] ?? ''));
        if ($created) {
            $this->settings->log_audit('Created Employee', 'HR & Attendance', "Created {$who} (" . ($post['employee_code'] ?? '') . ", #{$id}).");
            return;
        }
        $diff = \App\Support\AuditDiff::changes(
            $before,
            $post,
            ['employee_code', 'firstname', 'lastname', 'email', 'phone', 'department', 'designation', 'date_of_joining', 'salary', 'tax_code', 'is_resident', 'bank_code', 'approver_id', 'termination_date', 'status', 'fnpf_employer_voluntary', 'tin', 'fnpf_no', 'bank_account'],
            ['tin', 'fnpf_no', 'bank_account']
        );
        $this->settings->log_audit('Updated Employee', 'HR & Attendance', "Updated {$who} (#{$id}): {$diff}.");
    }
}
