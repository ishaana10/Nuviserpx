<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class AccountService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveGroup(array $data): string
    {
        extract($data);
        $dataStr = "";
        foreach ($data as $k => $v) {
            if (!in_array($k, ['id'])) {
                if (!is_numeric($v)) {
                    $v = $this->conn->real_escape_string((string)$v);
                }
                if (!empty($dataStr)) $dataStr .= ",";
                $dataStr .= " `{$k}`='{$v}' ";
            }
        }
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($id) ? intval($id) : 0;
        if (empty($id)) {
            $sql = "INSERT INTO `group_list` set `tenant_id`='{$tenant_id}', {$dataStr} ";
        } else {
            $sql = "UPDATE `group_list` set {$dataStr} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
        }
        $nameEsc = isset($name) ? $this->conn->real_escape_string((string)$name) : '';
        $check = $this->conn->query("SELECT * FROM `group_list` where `name` = '{$nameEsc}' and tenant_id = '{$tenant_id}' and delete_flag = 0 " . ($id > 0 ? " and id != '{$id}'" : ""));
        if ($check && $check->num_rows > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Account's Group Name already exists.";
        } else {
            $save = $this->conn->query($sql);
            if ($save) {
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? " Account's Group has successfully added." : " Account's Group details has been updated successfully.";
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = "An error occured.";
                $resp['err'] = $this->conn->error;
            }
        }
        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deleteGroup(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $del = $this->conn->query("UPDATE `group_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Account's Group has been deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function saveAccount(array $post): string
    {
        extract($post);
        $tenant_id = $this->settings->active_tenant_id();
        $id = isset($id) ? intval($id) : 0;
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
        if (empty($id)) {
            $sql = "INSERT INTO `account_list` set `tenant_id`='{$tenant_id}', {$dataStr} ";
        } else {
            $sql = "UPDATE `account_list` set {$dataStr} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
        }
        $nameEsc = isset($name) ? $this->conn->real_escape_string((string)$name) : '';
        $check = $this->conn->query("SELECT * FROM `account_list` where `name` ='{$nameEsc}' and tenant_id = '{$tenant_id}' and delete_flag = 0 " . ($id > 0 ? " and id != '{$id}' " : ""))->num_rows;
        if ($check > 0) {
            $resp['status'] = 'failed';
            $resp['msg'] = " Account's Name already exists.";
        } else {
            $save = $this->conn->query($sql);
            if ($save) {
                $resp['status'] = 'success';
                $resp['msg'] = empty($id) ? " Account has successfully added." : " Account has been updated successfully.";
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = "An error occured.";
                $resp['err'] = $this->conn->error;
            }
            if ($resp['status'] === 'success') {
                $this->settings->set_flashdata('success', $resp['msg']);
            }
        }
        return json_encode($resp);
    }

    public function deleteAccount(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $del = $this->conn->query("UPDATE `account_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Account has been deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }
}
