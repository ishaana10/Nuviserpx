<?php

declare(strict_types=1);

namespace App\Support;

if (!class_exists('SystemSettings')) {
    require_once base_app . 'classes/SystemSettings.php';
}

/**
 * Settings for one employee who is signed in to the Employee Portal (no staff session).
 *
 * The leave services decide "which company" and "who is asking" from the settings object. In the portal both come
 * only from the employee's own portal session, passed in here, never from a form or a staff session that happens
 * to share the browser. The employee is never an administrator or approver, and has no staff permissions.
 */
final class PortalSettings extends \SystemSettings
{
    private int $tenantId;
    private int $employeeId;

    public function __construct(\mysqli $conn, int $tenantId, int $employeeId)
    {
        // The parent constructor would open a second database connection; reuse the portal's own.
        $this->conn = $conn;
        $this->tenantId = $tenantId;
        $this->employeeId = $employeeId;
    }

    public function active_tenant_id()
    {
        return $this->tenantId;
    }

    public function userdata($field = '')
    {
        return $field === '' ? false : null;
    }

    public function is_super_admin()
    {
        return false;
    }

    public function own_employee_id()
    {
        return $this->employeeId;
    }

    /** Always the employee's own records, whatever the module. */
    public function employee_scope($module)
    {
        return $this->employeeId;
    }

    public function can_access($permission_key)
    {
        return false;
    }

    public function set_flashdata($flash = '', $value = '')
    {
        return false;
    }

    public function log_audit($action, $module, $details = '')
    {
        $t = $this->tenantId;
        $s = $this->conn->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, module, details, ip_address) VALUES (?, NULL, ?, ?, ?, ?)");
        if (!$s) {
            return;
        }
        $a = (string)$action;
        $m = (string)$module;
        $d = '[Employee portal] ' . (string)$details;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $s->bind_param('issss', $t, $a, $m, $d, $ip);
        $s->execute();
    }
}
