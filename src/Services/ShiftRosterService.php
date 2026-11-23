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
        $name = trim((string)$name);
        $timeOk = fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$t) === 1;
        if ($name === '' || mb_strlen($name) > 150 || !$timeOk($start_time) || !$timeOk($end_time)) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter a shift name and start and end times such as 08:00.']);
        }
        $breakMins = $break_duration_mins === null || $break_duration_mins === '' ? 30 : $break_duration_mins;
        if (!is_numeric($breakMins) || (int)$breakMins < 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter the break in minutes (zero or more).']);
        }
        // A shift may run past midnight, so its length wraps around the clock.
        $len = (strtotime("1970-01-01 {$end_time}") - strtotime("1970-01-01 {$start_time}") + 86400) % 86400 / 60;
        if ($len === 0.0 || (int)$breakMins >= $len) {
            return json_encode(['status' => 'failed', 'msg' => 'The start and end times must differ and the break must be shorter than the shift.']);
        }
        $break_duration_mins = (int)$breakMins;

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
        return \App\Support\Db::fail($this->conn, 'Failed to save shift type.');
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
        $dateOk = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) === 1 && date('Y-m-d', strtotime((string)$d)) === (string)$d;
        if (!$dateOk($start_date) || (!empty($end_date) && !$dateOk($end_date))) {
            return json_encode(['status' => 'failed', 'msg' => 'Enter valid dates.']);
        }
        if (!empty($end_date) && $end_date < $start_date) {
            return json_encode(['status' => 'failed', 'msg' => 'The end date cannot be before the start date.']);
        }
        $tid = (int)$tenant_id;
        $e = $this->conn->query("SELECT 1 FROM `employee_list` WHERE id = {$emp_id} AND tenant_id = {$tid} AND delete_flag = 0");
        $sh = $this->conn->query("SELECT 1 FROM `shift_types` WHERE id = {$shift_id} AND tenant_id = {$tid} AND status = 1");
        if (!$e || $e->num_rows === 0 || !$sh || $sh->num_rows === 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Choose an employee and a shift from this company.']);
        }
        // One roster at a time per person (an open-ended roster has no end date).
        $sd = $this->conn->real_escape_string((string)$start_date);
        $ed = !empty($end_date) ? $this->conn->real_escape_string((string)$end_date) : '9999-12-31';
        $ov = $this->conn->query("SELECT 1 FROM `shift_assignments` WHERE tenant_id = {$tid} AND employee_id = {$emp_id} AND status = 1 AND start_date <= '{$ed}' AND COALESCE(end_date, '9999-12-31') >= '{$sd}' LIMIT 1");
        if ($ov && $ov->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'This employee already has a shift roster covering some of those dates. End the earlier one first.']);
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
