<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Employee self-service: staff sign in to read their own payslips.
 *
 *  - HR invites an employee at the email address on their record. The emailed link carries a one-time token, stored
 *    only as a hash, valid for 7 days. The employee sets a password to activate the account.
 *  - Five wrong passwords lock the account for 15 minutes. Login failures never say whether the email exists.
 *  - An employee sees only their own payslips, and only payroll that is posted (not waiting for approval, returned
 *    or voided). The employee comes from the session, never from the request.
 */
class EmployeePortalService
{
    public const MAX_FAILURES = 5;
    public const LOCK_SECONDS = 900;
    public const INVITE_DAYS = 7;

    public function __construct(
        private readonly mysqli $conn,
        private readonly ?SystemSettings $settings = null
    ) {}

    /* ------------------------------ pure helpers ------------------------------ */

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return string[] problems with a chosen password */
    public static function passwordErrors(string $password, string $email = ''): array
    {
        $err = [];
        if (strlen($password) < 8) $err[] = 'Use at least 8 characters.';
        if (strlen($password) > 72) $err[] = 'Use no more than 72 characters.';
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) $err[] = 'Include at least one letter and one number.';
        if ($email !== '' && strcasecmp($password, $email) === 0) $err[] = 'The password cannot be your email address.';
        return $err;
    }

    public static function isLocked(?string $lockedUntil, int $now): bool
    {
        return $lockedUntil !== null && $lockedUntil !== '' && strtotime($lockedUntil) > $now;
    }

    /** Seconds to lock for after this many failures in a row (0 = not yet). */
    public static function lockSecondsFor(int $failures): int
    {
        return $failures >= self::MAX_FAILURES ? self::LOCK_SECONDS : 0;
    }

    public static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /* ------------------------------ HR side (admin session) ------------------------------ */

    /** Create or refresh an invitation. Returns the JSON response; on success it carries the activation link. */
    public function invite(int $employeeId): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT id, firstname, lastname, email, status, termination_date FROM employee_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $q->bind_param('ii', $employeeId, $t);
        $q->execute();
        $emp = $q->get_result()->fetch_assoc();
        if (!$emp) {
            return $this->fail('Employee not found.');
        }
        if ((int)$emp['status'] !== 1 || (!empty($emp['termination_date']) && $emp['termination_date'] < date('Y-m-d'))) {
            return $this->fail('This employee has left or is inactive, so self-service cannot be offered.');
        }
        $email = trim((string)$emp['email']);
        if (!self::validEmail($email)) {
            return $this->fail('Add a valid email address to this employee first.');
        }
        $token = bin2hex(random_bytes(24));
        $hash = self::tokenHash($token);
        $expires = date('Y-m-d H:i:s', strtotime('+' . self::INVITE_DAYS . ' days'));
        // A new invitation always resets the account: the old password stops working until the employee activates again.
        $u = $this->conn->prepare("INSERT INTO employee_portal_users (tenant_id, employee_id, email, invite_token_hash, invite_expires_at, status)
            VALUES (?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE email = VALUES(email), password = NULL, invite_token_hash = VALUES(invite_token_hash),
                invite_expires_at = VALUES(invite_expires_at), status = 0, failed_attempts = 0, locked_until = NULL");
        $u->bind_param('iisss', $t, $employeeId, $email, $hash, $expires);
        if (!$u->execute()) {
            return $this->fail('The invitation could not be created.');
        }
        $url = base_url . 'portal/employee_login.php?token=' . $token;
        $name = trim($emp['firstname'] . ' ' . $emp['lastname']);
        $sent = false;
        {
            // Emailer is not autoloaded; without this the invitation was never even attempted.
            require_once(base_app . 'classes/Emailer.php');
            $sys = [];
            $r = $this->conn->query("SELECT meta_field, meta_value FROM system_info");
            while ($r && $row = $r->fetch_assoc()) {
                $sys[$row['meta_field']] = $row['meta_value'];
            }
            $sent = (bool)\Emailer::send_employee_portal_invitation($email, $name, $url, $sys);
        }
        $this->log($t, $employeeId, 'invited', 'Invitation ' . ($sent ? 'emailed' : 'created, email not sent'));
        $this->settings->log_audit('Invited employee to self-service', 'Payroll', "Employee #{$employeeId}");
        return json_encode(['status' => 'success', 'sent' => $sent, 'link' => $sent ? '' : $url, 'username' => $email, 'name' => $name, 'msg' => $sent ? "Invitation emailed to {$email}. The link is valid for " . self::INVITE_DAYS . ' days.'
            : "The email could not be sent (check Email settings and the Email log). Give {$name} this link instead (valid for " . self::INVITE_DAYS . " days): {$url}\n\nTheir username for signing in is their email address: {$email}"]);
    }

    public function revoke(int $employeeId): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $u = $this->conn->prepare("UPDATE employee_portal_users SET status = 0, password = NULL, invite_token_hash = NULL, invite_expires_at = NULL WHERE tenant_id = ? AND employee_id = ?");
        $u->bind_param('ii', $t, $employeeId);
        $u->execute();
        if ($u->affected_rows < 1) {
            return $this->fail('This employee has no self-service access to remove.');
        }
        $this->log($t, $employeeId, 'revoked', 'Access removed by HR');
        $this->settings->log_audit('Removed employee self-service access', 'Payroll', "Employee #{$employeeId}");
        return json_encode(['status' => 'success', 'msg' => 'Self-service access removed.']);
    }

    /** employee id => 'active' | 'invited' for the active company. */
    public function statusMap(): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $q = $this->conn->prepare("SELECT employee_id, status, invite_token_hash FROM employee_portal_users WHERE tenant_id = ?");
        if (!$q) {
            return [];
        }
        $q->bind_param('i', $t);
        $q->execute();
        $out = [];
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['employee_id']] = (int)$r['status'] === 1 ? 'active' : ($r['invite_token_hash'] ? 'invited' : 'removed');
        }
        return $out;
    }

    /* ------------------------------ employee side (no admin session) ------------------------------ */

    /** The invitation behind a token, or null if it is unknown, used or expired. */
    public function invitation(string $token): ?array
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $hash = self::tokenHash($token);
        $q = $this->conn->prepare("SELECT u.id, u.tenant_id, u.employee_id, u.email, CONCAT(e.firstname, ' ', e.lastname) name
            FROM employee_portal_users u JOIN employee_list e ON e.id = u.employee_id AND e.tenant_id = u.tenant_id
            WHERE u.invite_token_hash = ? AND u.invite_expires_at >= NOW() AND u.status = 0 LIMIT 1");
        $q->bind_param('s', $hash);
        $q->execute();
        return $q->get_result()->fetch_assoc() ?: null;
    }

    /** @return array{ok:bool,msg:string,session?:array} */
    public function activate(string $token, string $password, string $ip): array
    {
        $inv = $this->invitation($token);
        if (!$inv) {
            return ['ok' => false, 'msg' => 'This invitation link is invalid or has expired. Ask HR to send a new one.'];
        }
        if ($err = self::passwordErrors($password, (string)$inv['email'])) {
            return ['ok' => false, 'msg' => implode(' ', $err)];
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $id = (int)$inv['id'];
        $u = $this->conn->prepare("UPDATE employee_portal_users SET password = ?, status = 1, invite_token_hash = NULL, invite_expires_at = NULL,
            failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ? AND status = 0");
        $u->bind_param('si', $hash, $id);
        $u->execute();
        if ($u->affected_rows < 1) {
            return ['ok' => false, 'msg' => 'This invitation has already been used.'];
        }
        $this->log((int)$inv['tenant_id'], (int)$inv['employee_id'], 'activated', 'Account activated', $ip);
        return ['ok' => true, 'msg' => 'Your account is ready.', 'session' => $this->sessionFor($inv)];
    }

    /** @return array{ok:bool,msg:string,session?:array} */
    public function login(string $email, string $password, string $ip): array
    {
        $generic = ['ok' => false, 'msg' => 'Invalid email or password, or too many attempts. Please try again later.'];
        $email = trim($email);
        if (!self::validEmail($email) || $password === '' || strlen($password) > 200) {
            return $generic;
        }
        $q = $this->conn->prepare("SELECT u.id, u.tenant_id, u.employee_id, u.email, u.password, u.locked_until FROM employee_portal_users u
            INNER JOIN employee_list e ON e.id = u.employee_id AND e.tenant_id = u.tenant_id AND e.delete_flag = 0 AND e.status = 1
            WHERE u.email = ? AND u.status = 1");
        $q->bind_param('s', $email);
        $q->execute();
        $now = time();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $u) {
            if (self::isLocked($u['locked_until'], $now)) {
                continue;
            }
            if (!empty($u['password']) && password_verify($password, $u['password'])) {
                $id = (int)$u['id'];
                $this->conn->query("UPDATE employee_portal_users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = {$id}");
                $this->log((int)$u['tenant_id'], (int)$u['employee_id'], 'login', 'Signed in', $ip);
                return ['ok' => true, 'msg' => 'Signed in.', 'session' => $this->sessionFor($u)];
            }
            $this->fail_attempt((int)$u['id'], (int)$u['tenant_id'], (int)$u['employee_id'], $ip);
        }
        return $generic;
    }

    private function fail_attempt(int $id, int $tenant, int $employee, string $ip): void
    {
        $this->conn->query("UPDATE employee_portal_users SET failed_attempts = failed_attempts + 1 WHERE id = {$id}");
        $r = $this->conn->query("SELECT failed_attempts FROM employee_portal_users WHERE id = {$id}");
        $n = (int)($r ? ($r->fetch_assoc()['failed_attempts'] ?? 0) : 0);
        $secs = self::lockSecondsFor($n);
        if ($secs > 0) {
            $this->conn->query("UPDATE employee_portal_users SET locked_until = DATE_ADD(NOW(), INTERVAL {$secs} SECOND), failed_attempts = 0 WHERE id = {$id}");
            $this->log($tenant, $employee, 'locked', 'Locked after repeated wrong passwords', $ip);
        }
    }

    private function sessionFor(array $u): array
    {
        return ['id' => (int)$u['id'], 'tenant_id' => (int)$u['tenant_id'], 'employee_id' => (int)$u['employee_id'], 'email' => (string)$u['email']];
    }

    /**
     * The signed-in employee changes their own password. The current password must be right (wrong guesses count towards the
     * same lock-out as sign-in), the new one must meet the usual rules and differ from the old one.
     *
     * @return array{ok:bool,msg:string}
     */
    public function changePassword(int $portalUserId, int $tenantId, int $employeeId, string $current, string $new, string $confirm, string $ip): array
    {
        $q = $this->conn->prepare("SELECT id, email, password, locked_until FROM employee_portal_users WHERE id = ? AND tenant_id = ? AND employee_id = ? AND status = 1");
        $q->bind_param('iii', $portalUserId, $tenantId, $employeeId);
        $q->execute();
        $u = $q->get_result()->fetch_assoc();
        if (!$u) {
            return ['ok' => false, 'msg' => 'Please sign in again.'];
        }
        if (self::isLocked($u['locked_until'], time())) {
            return ['ok' => false, 'msg' => 'Too many wrong attempts. Please try again later.'];
        }
        if (strlen($current) > 200 || strlen($new) > 200 || empty($u['password']) || !password_verify($current, (string)$u['password'])) {
            $this->fail_attempt($portalUserId, $tenantId, $employeeId, $ip);
            return ['ok' => false, 'msg' => 'Your current password is not right.'];
        }
        if ($new !== $confirm) {
            return ['ok' => false, 'msg' => 'The two new passwords do not match.'];
        }
        if ($new === $current) {
            return ['ok' => false, 'msg' => 'Choose a new password that is different from your current one.'];
        }
        if ($err = self::passwordErrors($new, (string)$u['email'])) {
            return ['ok' => false, 'msg' => implode(' ', $err)];
        }
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $up = $this->conn->prepare("UPDATE employee_portal_users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE id = ? AND tenant_id = ?");
        $up->bind_param('sii', $hash, $portalUserId, $tenantId);
        $up->execute();
        $this->log($tenantId, $employeeId, 'password_changed', 'Employee changed their own password', $ip);
        return ['ok' => true, 'msg' => 'Your password has been changed.'];
    }

    /** Is this portal account still switched on? (HR can revoke access while an employee is signed in.) */
    public function stillActive(int $portalUserId, int $tenantId, int $employeeId): bool
    {
        $q = $this->conn->prepare("SELECT 1 FROM employee_portal_users u
            INNER JOIN employee_list e ON e.id = u.employee_id AND e.tenant_id = u.tenant_id AND e.delete_flag = 0 AND e.status = 1
            WHERE u.id = ? AND u.tenant_id = ? AND u.employee_id = ? AND u.status = 1 LIMIT 1");
        $q->bind_param('iii', $portalUserId, $tenantId, $employeeId);
        $q->execute();
        return $q->get_result()->num_rows === 1;
    }

    /** The employee's payslips, newest first: posted payroll only. */
    public function payslips(int $tenantId, int $employeeId): array
    {
        $q = $this->conn->prepare("SELECT id, salary_month, pay_date, period_start, period_end, gross_salary, net_salary, payment_status
            FROM payroll_list WHERE tenant_id = ? AND employee_id = ? AND status = 'posted' ORDER BY COALESCE(pay_date, DATE(date_created)) DESC, id DESC LIMIT 120");
        $q->bind_param('ii', $tenantId, $employeeId);
        $q->execute();
        return $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** One payslip with its component lines, only if it is this employee's and posted. */
    public function payslip(int $tenantId, int $employeeId, int $payrollId, string $ip = ''): ?array
    {
        $q = $this->conn->prepare("SELECT p.*, CONCAT(e.firstname, ' ', e.lastname) emp_name, e.employee_code, e.designation, e.department
            FROM payroll_list p JOIN employee_list e ON e.id = p.employee_id AND e.tenant_id = p.tenant_id
            WHERE p.id = ? AND p.tenant_id = ? AND p.employee_id = ? AND p.status = 'posted'");
        $q->bind_param('iii', $payrollId, $tenantId, $employeeId);
        $q->execute();
        $p = $q->get_result()->fetch_assoc();
        if (!$p) {
            return null;
        }
        $c = $this->conn->prepare("SELECT name, type, amount FROM payroll_component_items WHERE payroll_id = ? ORDER BY id");
        $c->bind_param('i', $payrollId);
        $c->execute();
        $p['components'] = $c->get_result()->fetch_all(MYSQLI_ASSOC);
        $this->log($tenantId, $employeeId, 'payslip_viewed', "Payslip #{$payrollId}", $ip);
        return $p;
    }

    private function log(int $tenant, ?int $employee, string $action, string $details, string $ip = ''): void
    {
        $s = $this->conn->prepare("INSERT INTO employee_portal_logs (tenant_id, employee_id, action, details, ip_address) VALUES (?,?,?,?,?)");
        if ($s) {
            $ip = substr($ip, 0, 50);
            $s->bind_param('iisss', $tenant, $employee, $action, $details, $ip);
            $s->execute();
        }
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
