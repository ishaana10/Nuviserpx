<?php

declare(strict_types=1);

namespace App\Services;

/** Currencies and bank accounts. Moved verbatim out of Master. */
class CurrencyService
{
    private \mysqli $conn;
    private \SystemSettings $settings;
    private \App\Core\Container $container;

    public function __construct(\mysqli $conn, \SystemSettings $settings)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->container = \App\Core\Container::getInstance();
    }

    public function save_currency()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $code = $_POST['code'] ?? null;
        $name = $_POST['name'] ?? null;
        $symbol = $_POST['symbol'] ?? null;
        $exchange_rate = $_POST['exchange_rate'] ?? null;
        $is_default = $_POST['is_default'] ?? null;
        $id = $_POST['id'] ?? null;

        if (empty($code) || empty($name) || empty($symbol)) {
            return json_encode(['status' => 'failed', 'msg' => 'Currency code, name, and symbol are required.']);
        }

        $c_code = $this->conn->real_escape_string(strtoupper(trim($code)));
        $c_name = $this->conn->real_escape_string(trim($name));
        $c_sym = $this->conn->real_escape_string(trim($symbol));
        $c_rate = !empty($exchange_rate) ? floatval($exchange_rate) : 1.000000;
        $is_def = isset($is_default) && $is_default == '1' ? 1 : 0;

        if ($is_def == 1) {
            $this->conn->query("UPDATE `currencies` SET `is_default` = 0 WHERE `tenant_id` = '{$tenant_id}'");
        }

        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id <= 0) {
            $save = $this->conn->query("
                INSERT INTO `currencies` (`tenant_id`, `code`, `name`, `symbol`, `exchange_rate`, `is_default`, `status`)
                VALUES ('{$tenant_id}', '{$c_code}', '{$c_name}', '{$c_sym}', '{$c_rate}', '{$is_def}', 1)
            ");
        } else {
            $save = $this->conn->query("
                UPDATE `currencies` SET `code` = '{$c_code}', `name` = '{$c_name}', `symbol` = '{$c_sym}', `exchange_rate` = '{$c_rate}', `is_default` = '{$is_def}'
                WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'
            ");
        }

        if ($save) {
            $this->settings->log_audit("Saved Currency", "Currencies", "Currency: {$c_code} ({$c_sym}), Rate: {$c_rate}");
            return json_encode(['status' => 'success', 'msg' => 'Currency saved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save currency. ' . $this->conn->error]);
    }

    public function delete_currency()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `currencies` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}' AND `is_default` = 0");
        if ($del) {
            $this->settings->log_audit("Deleted Currency", "Currencies", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Currency deleted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete currency or currency is set as base default.']);
    }

    public function save_bank_account()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $bank_name = $_POST['bank_name'] ?? null;
        $account_name = $_POST['account_name'] ?? null;
        $account_number = $_POST['account_number'] ?? null;
        $currency = $_POST['currency'] ?? null;

        if (empty($bank_name) || empty($account_name)) {
            return json_encode(['status' => 'failed', 'msg' => 'Bank Name and Account Name are required.']);
        }

        // Auto-generate code if missing
        $code_q = $this->conn->query("SELECT MAX(CAST(code AS UNSIGNED)) as max_code FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND code REGEXP '^[0-9]+$'");
        $max_code = ($code_q && $code_q->num_rows > 0) ? intval($code_q->fetch_assoc()['max_code']) : 1000;
        $code = ($max_code > 0) ? strval($max_code + 10) : '1010';

        $full_name = $this->conn->real_escape_string($account_name . ' (' . $bank_name . ')');
        $b_name = $this->conn->real_escape_string($bank_name);
        $acc_num = $this->conn->real_escape_string($account_number ?? '');
        $curr = $this->conn->real_escape_string($currency ?? $this->settings->base_currency());
        $a_type = 'Bank';

        $data = " `tenant_id` = '{$tenant_id}' ";
        $data .= ", `code` = '{$code}' ";
        $data .= ", `name` = '{$full_name}' ";
        $data .= ", `type` = '{$a_type}' ";
        $data .= ", `tax_rate` = 'Tax Exempt (0%)' ";
        $data .= ", `bank_name` = '{$b_name}' ";
        $data .= ", `account_number` = '{$acc_num}' ";
        $data .= ", `currency` = '{$curr}' ";
        $data .= ", `status` = 1 ";

        $save = $this->conn->query("INSERT INTO `account_list` SET {$data}");
        if ($save) {
            $acc_id = $this->conn->insert_id;
            $this->settings->log_audit("Added Bank Account", "Chart of Accounts", "Bank: {$bank_name}, Account: {$account_name}");
            return json_encode(['status' => 'success', 'id' => $acc_id, 'msg' => 'Bank account added successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to add bank account. ' . $this->conn->error]);
    }

    /* Dolibarr Inspired CRM Opportunity Methods */

}
