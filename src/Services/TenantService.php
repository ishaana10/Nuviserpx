<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class TenantService
{
    /** Company Profile: the active tenant edits its own identity and logo. */
    public function saveProfile(array $post, array $files): string
    {
        $t = (int)$this->settings->active_tenant_id();
        $name = trim((string)($post['name'] ?? ''));
        $email = strtolower(trim((string)($post['email'] ?? '')));
        $phone = trim((string)($post['phone'] ?? ''));
        $address = trim((string)($post['address'] ?? ''));
        if ($name === '') {
            return json_encode(['status' => 'failed', 'msg' => 'The company name is required.']);
        }
        if ($email !== '' && !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return json_encode(['status' => 'failed', 'msg' => 'The company email address is not valid.']);
        }

        $logo_sql = '';
        if (!empty($files['logo']['tmp_name'])) {
            if ((int)$files['logo']['size'] > 2 * 1024 * 1024) {
                return json_encode(['status' => 'failed', 'msg' => 'The logo must be 2 MB or smaller.']);
            }
            $info = @getimagesize($files['logo']['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if ($ext === null) {
                return json_encode(['status' => 'failed', 'msg' => 'The logo must be a PNG, JPG or WebP image.']);
            }
            $rel = 'uploads/tenant_logo_' . $t . '.' . $ext;
            foreach (['png', 'jpg', 'webp'] as $old) { // one logo per company
                if ($old !== $ext) { @unlink(base_app . 'uploads/tenant_logo_' . $t . '.' . $old); }
            }
            if (!@move_uploaded_file($files['logo']['tmp_name'], base_app . $rel)) {
                return json_encode(['status' => 'failed', 'msg' => 'The logo could not be stored. Check that the uploads folder is writable.']);
            }
            $logo_sql = ", `logo` = '" . $this->conn->real_escape_string($rel) . "'";
        } elseif (!empty($post['remove_logo'])) {
            $cur = $this->conn->query("SELECT logo FROM `tenants` WHERE id = '{$t}'")->fetch_assoc()['logo'] ?? '';
            if ($cur) { @unlink(base_app . $cur); }
            $logo_sql = ", `logo` = NULL";
        }

        $ok = $this->conn->query("UPDATE `tenants` SET
            `name` = '" . $this->conn->real_escape_string($name) . "',
            `email` = '" . $this->conn->real_escape_string($email) . "',
            `phone` = '" . $this->conn->real_escape_string($phone) . "',
            `address` = '" . $this->conn->real_escape_string($address) . "'
            {$logo_sql}
            WHERE id = '{$t}'");
        if (!$ok) {
            return \App\Support\Db::fail($this->conn, 'The company profile could not be saved.');
        }
        $this->settings->log_audit('Updated Company Profile', 'Settings', "Company profile updated: {$name}" . ($logo_sql !== '' ? ' (logo changed)' : '') . '.');
        return json_encode(['status' => 'success', 'msg' => 'Company profile saved. Your documents now carry it.']);
    }

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
