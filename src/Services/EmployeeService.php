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
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($id) ? intval($id) : 0;
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
            $sql = "INSERT INTO `leave_list` set `tenant_id`='{$tenant_id}', {$dataStr} ";
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

    public function updateLeaveStatus(int $id, string $status): string
    {
        $idEsc = $this->conn->real_escape_string((string)$id);
        $statusEsc = $this->conn->real_escape_string($status);
        $update = $this->conn->query("UPDATE `leave_list` set `status` = '{$statusEsc}' where id = '{$idEsc}'");
        if ($update) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Leave status updated successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
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
