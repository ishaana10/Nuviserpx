<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * SaaS subscription plan management + tenant plan assignment.
 * Platform-operator scope (super admin). Backs admin/developer/customer_plans.php.
 */
class PlanService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    private function guard(): bool
    {
        return (int)$this->settings->userdata('type') === 1;
    }

    /** @param array<string,mixed> $post */
    public function savePlan(array $post): string
    {
        if (!$this->guard()) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied.']);
        }
        $id = !empty($post['id']) ? (int)$post['id'] : 0;
        $name = $this->conn->real_escape_string(trim((string)($post['name'] ?? '')));
        $code = strtolower(preg_replace('/[^a-z0-9_]/', '', (string)($post['code'] ?? '')));
        $code = $this->conn->real_escape_string($code);
        $desc = $this->conn->real_escape_string(trim((string)($post['description'] ?? '')));
        $price = (float)($post['price'] ?? 0);
        $cycle = in_array(($post['billing_cycle'] ?? 'monthly'), ['monthly','quarterly','yearly','one_time'], true) ? $post['billing_cycle'] : 'monthly';
        $max_users = max(0, (int)($post['max_users'] ?? 0));
        $max_invoices = max(0, (int)($post['max_invoices'] ?? 0));
        $max_api = max(0, (int)($post['max_api_calls'] ?? 0));
        $status = isset($post['status']) ? (int)$post['status'] : 1;
        $sort = (int)($post['sort_order'] ?? 0);

        // Features arrive as newline/comma list -> JSON array
        $featuresRaw = (string)($post['features'] ?? '');
        $features = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $featuresRaw) ?: [])));
        $features_esc = $this->conn->real_escape_string(json_encode($features));

        if ($name === '' || $code === '') {
            return json_encode(['status' => 'failed', 'msg' => 'Plan name and code are required.']);
        }

        if ($id === 0) {
            $sql = "INSERT INTO `tenant_plans` SET
                `code`='{$code}', `name`='{$name}', `description`='{$desc}', `price`='{$price}',
                `billing_cycle`='{$cycle}', `max_users`='{$max_users}', `max_invoices`='{$max_invoices}',
                `max_api_calls`='{$max_api}', `features`='{$features_esc}', `status`='{$status}', `sort_order`='{$sort}'";
        } else {
            $sql = "UPDATE `tenant_plans` SET
                `code`='{$code}', `name`='{$name}', `description`='{$desc}', `price`='{$price}',
                `billing_cycle`='{$cycle}', `max_users`='{$max_users}', `max_invoices`='{$max_invoices}',
                `max_api_calls`='{$max_api}', `features`='{$features_esc}', `status`='{$status}', `sort_order`='{$sort}'
                WHERE id='{$id}'";
        }

        if ($this->conn->query($sql)) {
            $this->settings->log_audit($id === 0 ? 'Created Plan' : 'Updated Plan', 'Platform', "Plan: {$name}");
            $this->settings->set_flashdata('success', $id === 0 ? ' Plan created.' : ' Plan updated.');
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Could not save plan (code may already exist).', 'err' => $this->conn->error]);
    }

    public function deletePlan(int $id): string
    {
        if (!$this->guard()) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied.']);
        }
        // Detach from any tenants first
        $this->conn->query("UPDATE `tenants` SET `plan_id` = NULL WHERE plan_id = '{$id}'");
        if ($this->conn->query("DELETE FROM `tenant_plans` WHERE id = '{$id}'")) {
            $this->settings->set_flashdata('success', ' Plan deleted.');
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'err' => $this->conn->error]);
    }

    /** @param array<string,mixed> $post */
    public function assignPlan(array $post): string
    {
        if (!$this->guard()) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied.']);
        }
        $tenant_id = (int)($post['tenant_id'] ?? 0);
        $plan_id = !empty($post['plan_id']) ? (int)$post['plan_id'] : null;
        $plan_status = in_array(($post['plan_status'] ?? 'active'), ['trial','active','past_due','suspended','cancelled'], true) ? $post['plan_status'] : 'active';
        $expires = !empty($post['plan_expires_at']) ? $this->conn->real_escape_string((string)$post['plan_expires_at']) : null;

        if ($tenant_id <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid tenant.']);
        }

        $plan_sql = $plan_id ? "'{$plan_id}'" : 'NULL';
        $exp_sql = $expires ? "'{$expires}'" : 'NULL';
        $sql = "UPDATE `tenants` SET
            `plan_id` = {$plan_sql},
            `plan_status` = '{$plan_status}',
            `plan_started_at` = COALESCE(`plan_started_at`, CURDATE()),
            `plan_expires_at` = {$exp_sql}
            WHERE id = '{$tenant_id}'";

        if ($this->conn->query($sql)) {
            $this->settings->log_audit('Assigned Plan', 'Platform', "Tenant #{$tenant_id} -> plan #" . ($plan_id ?? 'none'));
            $this->settings->set_flashdata('success', ' Plan assignment updated.');
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'failed', 'err' => $this->conn->error]);
    }
}
