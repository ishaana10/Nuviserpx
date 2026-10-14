<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class JournalService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveJournal(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        if (empty($post['id'])) {
            $prefix = date("Ym-");
            $code = sprintf("%'.05d", 1);
            while (true) {
                $check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
                if ($check > 0) {
                    $code = sprintf("%'.05d", ceil((float)$code) + 1);
                } else {
                    break;
                }
            }
            $post['code'] = $prefix . $code;
            $post['user_id'] = $this->settings->userdata('id');
        }

        extract($post);
        $id = isset($id) ? intval($id) : 0;
        $dataStr = "";
        foreach ($post as $k => $v) {
            if (!in_array($k, ['id']) && !is_array($post[$k])) {
                if (!is_numeric($v) && !is_null($v)) {
                    $v = $this->conn->real_escape_string((string)$v);
                }
                if (!empty($dataStr)) $dataStr .= ",";
                if (!is_null($v)) {
                    $dataStr .= " `{$k}`='{$v}' ";
                } else {
                    $dataStr .= " `{$k}`= NULL ";
                }
            }
        }

        if (empty($id)) {
            $sql = "INSERT INTO `journal_entries` set `tenant_id`='{$tenant_id}', {$dataStr} ";
        } else {
            $sql = "UPDATE `journal_entries` set {$dataStr} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $jid = !empty($id) ? $id : $this->conn->insert_id;
            $dataStr = "";
            $this->conn->query("DELETE FROM `journal_items` where journal_id = '{$jid}'");

            if (isset($account_id) && is_array($account_id)) {
                foreach ($account_id as $k => $v) {
                    if (!empty($dataStr)) $dataStr .= ", ";
                    $group = $group_id[$k] ?? 1;
                    $amt = $amount[$k] ?? 0;
                    $dataStr .= "('{$jid}','{$v}','{$group}','{$amt}')";
                }
            }

            if (!empty($dataStr)) {
                $sql = "INSERT INTO `journal_items` (`journal_id`,`account_id`,`group_id`,`amount`) VALUES {$dataStr}";
                $save2 = $this->conn->query($sql);
                if ($save2) {
                    $resp['status'] = 'success';
                    $resp['msg'] = empty($id) ? " Journal Entry has successfully added." : " Journal Entry has been updated successfully.";
                } else {
                    $resp['status'] = 'failed';
                    $resp['msg'] = empty($id) ? " Journal Entry has failed to save." : " Journal Entry has failed to update.";
                    if (empty($id)) {
                        $this->conn->query("DELETE FROM `journal_entries` where id = '{$jid}'");
                    }
                    $resp['error'] = $this->conn->error;
                }
            } else {
                $resp['status'] = 'failed';
                $resp['msg'] = empty($id) ? " Journal Entry has failed to save." : " Journal Entry has failed to update.";
                if (empty($id)) {
                    $this->conn->query("DELETE FROM `journal_entries` where id = '{$jid}'");
                }
                $resp['error'] = "Journal Items is empty";
            }
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occured.";
            $resp['err'] = $this->conn->error;
        }

        if ($resp['status'] === 'success') {
            $this->settings->set_flashdata('success', $resp['msg']);
        }
        return json_encode($resp);
    }

    public function deleteJournal(int $id): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $del = $this->conn->query("DELETE FROM `journal_entries` where id = '{$id}' and tenant_id = '{$tenant_id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Journal Entry has been deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }

    public function cancelJournal(int $id): string
    {
        $del = $this->conn->query("UPDATE `journal_entries` set `status` = '3' where id = '{$id}'");
        if ($del) {
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Journaling has successfully cancelled.");
        } else {
            $resp['status'] = 'failed';
            $resp['error'] = $this->conn->error;
        }
        return json_encode($resp);
    }
}
