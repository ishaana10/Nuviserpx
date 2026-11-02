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
        $tenantCode = $this->conn->real_escape_string((string)$tenant_code);
        $check = $this->conn->query("SELECT * FROM `tenants` where `tenant_code` = '{$tenantCode}' and delete_flag = 0 " . ($id > 0 ? " and id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Tenant Code already exists.";
        } else {
            $save = $this->conn->query($sql);
            if ($save) {
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? " Tenant added successfully." : " Tenant details updated successfully.";
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

    public function deleteTenant(int $id): string
    {
        $del = $this->conn->query("UPDATE `tenants` set delete_flag = 1 where id = '{$id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Tenant deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }
}
