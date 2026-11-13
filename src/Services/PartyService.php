<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class PartyService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveParty(array $post): string
    {
        $id = $post['id'] ?? null;
        $party_code = $post['party_code'] ?? null;
        $name = $post['name'] ?? null;
        $type = $post['type'] ?? null;
        $email = $post['email'] ?? null;
        $phone = $post['phone'] ?? null;
        $tin = $post['tin'] ?? null;
        $address = $post['address'] ?? null;
        $status = $post['status'] ?? null;
        $credit_limit = $post['credit_limit'] ?? null;
        $payment_terms_days = $post['payment_terms_days'] ?? null;
        $tenant_id = $this->settings->active_tenant_id();
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $party_code = isset($party_code) ? $this->conn->real_escape_string((string)$party_code) : '';
        $name = isset($name) ? $this->conn->real_escape_string((string)$name) : '';
        $type = !empty($type) ? $this->conn->real_escape_string((string)$type) : 'customer';
        $email = isset($email) ? $this->conn->real_escape_string((string)$email) : '';
        $phone = isset($phone) ? $this->conn->real_escape_string((string)$phone) : '';
        $tin = isset($tin) ? $this->conn->real_escape_string((string)$tin) : '';
        $address = isset($address) ? $this->conn->real_escape_string((string)$address) : '';
        $status = isset($status) ? (int)$status : 1;

        // Credit limit and payment terms (customers); empty means none.
        $limit = CreditControlService::cleanLimit($credit_limit ?? '');
        $terms = CreditControlService::cleanTerms($payment_terms_days ?? '');
        if (isset($limit['error']) || isset($terms['error'])) {
            return json_encode(['status' => 'failed', 'msg' => $limit['error'] ?? $terms['error']]);
        }
        [$whtRate, $whtErr] = BillPaymentService::cleanRate($post['wht_rate'] ?? '');
        if ($whtErr) {
            return json_encode(['status' => 'failed', 'msg' => $whtErr]);
        }
        $limitSql = $limit['value'] === null ? 'NULL' : "'" . number_format($limit['value'], 2, '.', '') . "'";
        $termsSql = $terms['value'] === null ? 'NULL' : (string)$terms['value'];

        $check = $this->conn->query("SELECT * FROM `party_list` WHERE `party_code` = '{$party_code}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Party Code already exists.']);
        }

        $before = !empty($id) ? ($this->conn->query("SELECT * FROM `party_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: []) : [];
        if (empty($id)) {
            $sql = "INSERT INTO `party_list` SET
				`tenant_id` = '{$tenant_id}',
				`party_code` = '{$party_code}',
				`name` = '{$name}',
				`type` = '{$type}',
				`email` = '{$email}',
				`phone` = '{$phone}',
				`tin` = '{$tin}',
				`address` = '{$address}',
				`credit_limit` = {$limitSql},
				`payment_terms_days` = {$termsSql},
				`wht_rate` = '{$whtRate}',
				`status` = '{$status}'";
        } else {
            $sql = "UPDATE `party_list` SET
				`party_code` = '{$party_code}',
				`name` = '{$name}',
				`type` = '{$type}',
				`email` = '{$email}',
				`phone` = '{$phone}',
				`tin` = '{$tin}',
				`address` = '{$address}',
				`credit_limit` = {$limitSql},
				`payment_terms_days` = {$termsSql},
				`wht_rate` = '{$whtRate}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
        }

        $save = $this->conn->query($sql);
        $savedId = empty($id) ? (int)$this->conn->insert_id : (int)$id;
        if ($save) {
            $this->auditSave(empty($id), $savedId, $before, $post);
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Party successfully created." : " Party details updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving party.";
            \App\Support\Db::log($this->conn);
        }
        return json_encode($resp);
    }

    public function deleteParty(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $was = $this->conn->query("SELECT name, party_code, type FROM `party_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc() ?: [];

        $del = $this->conn->query("UPDATE `party_list` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit('Deleted Party', 'Customers & Suppliers', "Deleted {$was['type']} '{$was['name']}' ({$was['party_code']}), #{$id}.");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Party deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            \App\Support\Db::log($this->conn);
        }
        return json_encode($resp);
    }

    private function auditSave(bool $created, int $id, array $before, array $post): void
    {
        $name = (string)($post['name'] ?? '');
        if ($created) {
            $this->settings->log_audit('Created Party', 'Customers & Suppliers', "Created " . ($post['type'] ?? 'customer') . " '{$name}' (#{$id}).");
            return;
        }
        $diff = \App\Support\AuditDiff::changes($before, $post, ['party_code', 'name', 'type', 'email', 'phone', 'tin', 'address', 'credit_limit', 'payment_terms_days', 'wht_rate', 'status']);
        $this->settings->log_audit('Updated Party', 'Customers & Suppliers', "Updated '{$name}' (#{$id}): {$diff}.");
    }
}
