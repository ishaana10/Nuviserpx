<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;
use App\Support\Permissions;

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
        // Only registry-known permission keys are stored; the wildcard and any
        // unknown keys submitted via a crafted request are discarded.
        $permissions = implode(',', Permissions::sanitize($permissions ?? null));
        $status = isset($status) ? (int)$status : 1;

        $check = $this->conn->query("SELECT * FROM `roles` WHERE `name` = '{$name}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Role name already exists for this company.']);
        }

        $before = !empty($id) ? ($this->conn->query("SELECT * FROM `roles` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: []) : [];
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
        $roleId = empty($id) ? (int)$this->conn->insert_id : (int)$id;
        if ($save) {
            $this->auditRole(empty($id), $roleId, $before, (string)($post['name'] ?? ''), $permissions, (int)$status);
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

        $was = $this->conn->query("SELECT name FROM `roles` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("UPDATE `roles` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Role', 'User Management', "Deleted role '" . ($was['name'] ?? '') . "' (#{$id}).");
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
        // API keys may carry the wildcard only when a super administrator issues
        // them; any other caller is reduced to valid registry permission keys.
        $submitted_perms = $permissions ?? null;
        if ($this->settings->is_super_admin() && ($submitted_perms === '*' || $submitted_perms === null || $submitted_perms === '')) {
            $permissions = '*';
        } else {
            $permissions = implode(',', Permissions::sanitize($submitted_perms));
        }
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
        $keyId = empty($id) ? (int)$this->conn->insert_id : (int)$id;
        if ($save) {
            // The key and secret are never written to the log.
            $scope = $permissions === '*' ? 'full access' : (substr_count($permissions, ',') + ($permissions === '' ? 0 : 1)) . ' permission(s)';
            $this->settings->log_audit(empty($id) ? 'Created API Key' : 'Updated API Key', 'API & Integrations', "API key '" . ($post['name'] ?? '') . "' (#{$keyId}): {$scope}, " . ($status ? 'active' : 'disabled') . '.');
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

        $was = $this->conn->query("SELECT name FROM `api_keys` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];
        $del = $this->conn->query("DELETE FROM `api_keys` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted API Key', 'API & Integrations', "Revoked and deleted API key '" . ($was['name'] ?? '') . "' (#{$id}).");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " API Key revoked and deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    private function auditRole(bool $created, int $id, array $before, string $name, string $permissions, int $status): void
    {
        if ($created) {
            $this->settings->log_audit('Created Role', 'User Management', "Created role '{$name}' (#{$id}) with " . ($permissions === '' ? 0 : substr_count($permissions, ',') + 1) . ' permission(s).');
            return;
        }
        $perm = \App\Support\AuditDiff::listChanges((string)($before['permissions'] ?? ''), $permissions);
        $other = \App\Support\AuditDiff::changes($before, ['name' => $name, 'status' => $status], ['name', 'status']);
        $this->settings->log_audit('Updated Role', 'User Management', "Updated role '{$name}' (#{$id}): {$perm}. Other: {$other}.");
    }
}
