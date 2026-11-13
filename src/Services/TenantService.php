<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class TenantService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveTenant(array $post): string
    {
        if (!$this->settings->is_super_admin()) {
            return json_encode(['status' => 'failed', 'msg' => 'Only the developer can manage tenants.']);
        }
        // Whitelist columns (never trust arbitrary POST keys as column names).
        $cols = ['tenant_code', 'name', 'email', 'phone', 'address', 'status'];
        $dataStr = "";
        foreach ($cols as $k) {
            if (!array_key_exists($k, $post)) continue;
            $v = $this->conn->real_escape_string((string)$post[$k]);
            $dataStr .= ($dataStr !== "" ? "," : "") . " `{$k}`='{$v}' ";
        }
        // Module assignment: only touched when the form submitted it.
        if (isset($post['modules_submitted'])) {
            $mods = $this->conn->real_escape_string(implode(',', \App\Support\Permissions::sanitizeTenantModules($post['modules'] ?? [])));
            $dataStr .= ($dataStr !== "" ? "," : "") . " `modules`='{$mods}' ";
        }
        $tenant_code = $post['tenant_code'] ?? '';
        $id = intval($post['id'] ?? 0);
        if (empty($id)) {
            $sql = "INSERT INTO `tenants` set {$dataStr} ";
        } else {
            $sql = "UPDATE `tenants` set {$dataStr} where id = '{$id}' ";
        }
        $before = $id > 0 ? ($this->conn->query("SELECT * FROM `tenants` WHERE id = '{$id}'")->fetch_assoc() ?: []) : [];
        $tenantCode = $this->conn->real_escape_string((string)$tenant_code);
        $check = $this->conn->query("SELECT * FROM `tenants` where `tenant_code` = '{$tenantCode}' and delete_flag = 0 " . ($id > 0 ? " and id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Tenant Code already exists.";
        } else {
            $save = $this->conn->query($sql);
            if ($save) {
                $tid = empty($id) ? (int)$this->conn->insert_id : $id;
                if (empty($id)) {
                    ChartProvisioner::provision($this->conn, $tid);
                }
                if (empty($id)) {
                    $this->settings->log_audit('Created Tenant', 'Tenants', "Created company '" . ($post['name'] ?? '') . "' (" . ($post['tenant_code'] ?? '') . ", #{$tid}).");
                } else {
                    $diff = \App\Support\AuditDiff::changes($before, $post, ['tenant_code', 'name', 'email', 'phone', 'address', 'status']);
                    $mods = isset($post['modules_submitted']) ? '; ' . \App\Support\AuditDiff::listChanges((string)($before['modules'] ?? ''), implode(',', \App\Support\Permissions::sanitizeTenantModules($post['modules'] ?? []))) . ' (modules)' : '';
                    $this->settings->log_audit('Updated Tenant', 'Tenants', "Updated company '" . ($before['name'] ?? '') . "' (#{$tid}): {$diff}{$mods}.");
                }
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? " Tenant added successfully." : " Tenant details updated successfully.";
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = "An error occurred.";
                \App\Support\Db::log($this->conn);
            }
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deleteTenant(int $id): string
    {
        $was = $this->conn->query("SELECT name, tenant_code FROM `tenants` WHERE id = '{$id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("UPDATE `tenants` set delete_flag = 1 where id = '{$id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Tenant', 'Tenants', "Deleted company '" . ($was['name'] ?? '') . "' (" . ($was['tenant_code'] ?? '') . ", #{$id}).");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Tenant deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            \App\Support\Db::log($this->conn);
        }
        return json_encode($resp);
    }
}
