<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Employees whose leaving (termination) date has passed are switched to inactive and lose their self-service access.
 * The leaving date itself is still a working day; the employee is deactivated from the day after.
 *
 * Sign-in and open portal sessions already refuse a leaver by date alone, so nothing depends on this sweep running on time.
 * It tidies the record (status, portal account) and is safe to run as often as you like.
 */
class LeaverService
{
    /** Deactivate this company's leavers. Returns how many employees were deactivated. */
    public static function sweep(mysqli $conn, int $tenantId): int
    {
        $q = $conn->prepare("SELECT id FROM employee_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1
            AND termination_date IS NOT NULL AND termination_date < CURDATE()");
        $q->bind_param('i', $tenantId);
        $q->execute();
        $ids = array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $q->close();
        foreach ($ids as $employeeId) {
            $employeeId = (int)$employeeId;
            $u = $conn->prepare("UPDATE employee_list SET status = 0 WHERE id = ? AND tenant_id = ?");
            $u->bind_param('ii', $employeeId, $tenantId);
            $u->execute();
            $u->close();
            $p = $conn->prepare("UPDATE employee_portal_users SET status = 0, password = NULL, invite_token_hash = NULL, invite_expires_at = NULL
                WHERE tenant_id = ? AND employee_id = ? AND (status = 1 OR password IS NOT NULL OR invite_token_hash IS NOT NULL)");
            $p->bind_param('ii', $tenantId, $employeeId);
            $p->execute();
            $hadAccess = $p->affected_rows > 0;
            $p->close();
            if ($hadAccess) {
                $l = $conn->prepare("INSERT INTO employee_portal_logs (tenant_id, employee_id, action, details) VALUES (?, ?, 'revoked', 'Access removed: leaving date has passed')");
                $l->bind_param('ii', $tenantId, $employeeId);
                $l->execute();
                $l->close();
            }
        }
        return count($ids);
    }

    /** Every company (used by the scheduled script and the daily trigger). */
    public static function sweepAll(mysqli $conn): int
    {
        $r = $conn->query("SELECT DISTINCT tenant_id FROM employee_list WHERE delete_flag = 0 AND status = 1
            AND termination_date IS NOT NULL AND termination_date < CURDATE()");
        $tenants = $r ? array_column($r->fetch_all(MYSQLI_ASSOC), 'tenant_id') : [];
        $total = 0;
        foreach ($tenants as $t) {
            $total += self::sweep($conn, (int)$t);
        }
        return $total;
    }

    /**
     * Run the all-company sweep at most once a day, without a scheduled job: called from the HR employee list and portal sign-in.
     * The day is remembered in system_info. Returns how many employees were deactivated (0 when it was not due).
     */
    public static function sweepIfDue(mysqli $conn): int
    {
        $today = date('Y-m-d');
        $r = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'leaver_sweep_date' LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row && $row['meta_value'] === $today) {
            return 0;
        }
        if ($row) {
            $u = $conn->prepare("UPDATE system_info SET meta_value = ? WHERE meta_field = 'leaver_sweep_date'");
        } else {
            $u = $conn->prepare("INSERT INTO system_info (meta_field, meta_value) VALUES ('leaver_sweep_date', ?)");
        }
        $u->bind_param('s', $today);
        $u->execute();
        $u->close();
        return self::sweepAll($conn);
    }
}
