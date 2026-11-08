<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Turns leave and attendance into unpaid days for a pay period.
 *  - Approved unpaid leave: each working day in the period is one unpaid day.
 *  - Any other approved leave (paid, sick, casual) covers a working day: no deduction, even if attendance says absent.
 *  - A logged absence on a working day with no approved leave is one unpaid day; a logged half day is half a day.
 * Only working days count (Mon-Fri by default; the number of working days per week is a payroll rule).
 * The deduction is days x daily rate, and the daily rate is annual basic / (weeks per year x working days per week).
 */
class PayrollAttendanceService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: the working days (Y-m-d) from $from to $to inclusive. 5 = Mon-Fri, 6 = Mon-Sat, 7 = every day. */
    public static function workingDays(string $from, string $to, int $daysPerWeek = 5): array
    {
        $daysPerWeek = max(1, min(7, $daysPerWeek));
        $out = [];
        $a = strtotime($from);
        $b = strtotime($to);
        if ($a === false || $b === false || $b < $a) {
            return [];
        }
        for ($t = $a; $t <= $b; $t = strtotime('+1 day', $t)) {
            if ((int)date('N', $t) <= $daysPerWeek) {
                $out[] = date('Y-m-d', $t);
            }
        }
        return $out;
    }

    /**
     * Pure: unpaid days in the period.
     *
     * @param array<int,array{leave_type:string,start_date:string,end_date:string}> $leaves approved leave only
     * @param array<int,array{attendance_date:string,status:string}> $attendance
     */
    public static function unpaidDays(array $leaves, array $attendance, string $from, string $to, int $daysPerWeek = 5, array $skipDates = []): float
    {
        $work = array_flip(array_diff(self::workingDays($from, $to, $daysPerWeek), $skipDates));
        $unpaid = [];
        $covered = [];
        foreach ($leaves as $l) {
            foreach (array_diff(self::workingDays(max($from, (string)$l['start_date']), min($to, (string)$l['end_date']), $daysPerWeek), $skipDates) as $d) {
                if (($l['leave_type'] ?? '') === 'unpaid') {
                    $unpaid[$d] = 1.0;
                } else {
                    $covered[$d] = true;
                }
            }
        }
        foreach ($attendance as $a) {
            $d = (string)$a['attendance_date'];
            if (!isset($work[$d]) || isset($unpaid[$d]) || isset($covered[$d])) {
                continue;
            }
            if ($a['status'] === 'absent') {
                $unpaid[$d] = 1.0;
            } elseif ($a['status'] === 'half_day') {
                $unpaid[$d] = 0.5;
            }
        }
        return round(array_sum($unpaid), 2);
    }

    /**
     * Pure: working days in the period that fall after the employee's last day. Pay stops after that date, so each
     * such day is unpaid. A blank or invalid termination date means the employee has not left.
     */
    public static function daysAfterTermination(string $from, string $to, ?string $terminationDate, int $daysPerWeek = 5): array
    {
        if ($terminationDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $terminationDate) || !strtotime($terminationDate)) {
            return [];
        }
        return self::workingDays(max($from, date('Y-m-d', strtotime($terminationDate . ' +1 day'))), $to, $daysPerWeek);
    }

    /**
     * Pure: working days in the period that fall before the employee's joining date. Pay starts on the joining date,
     * so each such day is unpaid. A blank or invalid date means no proration.
     */
    public static function daysBeforeJoining(string $from, string $to, ?string $joiningDate, int $daysPerWeek = 5): array
    {
        if ($joiningDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $joiningDate) || !strtotime($joiningDate) || $joiningDate === '0000-00-00') {
            return [];
        }
        return self::workingDays($from, min($to, date('Y-m-d', strtotime($joiningDate . ' -1 day'))), $daysPerWeek);
    }

    /**
     * Pure: should this period be prorated for a late start? Only when the joining date falls inside the period
     * (a period wholly before the joining date is a back-dated run, not a starter) and the employee has no earlier
     * payroll. The second test protects records whose joining date was just the day they were typed in.
     */
    public static function prorateJoiner(string $from, string $to, ?string $joiningDate, bool $hasEarlierPayroll, bool $enabled = true): bool
    {
        return $enabled && !$hasEarlierPayroll && $joiningDate !== null && $joiningDate > $from && $joiningDate <= $to;
    }

    /** Pure: pay for one working day. */
    public static function dailyRate(float $basic, int $periodsPerYear, float $weeksPerYear, int $daysPerWeek): float
    {
        $days = $weeksPerYear * max(1, $daysPerWeek);
        return $days > 0 ? round($basic * $periodsPerYear / $days, 4) : 0.0;
    }

    /** Pure: the deduction for $days unpaid days, never more than the basic itself. */
    public static function deduction(float $days, float $dailyRate, float $basic): float
    {
        return round(min(max(0.0, $days) * $dailyRate, max(0.0, $basic)), 2);
    }

    /** Unpaid days for an employee in a period, from approved leave and logged attendance. */
    public function unpaidDaysFor(int $employeeId, string $from, string $to, int $daysPerWeek, bool $prorateJoiners = true): float
    {
        $t = (int)$this->settings->active_tenant_id();
        $eq = $this->conn->prepare("SELECT termination_date, date_of_joining FROM employee_list WHERE id = ? AND tenant_id = ?");
        $eq->bind_param('ii', $employeeId, $t);
        $eq->execute();
        $emp = $eq->get_result()->fetch_assoc() ?: [];
        $after = self::daysAfterTermination($from, $to, $emp['termination_date'] ?? null, $daysPerWeek);
        $join = !empty($emp['date_of_joining']) ? (string)$emp['date_of_joining'] : null;
        if ($join !== null) {
            $pq = $this->conn->prepare("SELECT 1 FROM payroll_list WHERE tenant_id = ? AND employee_id = ? AND status NOT IN ('rejected','voided')
                AND COALESCE(period_start, pay_date) < ? LIMIT 1");
            $pq->bind_param('iis', $t, $employeeId, $from);
            $pq->execute();
            $earlier = $pq->get_result()->num_rows > 0;
            if (self::prorateJoiner($from, $to, $join, $earlier, $prorateJoiners)) {
                // A day can be both before joining and after leaving only in a one-off period: count it once.
                $after = array_values(array_unique(array_merge($after, self::daysBeforeJoining($from, $to, $join, $daysPerWeek))));
            }
        }
        $lq = $this->conn->prepare("SELECT leave_type, start_date, end_date FROM leave_list
            WHERE tenant_id = ? AND employee_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?");
        $aq = $this->conn->prepare("SELECT attendance_date, status FROM attendance_list
            WHERE tenant_id = ? AND employee_id = ? AND attendance_date BETWEEN ? AND ? AND status IN ('absent','half_day')");
        if (!$lq || !$aq) {
            return 0.0;
        }
        $lq->bind_param('iiss', $t, $employeeId, $to, $from);
        $lq->execute();
        $aq->bind_param('iiss', $t, $employeeId, $from, $to);
        $aq->execute();
        // Days after the last day are unpaid whatever the leave or attendance records say, and never counted twice.
        return round(self::unpaidDays($lq->get_result()->fetch_all(MYSQLI_ASSOC), $aq->get_result()->fetch_all(MYSQLI_ASSOC), $from, $to, $daysPerWeek, $after) + count($after), 2);
    }
}
