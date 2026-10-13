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

    public function isPeriodLocked(string $date): bool
    {
        $tenant_id = $this->settings->active_tenant_id();
        $dateEsc = $this->conn->real_escape_string($date);
        $qry = $this->conn->query("SELECT * FROM `accounting_periods` WHERE tenant_id = '{$tenant_id}' AND status IN ('closed', 'locked') AND start_date <= '{$dateEsc}' AND end_date >= '{$dateEsc}'");
        return ($qry && $qry->num_rows > 0);
    }

    public function saveJournal(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $journal_date = !empty($post['journal_date']) ? $post['journal_date'] : date('Y-m-d');

        if ($this->isPeriodLocked($journal_date)) {
            return json_encode([
                'status' => 'failed',
                'msg' => "Accounting period for date {$journal_date} is closed or locked. Cannot post journal entry."
            ]);
        }

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

    public function closeAccountingPeriod(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $name = $this->conn->real_escape_string((string)($post['period_name'] ?? 'Closed Period ' . date('Y-m')));
        $start = $this->conn->real_escape_string((string)($post['start_date'] ?? date('Y-m-01')));
        $end = $this->conn->real_escape_string((string)($post['end_date'] ?? date('Y-m-t')));

        $save = $this->conn->query("INSERT INTO `accounting_periods` SET
            `tenant_id` = '{$tenant_id}',
            `period_name` = '{$name}',
            `start_date` = '{$start}',
            `end_date` = '{$end}',
            `status` = 'closed',
            `closed_by` = '{$user_id}',
            `closed_at` = NOW()");

        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = "Accounting period '{$name}' closed successfully. Transactions in this period are now locked.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while closing period.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function processBankReconciliation(array $post): string
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $account_id = intval($post['account_id'] ?? 0);
        $date = $this->conn->real_escape_string((string)($post['statement_date'] ?? date('Y-m-d')));
        $stmt_bal = (float)($post['statement_balance'] ?? 0.0);
        $gl_bal = (float)($post['gl_balance'] ?? 0.0);
        $diff = round($stmt_bal - $gl_bal, 2);
        $status = ($diff == 0.0) ? 'reconciled' : 'draft';

        $save = $this->conn->query("INSERT INTO `bank_reconciliations` SET
            `tenant_id` = '{$tenant_id}',
            `account_id` = '{$account_id}',
            `statement_date` = '{$date}',
            `statement_balance` = '{$stmt_bal}',
            `gl_balance` = '{$gl_bal}',
            `difference` = '{$diff}',
            `status` = '{$status}',
            `reconciled_by` = '{$user_id}'");

        if ($save) {
            $resp['status'] = 'success';
            $resp['msg'] = "Bank Reconciliation statement for " . date('M Y', strtotime($date)) . " saved with status: " . strtoupper($status) . ".";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "Failed to save bank reconciliation.";
        }

        return json_encode($resp);
    }
}
