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
        extract($post);
        $dataStr = "";
        foreach ($post as $k => $v) {
            if (!in_array($k, ['id'])) {
                if (!is_numeric($v)) {
                    $v = $this->conn->real_escape_string((string)$v);
                }
                if (!empty($dataStr)) $dataStr .= ",";
                $dataStr .= " `{$k}`='{$v}' ";
            }
        }
        $id = isset($id) ? intval($id) : 0;
        if (empty($id)) {
            $sql = "INSERT INTO `tenants` set {$dataStr} ";
        } else {
            $sql = "UPDATE `tenants` set {$dataStr} where id = '{$id}' ";
        }
        $tenantCode = isset($tenant_code) ? $this->conn->real_escape_string((string)$tenant_code) : '';
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
