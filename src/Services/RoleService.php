<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class RoleService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveRole(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $name = isset($name) ? $this->conn->real_escape_string((string)$name) : '';
        $description = isset($description) ? $this->conn->real_escape_string((string)$description) : '';
        $permissions = isset($permissions) && is_array($permissions) ? implode(',', $permissions) : '';
        $status = isset($status) ? (int)$status : 1;

        $check = $this->conn->query("SELECT * FROM `roles` WHERE `name` = '{$name}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Role name already exists for this company.']);
        }

        if (empty($id)) {
            $sql = "INSERT INTO `roles` SET
				`tenant_id` = '{$tenant_id}',
				`name` = '{$name}',
				`description` = '{$description}',
				`permissions` = '{$permissions}',
				`status` = '{$status}'";
        } else {
            $sql = "UPDATE `roles` SET
				`name` = '{$name}',
				`description` = '{$description}',
				`permissions` = '{$permissions}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Custom role created successfully." : " Role permissions updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving role.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function deleteRole(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();

        $del = $this->conn->query("UPDATE `roles` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Role deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function saveApiKey(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $name = isset($name) ? $this->conn->real_escape_string((string)$name) : '';
        $permissions = isset($permissions) && is_array($permissions) ? implode(',', $permissions) : '*';
        $status = isset($status) ? (int)$status : 1;

        if (empty($id)) {
            $api_key = 'erpx_' . bin2hex(random_bytes(16));
            $api_secret = bin2hex(random_bytes(32));

            $sql = "INSERT INTO `api_keys` SET
				`tenant_id` = '{$tenant_id}',
				`name` = '{$name}',
				`api_key` = '{$api_key}',
				`api_secret` = '{$api_secret}',
				`permissions` = '{$permissions}',
				`status` = '{$status}',
				`created_by` = '{$user_id}'";
        } else {
            $sql = "UPDATE `api_keys` SET
				`name` = '{$name}',
				`permissions` = '{$permissions}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " API Key generated successfully." : " API Key updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving API Key.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function deleteApiKey(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();

        $del = $this->conn->query("DELETE FROM `api_keys` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " API Key revoked and deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }
}
