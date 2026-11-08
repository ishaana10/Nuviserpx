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
        foreach ($post as $k => $v) {
            if (!in_array($k, ['id'])) {
                // Nullable foreign keys: store NULL when left blank rather than ''
                if ($k === 'approver_id' && ($v === '' || $v === null)) {
                    if (!empty($dataStr)) $dataStr .= ",";
                    $dataStr .= " `approver_id`=NULL ";
                    continue;
                }
                if ($k === 'termination_date' && ($v === '' || $v === null)) {
                    if (!empty($dataStr)) $dataStr .= ",";
                    $dataStr .= " `termination_date`=NULL ";
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
        $empCode = isset($employee_code) ? $this->conn->real_escape_string((string)$employee_code) : '';
        $check = $this->conn->query("SELECT * FROM `employee_list` where `employee_code` = '{$empCode}' and tenant_id = '{$tenant_id}' and delete_flag = 0 " . ($id > 0 ? " and id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Employee Code already exists.";
        } else {
            $save = $this->conn->query($sql);
            if ($save) {
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? " Employee has been successfully added." : " Employee details have been updated successfully.";
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = "An error occurred.";
                $resp['err'] = $this->conn->error;
            }
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deleteEmployee(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $del = $this->conn->query("UPDATE `employee_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
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
        $del = $this->conn->query("DELETE FROM `attendance_list` where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
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
        $post = array_intersect_key($post, array_flip(['employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 'status']));
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
        if ($save) {
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

        $q = $this->conn->query("SELECT l.id, l.status, l.employee_id, e.approver_id FROM `leave_list` l INNER JOIN `employee_list` e ON l.employee_id = e.id WHERE l.id = '{$id}' AND l.tenant_id = '{$tenant_id}'");
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

        $update = $this->conn->query("UPDATE `leave_list` set `status` = '{$status}' where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($update) {
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
            $this->settings->set_flashdata('success', " Leave request has been cancelled.");
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'An error occurred.', 'error' => $this->conn->error]);
    }

    public function deleteLeave(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $del = $this->conn->query("DELETE FROM `leave_list` where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Leave request deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }
}
