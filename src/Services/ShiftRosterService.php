<?php

declare(strict_types=1);

namespace App\Services;

/** Shift types and roster assignments. Moved verbatim out of Master. */
class ShiftRosterService
{
    private \mysqli $conn;
    private \SystemSettings $settings;
    private \App\Core\Container $container;

    public function __construct(\mysqli $conn, \SystemSettings $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->container = \App\Core\Container::getInstance();
    }

    public function save_shift_type()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $name = $_POST['name'] ?? null;
        $start_time = $_POST['start_time'] ?? null;
        $end_time = $_POST['end_time'] ?? null;
        $break_duration_mins = $_POST['break_duration_mins'] ?? null;

        if (empty($name) || empty($start_time) || empty($end_time)) {
            return json_encode(['status' => 'failed', 'msg' => 'Shift Name, Start Time, and End Time are required.']);
        }

        $s_name = $this->conn->real_escape_string($name);
        $s_start = $this->conn->real_escape_string($start_time);
        $s_end = $this->conn->real_escape_string($end_time);
        $s_break = intval($break_duration_mins ?? 30);

        $save = $this->conn->query("
            INSERT INTO `shift_types` (`tenant_id`, `name`, `start_time`, `end_time`, `break_duration_mins`, `status`)
            VALUES ('{$tenant_id}', '{$s_name}', '{$s_start}', '{$s_end}', '{$s_break}', 1)
        ");

        if ($save) {
            $this->settings->log_audit("Created Shift Type", "HR & Attendance", "Shift: {$name} ({$start_time} - {$end_time})");
            return json_encode(['status' => 'success', 'msg' => 'Shift type saved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save shift type. ' . $this->conn->error]);
    }

    public function save_shift_assignment()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $employee_id = $_POST['employee_id'] ?? null;
        $shift_type_id = $_POST['shift_type_id'] ?? null;
        $start_date = $_POST['start_date'] ?? null;
        $end_date = $_POST['end_date'] ?? null;

        $emp_id = intval($employee_id ?? 0);
        $shift_id = intval($shift_type_id ?? 0);

        if ($emp_id <= 0 || $shift_id <= 0 || empty($start_date)) {
            return json_encode(['status' => 'failed', 'msg' => 'Employee, Shift Type, and Start Date are required.']);
        }

        $s_date = $this->conn->real_escape_string($start_date);
        $e_date = !empty($end_date) ? "'{$this->conn->real_escape_string($end_date)}'" : "NULL";

        $save = $this->conn->query("
            INSERT INTO `shift_assignments` (`tenant_id`, `employee_id`, `shift_type_id`, `start_date`, `end_date`, `status`)
            VALUES ('{$tenant_id}', '{$emp_id}', '{$shift_id}', '{$s_date}', {$e_date}, 1)
        ");

        if ($save) {
            $this->settings->log_audit("Assigned Shift Roster", "HR & Attendance", "Employee ID #{$emp_id}, Shift ID #{$shift_id}");
            return json_encode(['status' => 'success', 'msg' => 'Shift roster assigned successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to assign shift roster.']);
    }

}
