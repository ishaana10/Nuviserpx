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
    public static function unpaidDays(array $leaves, array $attendance, string $from, string $to, int $daysPerWeek = 5): float
    {
        $work = array_flip(self::workingDays($from, $to, $daysPerWeek));
        $unpaid = [];
        $covered = [];
        foreach ($leaves as $l) {
            foreach (self::workingDays(max($from, (string)$l['start_date']), min($to, (string)$l['end_date']), $daysPerWeek) as $d) {
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
    public function unpaidDaysFor(int $employeeId, string $from, string $to, int $daysPerWeek): float
    {
        $t = (int)$this->settings->active_tenant_id();
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
        return self::unpaidDays($lq->get_result()->fetch_all(MYSQLI_ASSOC), $aq->get_result()->fetch_all(MYSQLI_ASSOC), $from, $to, $daysPerWeek);
    }
}
