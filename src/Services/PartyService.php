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
        extract($post);
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

        $check = $this->conn->query("SELECT * FROM `party_list` WHERE `party_code` = '{$party_code}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
        if ($check > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Party Code already exists.']);
        }

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
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Party successfully created." : " Party details updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving party.";
            $resp['err'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function deleteParty(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();

        $del = $this->conn->query("UPDATE `party_list` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Party deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }
}
