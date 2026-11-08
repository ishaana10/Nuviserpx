<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Leave entitlements, accruals and balances.
 *
 *  - A leave type is tracked when it has a policy: annual days, how many unused days carry into the next year
 *    (blank = all, 0 = none), an optional ceiling, whether the balance may go negative and whether it is paid out
 *    when the employee leaves. Types without a policy (and unpaid leave) are not limited.
 *  - Leave years are calendar years. Days accrue evenly day by day from the joining date (or the start of the year),
 *    stop on the last day of employment, and are never granted ahead of time.
 *  - Balance = carried in + accrued + manual adjustments - approved working days taken, rolled forward year by year.
 *  - Taken days are Monday-to-Friday (or the payroll rule for working days per week) inside each approved request.
 */
class LeaveBalanceService
{
    public const TYPES = ['casual' => 'Casual leave', 'sick' => 'Sick leave', 'paid' => 'Annual / paid leave'];
    private const MAX_YEARS_BACK = 15;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure calculations ------------------------------ */

    /** Days accrued in one calendar year up to $asOf. */
    public static function yearAccrual(float $annual, ?string $join, ?string $term, int $year, string $asOf): float
    {
        if ($annual <= 0) {
            return 0.0;
        }
        $start = max("{$year}-01-01", $join ?: "{$year}-01-01");
        $end = min("{$year}-12-31", $asOf);
        if ($term !== null && $term !== '') {
            $end = min($end, $term);
        }
        if ($end < $start) {
            return 0.0;
        }
        $days = (int)round((strtotime($end) - strtotime($start)) / 86400) + 1;
        $inYear = (int)date('L', strtotime("{$year}-01-01")) ? 366 : 365;
        return round($annual * $days / $inYear, 2);
    }

    /**
     * Working days of approved leave per calendar year. Public holidays are not leave days, each date counts once
     * (the largest share if requests overlap) and a half-day request counts 0.5.
     *
     * @param array<int,array{start_date:string,end_date:string,day_fraction?:mixed}> $leaves
     * @param string[] $holidays Y-m-d dates that are not working days
     * @return array<int,float> year => days
     */
    public static function takenByYear(array $leaves, int $daysPerWeek = 5, array $holidays = []): array
    {
        $seen = [];
        foreach ($leaves as $l) {
            $frac = self::fraction($l['day_fraction'] ?? 1);
            foreach (array_diff(PayrollAttendanceService::workingDays((string)$l['start_date'], (string)$l['end_date'], $daysPerWeek), $holidays) as $d) {
                $seen[$d] = max($seen[$d] ?? 0.0, $frac);
            }
        }
        $out = [];
        foreach ($seen as $d => $f) {
            $y = (int)substr($d, 0, 4);
            $out[$y] = ($out[$y] ?? 0) + $f;
        }
        return $out;
    }

    /** Pure: a leave day fraction is 0.5 or 1, anything else counts as a full day. */
    public static function fraction($v): float
    {
        return is_numeric($v) && abs((float)$v - 0.5) < 0.001 ? 0.5 : 1.0;
    }

    /** Pure: the days a request uses: working days in the range, less holidays, times the day fraction. */
    public static function daysInRequest(string $start, string $end, int $daysPerWeek, array $holidays, $fraction = 1): float
    {
        return count(array_diff(PayrollAttendanceService::workingDays($start, $end, $daysPerWeek), $holidays)) * self::fraction($fraction);
    }

    /** Pure: an error for a bad day fraction (a half day needs a single date), else null. */
    public static function fractionError(string $start, string $end, $fraction): ?string
    {
        if ($fraction === null || $fraction === '' || self::fraction($fraction) === 1.0) {
            return null;
        }
        return $start === $end ? null : 'A half day can only be taken on a single date.';
    }

    /**
     * Roll the balance forward to $asOf.
     *
     * @param array{annual_days:float,carry_over_max:?float,max_balance:?float} $policy
     * @param array<int,float> $taken year => days taken
     * @param array<int,array{effective_date:string,days:float}> $adjustments
     * @return array{opening:float,accrued:float,adjusted:float,taken:float,lapsed:float,balance:float}
     */
    public static function balance(array $policy, ?string $join, ?string $term, string $asOf, array $taken, array $adjustments): array
    {
        $asYear = (int)substr($asOf, 0, 4);
        // Without a joining date nothing accrues before the current year: earlier history comes from adjustments only.
        $join = $join ?: "{$asYear}-01-01";
        $first = (int)substr($join, 0, 4);
        foreach ($adjustments as $a) {
            $first = min($first, (int)substr((string)$a['effective_date'], 0, 4));
        }
        $first = max($first, $asYear - self::MAX_YEARS_BACK);
        $adjByYear = [];
        foreach ($adjustments as $a) {
            $y = (int)substr((string)$a['effective_date'], 0, 4);
            if ((string)$a['effective_date'] <= $asOf) {
                $adjByYear[$y] = ($adjByYear[$y] ?? 0) + (float)$a['days'];
            }
        }
        $carry = 0.0;
        $lapsedTotal = 0.0;
        $row = ['opening' => 0.0, 'accrued' => 0.0, 'adjusted' => 0.0, 'taken' => 0.0, 'lapsed' => 0.0, 'balance' => 0.0];
        for ($y = $first; $y <= $asYear; $y++) {
            $accrued = self::yearAccrual((float)$policy['annual_days'], $join, $term, $y, $asOf);
            $adj = round($adjByYear[$y] ?? 0, 2);
            $tk = round((float)($taken[$y] ?? 0), 2);
            $closing = round($carry + $accrued + $adj - $tk, 2);
            $row = ['opening' => round($carry, 2), 'accrued' => $accrued, 'adjusted' => $adj, 'taken' => $tk, 'lapsed' => 0.0, 'balance' => $closing];
            if ($y < $asYear) {
                $cap = $policy['carry_over_max'] ?? null;
                $next = ($cap !== null && $closing > (float)$cap) ? (float)$cap : $closing;
                $lapsedTotal += $closing - $next;
                $carry = $next;
            } elseif (($policy['max_balance'] ?? null) !== null && $closing > (float)$policy['max_balance']) {
                $lapsedTotal += $closing - (float)$policy['max_balance'];
                $row['balance'] = round((float)$policy['max_balance'], 2);
            }
        }
        $row['lapsed'] = round($lapsedTotal, 2);
        return $row;
    }

    /** Pure: an error message when a request is longer than the available balance, else null. */
    public static function shortfall(float $requestDays, float $available, bool $allowNegative, string $typeLabel): ?string
    {
        if ($requestDays <= 0 || $allowNegative || $requestDays <= $available + 0.0001) {
            return null;
        }
        return sprintf('Not enough %s: %s day%s requested, %s available.', strtolower($typeLabel),
            self::trim($requestDays), $requestDays == 1.0 ? '' : 's', self::trim(max(0.0, $available)));
    }

    public static function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
    }

    /* ------------------------------ policies ------------------------------ */

    /** @return array<string,array> leave_type => policy row (tracked policies only) */
    public function policies(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT leave_type, tracked, annual_days, carry_over_max, max_balance, allow_negative, payable_on_exit FROM leave_policies WHERE tenant_id = ?");
        if (!$q) {
            return [];
        }
        $q->bind_param('i', $t);
        $q->execute();
        $out = [];
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if ((int)$r['tracked'] === 1) {
                $out[$r['leave_type']] = [
                    'annual_days' => (float)$r['annual_days'],
                    'carry_over_max' => $r['carry_over_max'] === null ? null : (float)$r['carry_over_max'],
                    'max_balance' => $r['max_balance'] === null ? null : (float)$r['max_balance'],
                    'allow_negative' => (int)$r['allow_negative'] === 1,
                    'payable_on_exit' => (int)$r['payable_on_exit'] === 1,
                ];
            }
        }
        return $out;
    }

    public function savePolicy(array $post): string
    {
        $type = (string)($post['leave_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            return $this->fail('Choose a leave type.');
        }
        $tracked = !empty($post['tracked']) ? 1 : 0;
        $num = fn($k, $max) => (isset($post[$k]) && $post[$k] !== '' && is_numeric($post[$k]) && $post[$k] >= 0 && $post[$k] <= $max) ? round((float)$post[$k], 2) : null;
        $annual = $num('annual_days', 366);
        if ($tracked && $annual === null) {
            return $this->fail('Enter the annual entitlement in days (0 to 366).');
        }
        $carry = $num('carry_over_max', 366);
        if (isset($post['carry_over_max']) && $post['carry_over_max'] !== '' && $carry === null) {
            return $this->fail('Carry-over must be a number of days from 0 to 366, or blank for no limit.');
        }
        $max = $num('max_balance', 1000);
        if (isset($post['max_balance']) && $post['max_balance'] !== '' && $max === null) {
            return $this->fail('Maximum balance must be a positive number of days, or blank for no ceiling.');
        }
        $neg = !empty($post['allow_negative']) ? 1 : 0;
        $pay = !empty($post['payable_on_exit']) ? 1 : 0;
        $t = (int)$this->settings->active_tenant_id();
        $annual = $annual ?? 0.0;
        $s = $this->conn->prepare("INSERT INTO leave_policies (tenant_id, leave_type, tracked, annual_days, carry_over_max, max_balance, allow_negative, payable_on_exit)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE tracked = VALUES(tracked), annual_days = VALUES(annual_days), carry_over_max = VALUES(carry_over_max),
                max_balance = VALUES(max_balance), allow_negative = VALUES(allow_negative), payable_on_exit = VALUES(payable_on_exit)");
        $s->bind_param('isidddii', $t, $type, $tracked, $annual, $carry, $max, $neg, $pay);
        if (!$s->execute()) {
            return $this->fail('The policy could not be saved.');
        }
        $this->settings->log_audit('Saved leave policy', 'Leave', self::TYPES[$type]);
        return json_encode(['status' => 'success', 'msg' => self::TYPES[$type] . ' policy saved.']);
    }

    public function saveAdjustment(array $post): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $emp = (int)($post['employee_id'] ?? 0);
        $type = (string)($post['leave_type'] ?? '');
        $days = isset($post['days']) && is_numeric($post['days']) ? round((float)$post['days'], 2) : 0.0;
        $date = (string)($post['effective_date'] ?? '');
        $reason = trim((string)($post['reason'] ?? ''));
        if (!isset(self::TYPES[$type])) return $this->fail('Choose a leave type.');
        if ($days == 0.0 || abs($days) > 366) return $this->fail('Enter the days to add (or a negative number to remove), up to 366.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return $this->fail('Enter the effective date.');
        if ($reason === '' || strlen($reason) > 255) return $this->fail('Enter a reason (up to 255 characters).');
        $e = $this->conn->prepare("SELECT id FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $e->bind_param('ii', $emp, $t);
        $e->execute();
        if (!$e->get_result()->fetch_assoc()) return $this->fail('Employee not found.');
        $by = (int)$this->settings->userdata('id');
        $s = $this->conn->prepare("INSERT INTO leave_adjustments (tenant_id, employee_id, leave_type, days, effective_date, reason, created_by) VALUES (?,?,?,?,?,?,?)");
        $s->bind_param('iisdssi', $t, $emp, $type, $days, $date, $reason, $by);
        if (!$s->execute()) return $this->fail('The adjustment could not be saved.');
        $this->settings->log_audit('Leave balance adjusted', 'Leave', "Employee #{$emp} {$type} {$days} days");
        return json_encode(['status' => 'success', 'msg' => 'Balance adjusted.']);
    }

    /* ------------------------------ balances ------------------------------ */

    private function daysPerWeek(string $asOf): int
    {
        try {
            require_once(base_app . 'classes/PayrollEngine.php');
            return max(1, min(7, (int)(\PayrollEngine::load_rules($this->settings, $asOf)['attendance']['working_days_per_week'] ?? 5)));
        } catch (\Throwable $e) {
            return 5;
        }
    }

    /** @var string[]|null */
    private ?array $holidayCache = null;

    /** Every public holiday date for the active company (Y-m-d). */
    public function holidays(): array
    {
        if ($this->holidayCache === null) {
            $t = (int)$this->settings->active_tenant_id();
            $q = $this->conn->prepare("SELECT holiday_date FROM public_holidays WHERE tenant_id = ?");
            $this->holidayCache = [];
            if ($q) {
                $q->bind_param('i', $t);
                $q->execute();
                foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                    $this->holidayCache[] = $r['holiday_date'];
                }
            }
        }
        return $this->holidayCache;
    }

    public function saveHoliday(array $post): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $date = (string)($post['holiday_date'] ?? '');
        $name = trim((string)($post['name'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return $this->fail('Enter the holiday date.');
        if ($name === '' || strlen($name) > 120) return $this->fail('Enter a name (up to 120 characters).');
        $s = $this->conn->prepare("INSERT INTO public_holidays (tenant_id, holiday_date, name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
        $s->bind_param('iss', $t, $date, $name);
        if (!$s->execute()) return $this->fail('The holiday could not be saved.');
        $this->settings->log_audit('Saved public holiday', 'Leave', "{$date} {$name}");
        return json_encode(['status' => 'success', 'msg' => 'Holiday saved.']);
    }

    public function deleteHoliday(int $id): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $s = $this->conn->prepare("DELETE FROM public_holidays WHERE id = ? AND tenant_id = ?");
        $s->bind_param('ii', $id, $t);
        $s->execute();
        if ($s->affected_rows < 1) return $this->fail('Holiday not found.');
        $this->settings->log_audit('Deleted public holiday', 'Leave', "#{$id}");
        return json_encode(['status' => 'success', 'msg' => 'Holiday removed.']);
    }

    /** Set (or with a blank value, clear) one employee's own annual entitlement for a type. */
    public function saveEntitlement(array $post): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $emp = (int)($post['employee_id'] ?? 0);
        $type = (string)($post['leave_type'] ?? '');
        if (!isset(self::TYPES[$type])) return $this->fail('Choose a leave type.');
        $e = $this->conn->prepare("SELECT id FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $e->bind_param('ii', $emp, $t);
        $e->execute();
        if (!$e->get_result()->fetch_assoc()) return $this->fail('Employee not found.');
        $raw = $post['annual_days'] ?? '';
        if ($raw === '' || $raw === null) {
            $d = $this->conn->prepare("DELETE FROM employee_leave_entitlements WHERE tenant_id = ? AND employee_id = ? AND leave_type = ?");
            $d->bind_param('iis', $t, $emp, $type);
            $d->execute();
            $this->settings->log_audit('Cleared individual leave entitlement', 'Leave', "Employee #{$emp} {$type}");
            return json_encode(['status' => 'success', 'msg' => 'Back to the standard entitlement.']);
        }
        if (!is_numeric($raw) || $raw < 0 || $raw > 366) return $this->fail('Enter days from 0 to 366, or leave blank to use the standard entitlement.');
        $days = round((float)$raw, 2);
        $s = $this->conn->prepare("INSERT INTO employee_leave_entitlements (tenant_id, employee_id, leave_type, annual_days) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE annual_days = VALUES(annual_days)");
        $s->bind_param('iisd', $t, $emp, $type, $days);
        if (!$s->execute()) return $this->fail('The entitlement could not be saved.');
        $this->settings->log_audit('Set individual leave entitlement', 'Leave', "Employee #{$emp} {$type} {$days}");
        return json_encode(['status' => 'success', 'msg' => 'Individual entitlement saved.']);
    }

    /** employee id => type => annual days, for people with their own entitlement. */
    public function entitlements(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT employee_id, leave_type, annual_days FROM employee_leave_entitlements WHERE tenant_id = ?");
        $out = [];
        if ($q) {
            $q->bind_param('i', $t);
            $q->execute();
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $out[(int)$r['employee_id']][$r['leave_type']] = (float)$r['annual_days'];
            }
        }
        return $out;
    }

    /** Balances for one employee, keyed by tracked leave type. */
    public function forEmployee(int $employeeId, ?string $asOf = null, int $excludeLeaveId = 0): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $t = (int)$this->settings->active_tenant_id();
        $policies = $this->policies();
        if (!$policies) {
            return [];
        }
        $e = $this->conn->prepare("SELECT date_of_joining, termination_date FROM employee_list WHERE id = ? AND tenant_id = ?");
        $e->bind_param('ii', $employeeId, $t);
        $e->execute();
        $emp = $e->get_result()->fetch_assoc();
        if (!$emp) {
            return [];
        }
        $join = !empty($emp['date_of_joining']) && $emp['date_of_joining'] !== '0000-00-00' ? $emp['date_of_joining'] : null;
        $term = !empty($emp['termination_date']) ? $emp['termination_date'] : null;
        $dpw = $this->daysPerWeek($asOf);
        $own = $this->entitlements()[$employeeId] ?? [];
        $holidays = $this->holidays();
        $out = [];
        foreach ($policies as $type => $p) {
            $custom = array_key_exists($type, $own);
            if ($custom) {
                $p['annual_days'] = $own[$type];
            }
            $lq = $this->conn->prepare("SELECT start_date, end_date, day_fraction FROM leave_list WHERE tenant_id = ? AND employee_id = ? AND leave_type = ? AND status = 'approved' AND id <> ?");
            $lq->bind_param('iisi', $t, $employeeId, $type, $excludeLeaveId);
            $lq->execute();
            $aq = $this->conn->prepare("SELECT effective_date, days FROM leave_adjustments WHERE tenant_id = ? AND employee_id = ? AND leave_type = ?");
            $aq->bind_param('iis', $t, $employeeId, $type);
            $aq->execute();
            $bal = self::balance($p, $join, $term, $asOf, self::takenByYear($lq->get_result()->fetch_all(MYSQLI_ASSOC), $dpw, $holidays), $aq->get_result()->fetch_all(MYSQLI_ASSOC));
            $out[$type] = $bal + ['policy' => $p, 'label' => self::TYPES[$type], 'custom' => $custom];
        }
        return $out;
    }

    /** Error message if this request would overdraw a tracked balance, else null. */
    public function check(int $employeeId, string $type, string $start, string $end, int $excludeLeaveId = 0, $fraction = 1): ?string
    {
        $p = $this->policies()[$type] ?? null;
        if ($p === null || $end < $start) {
            return null;
        }
        $days = self::daysInRequest($start, $end, $this->daysPerWeek($end), $this->holidays(), $fraction);
        $bal = $this->forEmployee($employeeId, $end, $excludeLeaveId)[$type] ?? null;
        return $bal ? self::shortfall((float)$days, (float)$bal['balance'], $p['allow_negative'], self::TYPES[$type]) : null;
    }

    /** Leave days a request uses: working days less public holidays, halved for a half day. */
    public function requestDays(string $start, string $end, $fraction = 1): float
    {
        return self::daysInRequest($start, $end, $this->daysPerWeek($end), $this->holidays(), $fraction);
    }

    /** Days payable on exit: the positive balance of every type marked payable, as of $asOf. */
    public function payoutDays(int $employeeId, string $asOf): float
    {
        $sum = 0.0;
        foreach ($this->forEmployee($employeeId, $asOf) as $b) {
            if ($b['policy']['payable_on_exit'] && $b['balance'] > 0) {
                $sum += $b['balance'];
            }
        }
        return round($sum, 2);
    }

    /** @return array<int,array{id:int,name:string,code:string}> active employees in scope */
    public function employees(?int $onlyId = null): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $sql = "SELECT id, employee_code code, CONCAT(firstname,' ',lastname) name FROM employee_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1"
            . ($onlyId ? " AND id = " . (int)$onlyId : '') . " ORDER BY firstname";
        $q = $this->conn->prepare($sql);
        $q->bind_param('i', $t);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
