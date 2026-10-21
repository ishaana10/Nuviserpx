<?php

use App\Core\Container;
use App\Services\AccountService;
use App\Services\EmployeeService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\JournalService;
use App\Services\PartyService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\RoleService;
use App\Services\TenantService;

require_once(__DIR__ . '/../config.php');

class Master extends DBConnection
{
    private $settings;
    private Container $container;

    public function __construct()
    {
        global $_settings;
        $this->settings = $_settings;
        parent::__construct();

        $this->container = Container::getInstance();
        $this->container->singleton(\mysqli::class, $this->conn);
        $this->container->singleton(SystemSettings::class, $this->settings);
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /* Account / Group Actions */
    public function save_group()
    {
        return $this->container->make(AccountService::class)->saveGroup($_POST);
    }

    public function delete_group()
    {
        return $this->container->make(AccountService::class)->deleteGroup(intval($_POST['id'] ?? 0));
    }

    public function save_account()
    {
        return $this->container->make(AccountService::class)->saveAccount($_POST);
    }

    public function delete_account()
    {
        return $this->container->make(AccountService::class)->deleteAccount(intval($_POST['id'] ?? 0));
    }

    public function delete_accounts_bulk()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $ids = $_POST['account_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return json_encode(['status' => 'failed', 'msg' => 'No accounts selected.']);
        }

        $sanitized_ids = array_map('intval', $ids);
        $id_list = implode(',', $sanitized_ids);

        $del = $this->conn->query("UPDATE `account_list` SET delete_flag = 1 WHERE tenant_id = '{$tenant_id}' AND id IN ({$id_list})");
        if ($del) {
            return json_encode(['status' => 'success', 'msg' => 'Selected accounts successfully deleted.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete selected accounts.']);
    }

    public function archive_accounts_bulk()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $ids = $_POST['account_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return json_encode(['status' => 'failed', 'msg' => 'No accounts selected.']);
        }

        $sanitized_ids = array_map('intval', $ids);
        $id_list = implode(',', $sanitized_ids);

        $upd = $this->conn->query("UPDATE `account_list` SET is_archived = 1 WHERE tenant_id = '{$tenant_id}' AND id IN ({$id_list})");
        if ($upd) {
            return json_encode(['status' => 'success', 'msg' => 'Selected accounts successfully archived.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to archive selected accounts.']);
    }

    public function change_tax_rate_bulk()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $ids = $_POST['account_ids'] ?? [];
        $tax_rate = $this->conn->real_escape_string($_POST['tax_rate'] ?? 'Tax Exempt (0%)');

        if (!is_array($ids) || empty($ids)) {
            return json_encode(['status' => 'failed', 'msg' => 'No accounts selected.']);
        }

        $sanitized_ids = array_map('intval', $ids);
        $id_list = implode(',', $sanitized_ids);

        $upd = $this->conn->query("UPDATE `account_list` SET tax_rate = '{$tax_rate}' WHERE tenant_id = '{$tenant_id}' AND id IN ({$id_list})");
        if ($upd) {
            return json_encode(['status' => 'success', 'msg' => 'Tax rates updated successfully for selected accounts.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to update tax rates.']);
    }

    public function save_asset()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        extract($_POST);

        if (empty($asset_name) || empty($asset_number)) {
            return json_encode(['status' => 'failed', 'msg' => 'Asset Name and Asset Number are required.']);
        }

        // Check unique asset number within tenant
        $target_id = !empty($id) ? intval($id) : 0;
        $chk_sql = "SELECT id FROM `fixed_assets` WHERE `tenant_id` = '{$tenant_id}' AND `asset_number` = '{$this->conn->real_escape_string($asset_number)}' ";
        if ($target_id > 0) {
            $chk_sql .= " AND id != '{$target_id}' ";
        }
        $check = $this->conn->query($chk_sql);
        if ($check && $check->num_rows > 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Asset Number already exists for this tenant.']);
        }

        $data = " `tenant_id` = '{$tenant_id}' ";
        $data .= ", `asset_number` = '{$this->conn->real_escape_string($asset_number)}' ";
        $data .= ", `asset_name` = '{$this->conn->real_escape_string($asset_name)}' ";
        $data .= ", `purchase_date` = " . (!empty($purchase_date) ? "'{$this->conn->real_escape_string($purchase_date)}'" : "NULL") . " ";
        $data .= ", `purchase_price` = '" . (!empty($purchase_price) ? floatval($purchase_price) : 0.00) . "' ";
        $data .= ", `asset_type` = '{$this->conn->real_escape_string($asset_type ?? '')}' ";
        $data .= ", `warranty_expiry` = " . (!empty($warranty_expiry) ? "'{$this->conn->real_escape_string($warranty_expiry)}'" : "NULL") . " ";
        $data .= ", `serial_number` = '{$this->conn->real_escape_string($serial_number ?? '')}' ";
        $data .= ", `description` = '{$this->conn->real_escape_string($description ?? '')}' ";
        $data .= ", `dep_start_date` = " . (!empty($dep_start_date) ? "'{$this->conn->real_escape_string($dep_start_date)}'" : "NULL") . " ";
        $data .= ", `dep_method` = '{$this->conn->real_escape_string($dep_method ?? '')}' ";
        $data .= ", `averaging_method` = '{$this->conn->real_escape_string($averaging_method ?? '')}' ";
        $data .= ", `dep_calc_by` = '{$this->conn->real_escape_string($dep_calc_by ?? 'rate')}' ";
        $data .= ", `dep_rate` = '" . (!empty($dep_rate) ? floatval($dep_rate) : 0.00) . "' ";
        $data .= ", `effective_life_years` = '" . (!empty($effective_life_years) ? floatval($effective_life_years) : 0.00) . "' ";
        $data .= ", `cost_limit` = '" . (!empty($cost_limit) ? floatval($cost_limit) : 0.00) . "' ";
        $data .= ", `residual_value` = '" . (!empty($residual_value) ? floatval($residual_value) : 0.00) . "' ";
        $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'draft')}' ";
        $data .= ", `created_by` = '{$user_id}' ";

        if (isset($_FILES['attachment']) && $_FILES['attachment']['tmp_name'] != '') {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'zip', 'txt'];
            if (!in_array($ext, $allowed_exts)) {
                return json_encode(['status' => 'failed', 'msg' => 'Invalid file attachment format. Allowed types: PDF, Images, Documents, Spreadsheets.']);
            }
            $fname = 'uploads/assets/asset_' . $tenant_id . '_' . time() . '.' . $ext;
            $dir_path = base_app . 'uploads/assets';
            if (!is_dir($dir_path)) {
                mkdir($dir_path, 0777, true);
            }
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], base_app . $fname)) {
                $data .= ", `attachment_path` = '{$fname}' ";
            }
        }

        if ($target_id <= 0) {
            $save = $this->conn->query("INSERT INTO `fixed_assets` SET {$data}");
            $asset_id = $this->conn->insert_id;
        } else {
            $save = $this->conn->query("UPDATE `fixed_assets` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            $asset_id = $target_id;
        }

        if ($save) {
            $this->settings->log_audit(empty($id) ? "Created Asset" : "Updated Asset", "Fixed Assets", "Asset #{$asset_number}: {$asset_name}");
            return json_encode(['status' => 'success', 'id' => $asset_id, 'msg' => 'Fixed asset saved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save fixed asset. ' . $this->conn->error]);
    }

    public function delete_asset()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `fixed_assets` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted Asset", "Fixed Assets", "Asset ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Fixed asset deleted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete fixed asset.']);
    }

    public function save_bank_account()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

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
        $curr = $this->conn->real_escape_string($currency ?? 'FJD');
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
    public function save_opportunity()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        extract($_POST);

        if (empty($title)) {
            return json_encode(['status' => 'failed', 'msg' => 'Opportunity Title is required.']);
        }

        $data = " `tenant_id` = '{$tenant_id}' ";
        $data .= ", `title` = '{$this->conn->real_escape_string($title)}' ";
        $data .= ", `party_id` = " . (!empty($party_id) ? intval($party_id) : "NULL") . " ";
        $data .= ", `stage` = '{$this->conn->real_escape_string($stage ?? 'qualification')}' ";
        $data .= ", `amount` = '" . (!empty($amount) ? floatval($amount) : 0.00) . "' ";
        $data .= ", `probability` = '" . (!empty($probability) ? intval($probability) : 20) . "' ";
        $data .= ", `expected_close_date` = " . (!empty($expected_close_date) ? "'{$this->conn->real_escape_string($expected_close_date)}'" : "NULL") . " ";
        $data .= ", `notes` = '{$this->conn->real_escape_string($notes ?? '')}' ";
        $data .= ", `created_by` = '{$user_id}' ";

        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id <= 0) {
            $save = $this->conn->query("INSERT INTO `crm_opportunities` SET {$data}");
            $opp_id = $this->conn->insert_id;
        } else {
            $save = $this->conn->query("UPDATE `crm_opportunities` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            $opp_id = $target_id;
        }

        if ($save) {
            $this->settings->log_audit(empty($id) ? "Created CRM Deal" : "Updated CRM Deal", "CRM Opportunities", "Title: {$title}");
            return json_encode(['status' => 'success', 'id' => $opp_id, 'msg' => 'CRM Opportunity saved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save opportunity. ' . $this->conn->error]);
    }

    public function delete_opportunity()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `crm_opportunities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted CRM Deal", "CRM Opportunities", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'CRM opportunity deleted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete opportunity.']);
    }

    public function save_proposal_signature()
    {
        extract($_POST);
        $t_id = !empty($tenant_id) ? intval($tenant_id) : $this->settings->active_tenant_id();
        $so_id = intval($sales_order_id ?? 0);

        if ($so_id <= 0 || empty($signer_name) || empty($signer_email) || empty($signature_data)) {
            return json_encode(['status' => 'failed', 'msg' => 'Signer Name, Email, and Signature drawing are required.']);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $s_name = $this->conn->real_escape_string($signer_name);
        $s_email = $this->conn->real_escape_string($signer_email);
        $sig_data = $this->conn->real_escape_string($signature_data);

        $save = $this->conn->query("
            INSERT INTO `proposal_signatures`
            (`tenant_id`, `sales_order_id`, `signer_name`, `signer_email`, `signature_data`, `ip_address`, `status`)
            VALUES ('{$t_id}', '{$so_id}', '{$s_name}', '{$s_email}', '{$sig_data}', '{$ip}', 'accepted')
        ");

        if ($save) {
            // Update sales order status to confirmed
            $this->conn->query("UPDATE `sales_order_list` SET `status` = 'confirmed' WHERE id = '{$so_id}'");
            $this->settings->log_audit("Signed Commercial Proposal", "Customer Portal", "Quote ID #{$so_id} signed by {$signer_name}");
            return json_encode(['status' => 'success', 'msg' => 'Proposal signed and confirmed successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save digital signature. ' . $this->conn->error]);
    }

    /* Dolibarr Inspired Helpdesk Ticket Methods */
    public function save_ticket()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        extract($_POST);

        if (empty($subject)) {
            return json_encode(['status' => 'failed', 'msg' => 'Ticket Subject is required.']);
        }

        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id <= 0) {
            $code = 'TCK-' . sprintf('%05d', rand(100, 99999));
            $data = " `tenant_id` = '{$tenant_id}' ";
            $data .= ", `ticket_code` = '{$code}' ";
            $data .= ", `subject` = '{$this->conn->real_escape_string($subject)}' ";
            $data .= ", `party_id` = " . (!empty($party_id) ? intval($party_id) : "NULL") . " ";
            $data .= ", `priority` = '{$this->conn->real_escape_string($priority ?? 'medium')}' ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'new')}' ";
            $data .= ", `assigned_to` = " . (!empty($assigned_to) ? intval($assigned_to) : "NULL") . " ";
            $data .= ", `created_by` = '{$user_id}' ";

            $save = $this->conn->query("INSERT INTO `tickets` SET {$data}");
            if ($save) {
                $ticket_id = $this->conn->insert_id;
                if (!empty($initial_message)) {
                    $msg = $this->conn->real_escape_string($initial_message);
                    $this->conn->query("INSERT INTO `ticket_messages` (`ticket_id`, `user_id`, `message`) VALUES ('{$ticket_id}', '{$user_id}', '{$msg}')");
                }
                $this->settings->log_audit("Created Ticket", "Helpdesk", "Ticket #{$code}: {$subject}");
                return json_encode(['status' => 'success', 'id' => $ticket_id, 'msg' => 'Ticket created successfully.']);
            }
        } else {
            $data = " `subject` = '{$this->conn->real_escape_string($subject)}' ";
            $data .= ", `party_id` = " . (!empty($party_id) ? intval($party_id) : "NULL") . " ";
            $data .= ", `priority` = '{$this->conn->real_escape_string($priority ?? 'medium')}' ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'new')}' ";
            $data .= ", `assigned_to` = " . (!empty($assigned_to) ? intval($assigned_to) : "NULL") . " ";

            $save = $this->conn->query("UPDATE `tickets` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            if ($save) {
                return json_encode(['status' => 'success', 'id' => $target_id, 'msg' => 'Ticket updated successfully.']);
            }
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save ticket. ' . $this->conn->error]);
    }

    public function reply_ticket()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        extract($_POST);

        $t_id = intval($ticket_id ?? 0);
        if ($t_id <= 0 || empty($message)) {
            return json_encode(['status' => 'failed', 'msg' => 'Message content is required.']);
        }

        $msg = $this->conn->real_escape_string($message);
        $save = $this->conn->query("INSERT INTO `ticket_messages` (`ticket_id`, `user_id`, `message`) VALUES ('{$t_id}', '{$user_id}', '{$msg}')");

        if ($save) {
            if (!empty($status)) {
                $st = $this->conn->real_escape_string($status);
                $this->conn->query("UPDATE `tickets` SET `status` = '{$st}' WHERE id = '{$t_id}' AND tenant_id = '{$tenant_id}'");
            }
            return json_encode(['status' => 'success', 'msg' => 'Reply posted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to post reply.']);
    }

    /* Dolibarr Inspired Contract Methods */
    public function save_contract()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        if (empty($title) || empty($party_id) || empty($amount)) {
            return json_encode(['status' => 'failed', 'msg' => 'Title, Customer, and Amount are required.']);
        }

        $target_id = !empty($id) ? intval($id) : 0;
        if ($target_id <= 0) {
            $c_no = 'CTR-' . sprintf('%05d', rand(100, 99999));
            $data = " `tenant_id` = '{$tenant_id}' ";
            $data .= ", `contract_no` = '{$c_no}' ";
            $data .= ", `title` = '{$this->conn->real_escape_string($title)}' ";
            $data .= ", `party_id` = '" . intval($party_id) . "' ";
            $data .= ", `billing_frequency` = '{$this->conn->real_escape_string($billing_frequency ?? 'monthly')}' ";
            $data .= ", `amount` = '" . floatval($amount) . "' ";
            $data .= ", `start_date` = '{$this->conn->real_escape_string($start_date)}' ";
            $data .= ", `end_date` = " . (!empty($end_date) ? "'{$this->conn->real_escape_string($end_date)}'" : "NULL") . " ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'active')}' ";
            $data .= ", `notes` = '{$this->conn->real_escape_string($notes ?? '')}' ";

            $save = $this->conn->query("INSERT INTO `contracts` SET {$data}");
            if ($save) {
                $contract_id = $this->conn->insert_id;
                $this->settings->log_audit("Created Contract", "Contracts", "Contract #{$c_no}: {$title}");
                return json_encode(['status' => 'success', 'id' => $contract_id, 'msg' => 'Contract created successfully.']);
            }
        } else {
            $data = " `title` = '{$this->conn->real_escape_string($title)}' ";
            $data .= ", `party_id` = '" . intval($party_id) . "' ";
            $data .= ", `billing_frequency` = '{$this->conn->real_escape_string($billing_frequency ?? 'monthly')}' ";
            $data .= ", `amount` = '" . floatval($amount) . "' ";
            $data .= ", `start_date` = '{$this->conn->real_escape_string($start_date)}' ";
            $data .= ", `end_date` = " . (!empty($end_date) ? "'{$this->conn->real_escape_string($end_date)}'" : "NULL") . " ";
            $data .= ", `status` = '{$this->conn->real_escape_string($status ?? 'active')}' ";
            $data .= ", `notes` = '{$this->conn->real_escape_string($notes ?? '')}' ";

            $save = $this->conn->query("UPDATE `contracts` SET {$data} WHERE id = '{$target_id}' AND tenant_id = '{$tenant_id}'");
            if ($save) {
                return json_encode(['status' => 'success', 'id' => $target_id, 'msg' => 'Contract updated successfully.']);
            }
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save contract. ' . $this->conn->error]);
    }

    /* Dolibarr Inspired Warehouse Methods */
    public function save_warehouse()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        if (empty($name)) {
            return json_encode(['status' => 'failed', 'msg' => 'Warehouse Name is required.']);
        }

        $w_name = $this->conn->real_escape_string($name);
        $w_loc = $this->conn->real_escape_string($location ?? '');

        $save = $this->conn->query("INSERT INTO `warehouses` (`tenant_id`, `name`, `location`, `status`) VALUES ('{$tenant_id}', '{$w_name}', '{$w_loc}', 1)");
        if ($save) {
            $this->settings->log_audit("Added Warehouse", "Warehouses", "Name: {$name}");
            return json_encode(['status' => 'success', 'id' => $this->conn->insert_id, 'msg' => 'Warehouse created successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save warehouse.']);
    }

    public function transfer_stock()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        extract($_POST);

        $from_wh = intval($from_warehouse_id ?? 0);
        $to_wh = intval($to_warehouse_id ?? 0);
        $p_id = intval($product_id ?? 0);
        $transfer_qty = floatval($qty ?? 0);

        if ($p_id <= 0 || $transfer_qty <= 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Valid Product and Quantity (> 0) are required.']);
        }

        $save = $this->conn->query("
            INSERT INTO `warehouse_transfers`
            (`tenant_id`, `from_warehouse_id`, `to_warehouse_id`, `product_id`, `qty`, `notes`, `created_by`)
            VALUES ('{$tenant_id}', '{$from_wh}', '{$to_wh}', '{$p_id}', '{$transfer_qty}', '{$this->conn->real_escape_string($notes ?? '')}', '{$user_id}')
        ");

        if ($save) {
            $this->settings->log_audit("Transferred Stock", "Warehouses", "Product ID #{$p_id}, Qty: {$transfer_qty}");
            return json_encode(['status' => 'success', 'msg' => 'Stock transfer recorded successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to record stock transfer.']);
    }

    /* Dolibarr Inspired Employee Expense Claim Methods */
    public function save_expense_claim()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        $emp_id = intval($employee_id ?? 0);
        $c_amount = floatval($amount ?? 0);

        if ($emp_id <= 0 || $c_amount <= 0 || empty($claim_date)) {
            return json_encode(['status' => 'failed', 'msg' => 'Employee, Date, and Amount are required.']);
        }

        $c_no = 'EXP-' . sprintf('%05d', rand(100, 99999));
        $data = " `tenant_id` = '{$tenant_id}' ";
        $data .= ", `claim_no` = '{$c_no}' ";
        $data .= ", `employee_id` = '{$emp_id}' ";
        $data .= ", `claim_date` = '{$this->conn->real_escape_string($claim_date)}' ";
        $data .= ", `category` = '{$this->conn->real_escape_string($category ?? 'Travel & Meals')}' ";
        $data .= ", `amount` = '{$c_amount}' ";
        $data .= ", `description` = '{$this->conn->real_escape_string($description ?? '')}' ";
        $data .= ", `status` = 'pending' ";

        if (isset($_FILES['attachment']) && $_FILES['attachment']['tmp_name'] != '') {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
            if (!in_array($ext, $allowed_exts)) {
                return json_encode(['status' => 'failed', 'msg' => 'Invalid receipt attachment format. Allowed: PDF, Images, Documents.']);
            }
            $fname = 'uploads/expenses/claim_' . $tenant_id . '_' . time() . '.' . $ext;
            $dir_path = base_app . 'uploads/expenses';
            if (!is_dir($dir_path)) {
                mkdir($dir_path, 0777, true);
            }
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], base_app . $fname)) {
                $data .= ", `attachment_path` = '{$fname}' ";
            }
        }

        $save = $this->conn->query("INSERT INTO `expense_reports` SET {$data}");
        if ($save) {
            $this->settings->log_audit("Submitted Expense Claim", "Expense Claims", "Claim #{$c_no}, Amount: {$c_amount}");
            return json_encode(['status' => 'success', 'id' => $this->conn->insert_id, 'msg' => 'Expense claim submitted successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to submit expense claim. ' . $this->conn->error]);
    }

    public function approve_expense_claim()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = intval($_POST['id'] ?? 0);

        $save = $this->conn->query("UPDATE `expense_reports` SET `status` = 'approved', `approved_by` = '{$user_id}' WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($save) {
            $this->settings->log_audit("Approved Expense Claim", "Expense Claims", "Claim ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Expense claim approved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to approve expense claim.']);
    }

    public function save_module_settings()
    {
        $modules = ['mod_accounting', 'mod_fixed_assets', 'mod_sales_crm', 'mod_tickets', 'mod_contracts', 'mod_warehouses', 'mod_expense_reports', 'mod_payroll'];
        foreach ($modules as $m) {
            $val = isset($_POST[$m]) ? '1' : '0';
            $check = $this->conn->query("SELECT id FROM `system_info` WHERE `meta_field` = '{$m}'");
            if ($check && $check->num_rows > 0) {
                $this->conn->query("UPDATE `system_info` SET `meta_value` = '{$val}' WHERE `meta_field` = '{$m}'");
            } else {
                $this->conn->query("INSERT INTO `system_info` (`meta_field`, `meta_value`) VALUES ('{$m}', '{$val}')");
            }
        }
        $this->settings->log_audit("Updated Module Configuration", "System Settings", "Module toggles updated.");
        return json_encode(['status' => 'success', 'msg' => 'Module configuration updated successfully.']);
    }

    /* Dolibarr Inspired Custom Extrafields Methods */
    public function save_extrafield()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        if (empty($element_type) || empty($field_code) || empty($field_label)) {
            return json_encode(['status' => 'failed', 'msg' => 'Target entity, field code, and field label are required.']);
        }

        $elem = $this->conn->real_escape_string($element_type);
        $f_code = $this->conn->real_escape_string(preg_replace('/[^a-z0-9_]/', '', strtolower($field_code)));
        $f_label = $this->conn->real_escape_string($field_label);
        $f_type = $this->conn->real_escape_string($field_type ?? 'text');
        $f_opts = $this->conn->real_escape_string($field_options ?? '');
        $req = isset($is_required) ? 1 : 0;

        $save = $this->conn->query("
            INSERT INTO `custom_extrafields`
            (`tenant_id`, `element_type`, `field_code`, `field_label`, `field_type`, `field_options`, `is_required`, `status`)
            VALUES ('{$tenant_id}', '{$elem}', '{$f_code}', '{$f_label}', '{$f_type}', '{$f_opts}', '{$req}', 1)
        ");

        if ($save) {
            $this->settings->log_audit("Added Custom Extrafield", "Custom Fields", "Target: {$element_type}, Code: {$field_code}");
            return json_encode(['status' => 'success', 'id' => $this->conn->insert_id, 'msg' => 'Custom extrafield created successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save custom extrafield. ' . $this->conn->error]);
    }

    public function delete_extrafield()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `custom_extrafields` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted Custom Extrafield", "Custom Fields", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Custom extrafield deleted.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete custom extrafield.']);
    }

    public function save_currency()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

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

    public function backup_db()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Super Administrator permission required.']);
        }

        $tables = [];
        $res = $this->conn->query("SHOW TABLES");
        while ($row = $res->fetch_array()) {
            $tables[] = $row[0];
        }

        $sql_dump = "-- NuvisERPX Database Dump\n-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($tables as $tbl) {
            $res_create = $this->conn->query("SHOW CREATE TABLE `{$tbl}`");
            $row_create = $res_create->fetch_array();
            $sql_dump .= "DROP TABLE IF EXISTS `{$tbl}`;\n" . $row_create[1] . ";\n\n";

            $res_data = $this->conn->query("SELECT * FROM `{$tbl}`");
            while ($row_data = $res_data->fetch_assoc()) {
                $cols = array_keys($row_data);
                $escaped = array_map(function ($val) {
                    if (is_null($val)) return "NULL";
                    return "'" . $this->conn->real_escape_string($val) . "'";
                }, array_values($row_data));

                $sql_dump .= "INSERT INTO `{$tbl}` (`" . implode("`, `", $cols) . "`) VALUES (" . implode(", ", $escaped) . ");\n";
            }
            $sql_dump .= "\n";
        }

        $filename = 'backup_nuviserpx_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $sql_dump;
        exit;
    }

    public function optimize_db()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }

        $tables = [];
        $res = $this->conn->query("SHOW TABLES");
        while ($row = $res->fetch_array()) {
            $tables[] = $row[0];
        }

        foreach ($tables as $tbl) {
            $this->conn->query("OPTIMIZE TABLE `{$tbl}`");
        }

        $this->settings->log_audit("Optimized Database", "System Settings", "Executed OPTIMIZE TABLE on " . count($tables) . " tables.");
        return json_encode(['status' => 'success', 'msg' => 'All database tables successfully optimized.']);
    }

    public function save_shift_type()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        if (empty($name) || empty($start_time) || empty($end_time)) {
            return json_encode(['status' => 'failed', 'msg' => 'Shift Name, Start Time, and End Time are required.']);
        }

        $s_name = $this->conn->real_escape_string($name);
        $s_start = $this->conn->real_escape_string($start_time);
        $s_end = $this->conn->real_escape_string($end_time);
        $s_break = intval($break_duration_mins ?? 30);

        $save = $this->conn->query("
            INSERT INTO `shift_types` (`tenant_id`, `name`, `start_time`, `end_time`, `break_duration_mins`, `status`)
            VALUES ('{$tenant_id}', '{$s_name}', '{$s_start}', '{$s_end}', '{$s_break}', 1)
        ");

        if ($save) {
            $this->settings->log_audit("Created Shift Type", "HR & Attendance", "Shift: {$name} ({$start_time} - {$end_time})");
            return json_encode(['status' => 'success', 'msg' => 'Shift type saved successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save shift type. ' . $this->conn->error]);
    }

    public function save_shift_assignment()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        $emp_id = intval($employee_id ?? 0);
        $shift_id = intval($shift_type_id ?? 0);

        if ($emp_id <= 0 || $shift_id <= 0 || empty($start_date)) {
            return json_encode(['status' => 'failed', 'msg' => 'Employee, Shift Type, and Start Date are required.']);
        }

        $s_date = $this->conn->real_escape_string($start_date);
        $e_date = !empty($end_date) ? "'{$this->conn->real_escape_string($end_date)}'" : "NULL";

        $save = $this->conn->query("
            INSERT INTO `shift_assignments` (`tenant_id`, `employee_id`, `shift_type_id`, `start_date`, `end_date`, `status`)
            VALUES ('{$tenant_id}', '{$emp_id}', '{$shift_id}', '{$s_date}', {$e_date}, 1)
        ");

        if ($save) {
            $this->settings->log_audit("Assigned Shift Roster", "HR & Attendance", "Employee ID #{$emp_id}, Shift ID #{$shift_id}");
            return json_encode(['status' => 'success', 'msg' => 'Shift roster assigned successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to assign shift roster.']);
    }

    public function save_salary_component()
    {
        $tenant_id = $this->settings->active_tenant_id();
        extract($_POST);

        if (empty($code) || empty($name)) {
            return json_encode(['status' => 'failed', 'msg' => 'Component Code and Name are required.']);
        }

        $c_code = $this->conn->real_escape_string(strtoupper(trim($code)));
        $c_name = $this->conn->real_escape_string(trim($name));
        $c_type = $this->conn->real_escape_string($type ?? 'earning');
        $c_tax = isset($is_taxable) ? 1 : 0;
        $c_fnpf = isset($is_fnpf_subject) ? 1 : 0;
        $c_amt = floatval($default_amount ?? 0);

        $save = $this->conn->query("
            INSERT INTO `salary_components` (`tenant_id`, `code`, `name`, `type`, `is_taxable`, `is_fnpf_subject`, `default_amount`, `status`)
            VALUES ('{$tenant_id}', '{$c_code}', '{$c_name}', '{$c_type}', '{$c_tax}', '{$c_fnpf}', '{$c_amt}', 1)
        ");

        if ($save) {
            $this->settings->log_audit("Created Salary Component", "Payroll", "Code: {$c_code}, Name: {$c_name}");
            return json_encode(['status' => 'success', 'msg' => 'Salary component created successfully.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to save salary component. ' . $this->conn->error]);
    }

    public function delete_salary_component()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $id = intval($_POST['id'] ?? 0);

        $del = $this->conn->query("DELETE FROM `salary_components` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
        if ($del) {
            $this->settings->log_audit("Deleted Salary Component", "Payroll", "ID #{$id}");
            return json_encode(['status' => 'success', 'msg' => 'Salary component deleted.']);
        }
        return json_encode(['status' => 'failed', 'msg' => 'Failed to delete salary component.']);
    }

    /* Journal Actions */
    public function save_journal()
    {
        return $this->container->make(JournalService::class)->saveJournal($_POST);
    }

    public function delete_journal()
    {
        return $this->container->make(JournalService::class)->deleteJournal(intval($_POST['id'] ?? 0));
    }

    public function cancel_journal()
    {
        return $this->container->make(JournalService::class)->cancelJournal(intval($_POST['id'] ?? 0));
    }

    /* Role / API Key Actions */
    public function save_role()
    {
        return $this->container->make(RoleService::class)->saveRole($_POST);
    }

    public function delete_role()
    {
        return $this->container->make(RoleService::class)->deleteRole(intval($_POST['id'] ?? 0));
    }

    public function save_api_key()
    {
        return $this->container->make(RoleService::class)->saveApiKey($_POST);
    }

    public function delete_api_key()
    {
        return $this->container->make(RoleService::class)->deleteApiKey(intval($_POST['id'] ?? 0));
    }

    /* Invoice Actions */
    public function save_invoice()
    {
        return $this->container->make(InvoiceService::class)->saveInvoice($_POST);
    }

    public function delete_invoice()
    {
        return $this->container->make(InvoiceService::class)->deleteInvoice(intval($_POST['id'] ?? 0));
    }

    public function email_invoice()
    {
        return $this->container->make(InvoiceService::class)->emailInvoice(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Payment Actions */
    public function save_payment()
    {
        return $this->container->make(PaymentService::class)->savePayment($_POST);
    }

    public function delete_payment()
    {
        return $this->container->make(PaymentService::class)->deletePayment(intval($_POST['id'] ?? 0));
    }

    public function email_payment_receipt()
    {
        return $this->container->make(PaymentService::class)->emailPaymentReceipt(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Inventory / Product / Sales Order Actions */
    public function save_product()
    {
        return $this->container->make(InventoryService::class)->saveProduct($_POST);
    }

    public function delete_product()
    {
        return $this->container->make(InventoryService::class)->deleteProduct(intval($_POST['id'] ?? 0));
    }

    public function save_stock_adjustment()
    {
        return $this->container->make(InventoryService::class)->saveStockAdjustment($_POST);
    }

    public function save_sales_order()
    {
        extract($_POST);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $order_no = !empty($order_no) ? $this->conn->real_escape_string((string)$order_no) : 'SO-' . date('Ym') . '-' . rand(1000, 9999);
        $party_id = $this->conn->real_escape_string((string)($party_id ?? 0));
        $order_date = !empty($order_date) ? $this->conn->real_escape_string((string)$order_date) : date('Y-m-d');
        $type = !empty($type) ? $this->conn->real_escape_string((string)$type) : 'proforma';
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : 'proforma';

        if (empty($id)) {
            $sql = "INSERT INTO `sales_order_list` SET
				`tenant_id` = '{$tenant_id}',
				`order_no` = '{$order_no}',
				`party_id` = '{$party_id}',
				`order_date` = '{$order_date}',
				`type` = '{$type}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}',
				`created_by` = '{$user_id}'";
            $save = $this->conn->query($sql);
            $sales_order_id = $this->conn->insert_id;
        } else {
            $sql = "UPDATE `sales_order_list` SET
				`order_no` = '{$order_no}',
				`party_id` = '{$party_id}',
				`order_date` = '{$order_date}',
				`type` = '{$type}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
            $save = $this->conn->query($sql);
            $sales_order_id = $id;
        }

        if ($save) {
            $this->conn->query("DELETE FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
            if (isset($item_name) && is_array($item_name)) {
                foreach ($item_name as $k => $v) {
                    $i_name = $this->conn->real_escape_string((string)$v);
                    if (empty($i_name)) continue;

                    $p_id = isset($product_id[$k]) && is_numeric($product_id[$k]) ? (int)$product_id[$k] : 'NULL';
                    $i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string((string)$item_desc[$k]) : '';
                    $i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0;
                    $i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0.0;
                    $i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0.0;
                    $i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
                    $i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

                    $item_sql = "INSERT INTO `sales_order_items` SET
						`sales_order_id` = '{$sales_order_id}',
						`product_id` = {$p_id},
						`item_name` = '{$i_name}',
						`description` = '{$i_desc}',
						`qty` = '{$i_qty}',
						`unit_price` = '{$i_price}',
						`tax_rate` = '{$i_tax_rate}',
						`tax_amount` = '{$i_tax_amt}',
						`amount` = '{$i_amount}'";
                    $this->conn->query($item_sql);
                }
            }

            if ($status === 'confirmed' || $status === 'converted_to_invoice') {
                $this->confirm_sales_order_internal($sales_order_id);
            }

            $resp['status'] = 'success';
            $resp['msg'] = empty($id) ? " Sales Order / Proforma Invoice created successfully." : " Order details updated successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while saving sales order.";
            $resp['err'] = $this->conn->error;
        }

        return json_encode($resp);
    }

    public function confirm_sales_order_internal($sales_order_id)
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $order_qry = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$sales_order_id}' AND tenant_id = '{$tenant_id}'");
        if (!$order_qry || $order_qry->num_rows == 0) return false;

        $order = $order_qry->fetch_assoc();

        if ($order['stock_adjusted'] == 0) {
            $items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
            if ($items) {
                while ($item = $items->fetch_assoc()) {
                    $pid = (int)$item['product_id'];
                    $qty = (float)$item['qty'];

                    if ($pid > 0 && $qty > 0) {
                        $prod = $this->conn->query("SELECT cost_price, stock_quantity FROM `product_list` WHERE id = '{$pid}'")->fetch_assoc();
                        $cost = isset($prod['cost_price']) ? (float)$prod['cost_price'] : 0.0;

                        $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` - {$qty} WHERE id = '{$pid}'");

                        $orderNoEsc = $this->conn->real_escape_string((string)$order['order_no']);
                        $this->conn->query("INSERT INTO `inventory_logs` SET
							`tenant_id` = '{$tenant_id}',
							`product_id` = '{$pid}',
							`type` = 'out',
							`qty` = '{$qty}',
							`unit_cost` = '{$cost}',
							`reference` = '{$orderNoEsc}',
							`notes` = 'Auto stock deduction on sales order confirmation',
							`created_by` = '{$user_id}'");
                    }
                }
            }

            $this->conn->query("UPDATE `sales_order_list` SET `stock_adjusted` = 1, `status` = 'confirmed' WHERE id = '{$sales_order_id}'");

            if (empty($order['invoice_id'])) {
                $inv_no = 'INV-' . str_replace(['SO-', 'PRO-'], '', $order['order_no']);

                $ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
                $ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

                $rev_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%sales%' OR `name` LIKE '%revenue%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
                $rev_acc_id = ($rev_acc && $rev_acc->num_rows > 0) ? $rev_acc->fetch_assoc()['id'] : 2;

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
                $journal_code = $prefix . $code;
                $journal_date = $order['order_date'];
                $description = $this->conn->real_escape_string("Sales Invoice {$inv_no} from Order {$order['order_no']}");

                $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
                $this->conn->query($j_sql);
                $journal_id = $this->conn->insert_id;

                $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$ar_acc_id}', '1', '{$order['total']}'),
					('{$journal_id}', '{$rev_acc_id}', '2', '{$order['total']}')";
                $this->conn->query($j_items);

                $dueDate = date('Y-m-d', strtotime($order['order_date'] . ' +30 days'));
                $notesEsc = $this->conn->real_escape_string((string)$order['notes']);
                $inv_sql = "INSERT INTO `invoice_list` SET
					`tenant_id` = '{$tenant_id}',
					`invoice_no` = '{$inv_no}',
					`party_id` = '{$order['party_id']}',
					`invoice_date` = '{$order['order_date']}',
					`due_date` = '{$dueDate}',
					`subtotal` = '{$order['subtotal']}',
					`tax_amount` = '{$order['tax_amount']}',
					`discount` = '{$order['discount']}',
					`total` = '{$order['total']}',
					`paid_amount` = '0.00',
					`balance` = '{$order['total']}',
					`status` = 'unpaid',
					`notes` = '{$notesEsc}',
					`journal_id` = '{$journal_id}',
					`created_by` = '{$user_id}'";
                $this->conn->query($inv_sql);
                $invoice_id = $this->conn->insert_id;

                $items2 = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
                if ($items2) {
                    while ($it2 = $items2->fetch_assoc()) {
                        $itNameEsc = $this->conn->real_escape_string((string)$it2['item_name']);
                        $itDescEsc = $this->conn->real_escape_string((string)$it2['description']);
                        $this->conn->query("INSERT INTO `invoice_items` SET
							`invoice_id` = '{$invoice_id}',
							`item_name` = '{$itNameEsc}',
							`description` = '{$itDescEsc}',
							`qty` = '{$it2['qty']}',
							`unit_price` = '{$it2['unit_price']}',
							`tax_rate` = '{$it2['tax_rate']}',
							`tax_amount` = '{$it2['tax_amount']}',
							`amount` = '{$it2['amount']}'");
                    }
                }

                $this->conn->query("UPDATE `sales_order_list` SET `invoice_id` = '{$invoice_id}', `status` = 'converted_to_invoice' WHERE id = '{$sales_order_id}'");
            }
        }
        return true;
    }

    public function confirm_sales_order()
    {
        extract($_POST);
        $id = $this->conn->real_escape_string((string)$id);
        if ($this->confirm_sales_order_internal($id)) {
            $resp['status'] = 'success';
            $resp['msg'] = " Sale confirmed, auto stock adjusted, and converted to Sales Invoice successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while confirming order.";
        }
        return json_encode($resp);
    }

    public function delete_sales_order()
    {
        extract($_POST);
        $id = $this->conn->real_escape_string((string)$id);
        $tenant_id = $this->settings->active_tenant_id();

        $order = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if ($order) {
            if ($order['stock_adjusted'] == 1) {
                $items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}'");
                if ($items) {
                    while ($it = $items->fetch_assoc()) {
                        $pid = (int)$it['product_id'];
                        $qty = (float)$it['qty'];
                        if ($pid > 0 && $qty > 0) {
                            $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$pid}'");
                        }
                    }
                }
            }
            $this->conn->query("DELETE FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
            $resp['status'] = 'success';
            $this->settings->set_flashdata('success', " Order deleted successfully.");
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "Order not found.";
        }
        return json_encode($resp);
    }

    /* Payroll Actions */
    public function calculate_payroll_ajax()
    {
        return $this->container->make(PayrollService::class)->calculatePayrollAjax($_POST);
    }

    public function save_payroll()
    {
        return $this->container->make(PayrollService::class)->savePayroll($_POST);
    }

    public function delete_payroll()
    {
        return $this->container->make(PayrollService::class)->deletePayroll(intval($_POST['id'] ?? 0));
    }

    public function email_payslip()
    {
        return $this->container->make(PayrollService::class)->emailPayslip(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Employee / Attendance / Leave Actions */
    public function save_employee()
    {
        return $this->container->make(EmployeeService::class)->saveEmployee($_POST);
    }

    public function delete_employee()
    {
        return $this->container->make(EmployeeService::class)->deleteEmployee(intval($_POST['id'] ?? 0));
    }

    public function save_attendance()
    {
        return $this->container->make(EmployeeService::class)->saveAttendance($_POST);
    }

    public function delete_attendance()
    {
        return $this->container->make(EmployeeService::class)->deleteAttendance(intval($_POST['id'] ?? 0));
    }

    public function save_leave()
    {
        return $this->container->make(EmployeeService::class)->saveLeave($_POST);
    }

    public function update_leave_status()
    {
        return $this->container->make(EmployeeService::class)->updateLeaveStatus(intval($_POST['id'] ?? 0), $_POST['status'] ?? 'Pending');
    }

    public function delete_leave()
    {
        return $this->container->make(EmployeeService::class)->deleteLeave(intval($_POST['id'] ?? 0));
    }

    /* Party / Tenant Actions */
    public function save_party()
    {
        return $this->container->make(PartyService::class)->saveParty($_POST);
    }

    public function delete_party()
    {
        return $this->container->make(PartyService::class)->deleteParty(intval($_POST['id'] ?? 0));
    }

    public function save_tenant()
    {
        return $this->container->make(TenantService::class)->saveTenant($_POST);
    }

    public function delete_tenant()
    {
        return $this->container->make(TenantService::class)->deleteTenant(intval($_POST['id'] ?? 0));
    }

    public function git_pull()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }
        $branch = trim((string)shell_exec('git rev-parse --abbrev-ref HEAD 2>&1'));
        if (empty($branch)) {
            $branch = 'main';
        }
        $output = shell_exec("git pull origin {$branch} 2>&1");
        return json_encode(['status' => 'success', 'msg' => 'Git pull executed.', 'output' => $output]);
    }

    public function git_fetch()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }
        $output = shell_exec("git fetch 2>&1 && git status 2>&1");
        return json_encode(['status' => 'success', 'msg' => 'Git fetch executed.', 'output' => $output]);
    }

    public function get_redis_status()
    {
        require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
        $redis = new \App\Services\RedisCacheService($this->settings);
        return json_encode($redis->getStatus());
    }

    public function test_redis_ping()
    {
        require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
        $redis = new \App\Services\RedisCacheService($this->settings);
        return json_encode($redis->benchmark());
    }

    public function start_redis_daemon()
    {
        require_once(__DIR__ . '/../src/Services/RedisCacheService.php');
        $redis = new \App\Services\RedisCacheService($this->settings);
        return json_encode($redis->startDaemon());
    }

    public function log_portal_activity($tenant_id, $party_id, $portal_user_id, $action, $details = '')
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ip_esc = $this->conn->real_escape_string($ip);
        $action_esc = $this->conn->real_escape_string($action);
        $details_esc = $this->conn->real_escape_string($details);
        $user_id_sql = $portal_user_id ? "'{$portal_user_id}'" : "NULL";

        $sql = "INSERT INTO `customer_portal_logs` (`tenant_id`, `party_id`, `portal_user_id`, `action`, `details`, `ip_address`)
                VALUES ('{$tenant_id}', '{$party_id}', {$user_id_sql}, '{$action_esc}', '{$details_esc}', '{$ip_esc}')";
        @$this->conn->query($sql);
    }

    public function send_customer_invitation()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $party_id  = intval($_POST['party_id'] ?? 0);

        $party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0");
        if (!$party_qry || $party_qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Customer contact not found.']);
        }

        $party = $party_qry->fetch_assoc();
        $email = trim($party['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json_encode(['status' => 'failed', 'msg' => 'Customer does not have a valid email address.']);
        }

        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

        $chk = $this->conn->query("SELECT id FROM `customer_portal_users` WHERE tenant_id = '{$tenant_id}' AND party_id = '{$party_id}'");
        if ($chk && $chk->num_rows > 0) {
            $portal_user = $chk->fetch_assoc();
            $portal_user_id = $portal_user['id'];
            $sql = "UPDATE `customer_portal_users` SET `email` = '{$email}', `invite_token` = '{$token}', `invite_expires_at` = '{$expires}', `status` = 0 WHERE id = '{$portal_user_id}'";
        } else {
            $sql = "INSERT INTO `customer_portal_users` (`tenant_id`, `party_id`, `email`, `invite_token`, `invite_expires_at`, `status`) VALUES ('{$tenant_id}', '{$party_id}', '{$email}', '{$token}', '{$expires}', 0)";
        }

        if ($this->conn->query($sql)) {
            if (empty($portal_user_id)) {
                $portal_user_id = $this->conn->insert_id;
            }

            $invite_url = base_url . "portal/login.php?token=" . $token;
            require_once(__DIR__ . '/Emailer.php');
            $sys = [];
            $sys_qry = $this->conn->query("SELECT meta_field, meta_value FROM `system_info`");
            while ($r = $sys_qry->fetch_assoc()) $sys[$r['meta_field']] = $r['meta_value'];

            \Emailer::send_portal_invitation($email, $party['name'], $invite_url, $sys);

            $this->log_portal_activity($tenant_id, $party_id, $portal_user_id, 'invitation_sent', "Portal invitation sent to {$email}");

            return json_encode([
                'status' => 'success',
                'msg' => 'Portal invitation successfully generated & emailed to ' . $email . '.',
                'invite_url' => $invite_url
            ]);
        }

        return json_encode(['status' => 'failed', 'msg' => 'Failed to generate invitation token.']);
    }

    public function customer_register()
    {
        $token    = trim($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($token) || empty($password)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please fill in all required fields.']);
        }

        if (strlen($password) < 6) {
            return json_encode(['status' => 'failed', 'msg' => 'Password must be at least 6 characters long.']);
        }

        $token_esc = $this->conn->real_escape_string($token);
        $qry = $this->conn->query("SELECT * FROM `customer_portal_users` WHERE `invite_token` = '{$token_esc}' AND `invite_expires_at` >= NOW() LIMIT 1");

        if (!$qry || $qry->num_rows == 0) {
            return json_encode(['status' => 'failed', 'msg' => 'Invalid or expired invitation link.']);
        }

        $user = $qry->fetch_assoc();
        $hashed_pass = password_hash($password, PASSWORD_BCRYPT);
        $pass_esc = $this->conn->real_escape_string($hashed_pass);

        $upd = "UPDATE `customer_portal_users` SET `password` = '{$pass_esc}', `status` = 1, `invite_token` = NULL, `invite_expires_at` = NULL WHERE id = '{$user['id']}'";
        if ($this->conn->query($upd)) {
            $_SESSION['customer_portal'] = [
                'id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'party_id' => $user['party_id'],
                'email' => $user['email']
            ];

            $this->log_portal_activity($user['tenant_id'], $user['party_id'], $user['id'], 'account_activated', "Customer registered password and activated portal account");

            return json_encode(['status' => 'success', 'msg' => 'Account successfully created and activated! Redirecting to dashboard...']);
        }

        return json_encode(['status' => 'failed', 'msg' => 'Failed to activate account.']);
    }

    public function customer_login()
    {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            return json_encode(['status' => 'failed', 'msg' => 'Please enter your email and password.']);
        }

        $email_esc = $this->conn->real_escape_string($email);
        $qry = $this->conn->query("SELECT * FROM `customer_portal_users` WHERE `email` = '{$email_esc}' AND `status` = 1 LIMIT 1");

        if ($qry && $qry->num_rows > 0) {
            $user = $qry->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['customer_portal'] = [
                    'id' => $user['id'],
                    'tenant_id' => $user['tenant_id'],
                    'party_id' => $user['party_id'],
                    'email' => $user['email']
                ];

                $this->conn->query("UPDATE `customer_portal_users` SET `last_login` = NOW() WHERE id = '{$user['id']}'");
                $this->log_portal_activity($user['tenant_id'], $user['party_id'], $user['id'], 'customer_login', "Customer logged into portal");

                return json_encode(['status' => 'success', 'msg' => 'Login successful!']);
            }
        }

        return json_encode(['status' => 'failed', 'msg' => 'Invalid email address or password.']);
    }

    public function get_portal_logs()
    {
        $tenant_id = $this->settings->active_tenant_id();
        $party_id  = intval($_POST['party_id'] ?? 0);

        $qry = $this->conn->query("SELECT * FROM `customer_portal_logs` WHERE tenant_id = '{$tenant_id}' AND party_id = '{$party_id}' ORDER BY date_created DESC LIMIT 50");
        $logs = [];
        if ($qry) {
            while ($row = $qry->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        return json_encode(['status' => 'success', 'data' => $logs]);
    }

    public function save_invoice_template()
    {
        $tenant_id     = $this->settings->active_tenant_id();
        $template_name = $this->conn->real_escape_string($_POST['template_name'] ?? 'standard');
        $bg_color      = $this->conn->real_escape_string($_POST['bg_color'] ?? '#ffffff');
        $label_color   = $this->conn->real_escape_string($_POST['label_color'] ?? '#0f172a');
        $font_color    = $this->conn->real_escape_string($_POST['font_color'] ?? '#334155');
        $font_family   = $this->conn->real_escape_string($_POST['font_family'] ?? 'sans-serif');

        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['tmp_name'] != '') {
            $fname = 'uploads/tenant_logo_' . $tenant_id . '_' . time() . '.png';
            $dir_path = base_app . $fname;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir_path)) {
                $logo_path = $fname;
            }
        }

        $logo_sql = $logo_path ? ", `logo_path` = '{$logo_path}'" : "";

        $check = $this->conn->query("SELECT id FROM `invoice_templates` WHERE tenant_id = '{$tenant_id}'");
        if ($check && $check->num_rows > 0) {
            $sql = "UPDATE `invoice_templates` SET `template_name` = '{$template_name}', `bg_color` = '{$bg_color}', `label_color` = '{$label_color}', `font_color` = '{$font_color}', `font_family` = '{$font_family}' {$logo_sql} WHERE tenant_id = '{$tenant_id}'";
        } else {
            $sql = "INSERT INTO `invoice_templates` (`tenant_id`, `template_name`, `bg_color`, `label_color`, `font_color`, `font_family`, `logo_path`) VALUES ('{$tenant_id}', '{$template_name}', '{$bg_color}', '{$label_color}', '{$font_color}', '{$font_family}', " . ($logo_path ? "'{$logo_path}'" : "NULL") . ")";
        }

        $save = $this->conn->query($sql);
        if ($save) {
            $this->log_audit("save_invoice_template", "Invoice Templates", "Saved template {$template_name} for tenant {$tenant_id}");
            return json_encode(['status' => 'success', 'msg' => 'Invoice template customization saved successfully!']);
        }

        return json_encode(['status' => 'failed', 'msg' => 'Failed to save template settings: ' . $this->conn->error]);
    }
}

$Master = new Master();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
switch ($action) {
    case 'save_group':
        echo $Master->save_group();
        break;
    case 'delete_group':
        echo $Master->delete_group();
        break;
    case 'save_account':
        echo $Master->save_account();
        break;
    case 'delete_account':
        echo $Master->delete_account();
        break;
    case 'save_journal':
        echo $Master->save_journal();
        break;
    case 'delete_journal':
        echo $Master->delete_journal();
        break;
    case 'cancel_journal':
        echo $Master->cancel_journal();
        break;
    case 'save_employee':
        echo $Master->save_employee();
        break;
    case 'delete_employee':
        echo $Master->delete_employee();
        break;
    case 'save_attendance':
        echo $Master->save_attendance();
        break;
    case 'delete_attendance':
        echo $Master->delete_attendance();
        break;
    case 'save_leave':
        echo $Master->save_leave();
        break;
    case 'update_leave_status':
        echo $Master->update_leave_status();
        break;
    case 'delete_leave':
        echo $Master->delete_leave();
        break;
    case 'calculate_payroll_ajax':
        echo $Master->calculate_payroll_ajax();
        break;
    case 'save_payroll':
        echo $Master->save_payroll();
        break;
    case 'delete_payroll':
        echo $Master->delete_payroll();
        break;
    case 'save_party':
        echo $Master->save_party();
        break;
    case 'delete_party':
        echo $Master->delete_party();
        break;
    case 'save_invoice':
        echo $Master->save_invoice();
        break;
    case 'delete_invoice':
        echo $Master->delete_invoice();
        break;
    case 'save_payment':
        echo $Master->save_payment();
        break;
    case 'delete_payment':
        echo $Master->delete_payment();
        break;
    case 'save_product':
        echo $Master->save_product();
        break;
    case 'delete_product':
        echo $Master->delete_product();
        break;
    case 'save_sales_order':
        echo $Master->save_sales_order();
        break;
    case 'confirm_sales_order':
        echo $Master->confirm_sales_order();
        break;
    case 'delete_sales_order':
        echo $Master->delete_sales_order();
        break;
    case 'save_stock_adjustment':
        echo $Master->save_stock_adjustment();
        break;
    case 'save_api_key':
        echo $Master->save_api_key();
        break;
    case 'delete_api_key':
        echo $Master->delete_api_key();
        break;
    case 'save_role':
        echo $Master->save_role();
        break;
    case 'delete_role':
        echo $Master->delete_role();
        break;
    case 'email_invoice':
        echo $Master->email_invoice();
        break;
    case 'email_payment_receipt':
        echo $Master->email_payment_receipt();
        break;
    case 'email_payslip':
        echo $Master->email_payslip();
        break;
    case 'save_tenant':
        echo $Master->save_tenant();
        break;
    case 'delete_tenant':
        echo $Master->delete_tenant();
        break;
    case 'git_pull':
        echo $Master->git_pull();
        break;
    case 'git_fetch':
        echo $Master->git_fetch();
        break;
    case 'get_redis_status':
        echo $Master->get_redis_status();
        break;
    case 'test_redis_ping':
        echo $Master->test_redis_ping();
        break;
    case 'start_redis_daemon':
        echo $Master->start_redis_daemon();
        break;
    case 'send_customer_invitation':
        echo $Master->send_customer_invitation();
        break;
    case 'customer_register':
        echo $Master->customer_register();
        break;
    case 'customer_login':
        echo $Master->customer_login();
        break;
    case 'get_portal_logs':
        echo $Master->get_portal_logs();
        break;
    case 'save_invoice_template':
        echo $Master->save_invoice_template();
        break;
    case 'save_asset':
        echo $Master->save_asset();
        break;
    case 'delete_asset':
        echo $Master->delete_asset();
        break;
    case 'save_bank_account':
        echo $Master->save_bank_account();
        break;
    case 'save_opportunity':
        echo $Master->save_opportunity();
        break;
    case 'delete_opportunity':
        echo $Master->delete_opportunity();
        break;
    case 'save_proposal_signature':
        echo $Master->save_proposal_signature();
        break;
    case 'save_ticket':
        echo $Master->save_ticket();
        break;
    case 'reply_ticket':
        echo $Master->reply_ticket();
        break;
    case 'save_contract':
        echo $Master->save_contract();
        break;
    case 'save_warehouse':
        echo $Master->save_warehouse();
        break;
    case 'transfer_stock':
        echo $Master->transfer_stock();
        break;
    case 'save_expense_claim':
        echo $Master->save_expense_claim();
        break;
    case 'approve_expense_claim':
        echo $Master->approve_expense_claim();
        break;
    case 'save_module_settings':
        echo $Master->save_module_settings();
        break;
    case 'save_extrafield':
        echo $Master->save_extrafield();
        break;
    case 'delete_extrafield':
        echo $Master->delete_extrafield();
        break;
    case 'delete_accounts_bulk':
        echo $Master->delete_accounts_bulk();
        break;
    case 'archive_accounts_bulk':
        echo $Master->archive_accounts_bulk();
        break;
    case 'change_tax_rate_bulk':
        echo $Master->change_tax_rate_bulk();
        break;
    case 'save_currency':
        echo $Master->save_currency();
        break;
    case 'delete_currency':
        echo $Master->delete_currency();
        break;
    case 'backup_db':
        $Master->backup_db();
        break;
    case 'optimize_db':
        echo $Master->optimize_db();
        break;
    case 'save_shift_type':
        echo $Master->save_shift_type();
        break;
    case 'save_shift_assignment':
        echo $Master->save_shift_assignment();
        break;
    case 'save_salary_component':
        echo $Master->save_salary_component();
        break;
    case 'delete_salary_component':
        echo $Master->delete_salary_component();
        break;
    default:
        break;
}
