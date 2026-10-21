<?php
if(!defined('DB_SERVER')){
    require_once("../initialize.php");
}
class DBConnection{

    private $host = DB_SERVER;
    private $username = DB_USERNAME;
    private $password = DB_PASSWORD;
    private $database = DB_NAME;
    
    public $conn;
    
    public function __construct(){

        if (!isset($this->conn)) {
            // Disable default mysqli exception throwing to handle errors gracefully
            if (function_exists('mysqli_report')) {
                mysqli_report(MYSQLI_REPORT_OFF);
            }

            // Attempt connection to host and database
            @$this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
            
            if ($this->conn->connect_error) {
                // If database does not exist or access failed, connect without database to create it
                @$server_conn = new mysqli($this->host, $this->username, $this->password);
                if ($server_conn->connect_error) {
                    die("<div style='padding:20px; font-family:sans-serif; background:#fee2e2; border:1px solid #f87171; color:#991b1b; border-radius:8px;'>
                        <h3 style='margin-top:0;'>Database Connection Failed</h3>
                        <p>Unable to connect to the MySQL database server at <b>{$this->host}</b> with username <b>{$this->username}</b>.</p>
                        <p><b>Error Details:</b> " . htmlspecialchars($server_conn->connect_error) . "</p>
                        <p>Please check your database credentials in <code>initialize.php</code>.</p>
                    </div>");
                }

                // Auto-create database
                $create_db_sql = "CREATE DATABASE IF NOT EXISTS `" . $this->database . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                if ($server_conn->query($create_db_sql)) {
                    $server_conn->close();
                    @$this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
                } else {
                    die("<div style='padding:20px; font-family:sans-serif; background:#fee2e2; border:1px solid #f87171; color:#991b1b; border-radius:8px;'>
                        <h3 style='margin-top:0;'>Database Auto-Creation Failed</h3>
                        <p>Could not auto-create database <b>{$this->database}</b>: " . htmlspecialchars($server_conn->error) . "</p>
                    </div>");
                }
            }

            // Auto-install schema if system_info table is missing
            $check_tbl = @$this->conn->query("SHOW TABLES LIKE 'system_info'");
            if (!$check_tbl || $check_tbl->num_rows == 0) {
                $this->auto_install_schema();
            } else {
                $this->check_fiji_payroll_migrations();
                $this->check_invoicing_migrations();
                $this->check_stock_migrations();
                $this->check_api_migrations();
                $this->check_rbac_migrations();
                $this->check_invoice_template_migrations();
                $this->check_account_list_migrations();
                $this->check_customer_portal_migrations();
                $this->check_fixed_assets_migrations();
                $this->check_journal_options_migrations();
                $this->check_dolibarr_crm_migrations();
                $this->check_dolibarr_extended_migrations();
                $this->check_extrafields_migrations();
                $this->check_currency_migrations();
                $this->check_frappe_hrms_migrations();
                $this->check_vms_v3_migrations();
            }

            // Run versioned migrations
            if (class_exists('App\Database\MigrationRunner')) {
                $runner = new \App\Database\MigrationRunner($this->conn);
                $runner->run(base_app . 'database/migrations');
            }
        }    
        
    }

    public function check_fiji_payroll_migrations(){
        if (!$this->conn) return;

        // Check employee_list columns
        $emp_cols = array(
            'tin' => "ALTER TABLE `employee_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL AFTER `salary`",
            'fnpf_no' => "ALTER TABLE `employee_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL AFTER `tin`",
            'tax_code' => "ALTER TABLE `employee_list` ADD COLUMN `tax_code` varchar(10) NOT NULL DEFAULT 'P' AFTER `fnpf_no`",
            'is_resident' => "ALTER TABLE `employee_list` ADD COLUMN `is_resident` tinyint(1) NOT NULL DEFAULT 1 AFTER `tax_code`",
            'bank_code' => "ALTER TABLE `employee_list` ADD COLUMN `bank_code` varchar(20) DEFAULT 'bsp' AFTER `is_resident`",
            'bank_account' => "ALTER TABLE `employee_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL AFTER `bank_code`"
        );

        foreach ($emp_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `employee_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        // Check payroll_list columns
        $pay_cols = array(
            'payroll_run_ref' => "ALTER TABLE `payroll_list` ADD COLUMN `payroll_run_ref` varchar(100) DEFAULT NULL AFTER `journal_id`",
            'pay_frequency' => "ALTER TABLE `payroll_list` ADD COLUMN `pay_frequency` varchar(20) NOT NULL DEFAULT 'Monthly' AFTER `salary_month`",
            'pay_date' => "ALTER TABLE `payroll_list` ADD COLUMN `pay_date` date DEFAULT NULL AFTER `pay_frequency`",
            'period_start' => "ALTER TABLE `payroll_list` ADD COLUMN `period_start` date DEFAULT NULL AFTER `pay_date`",
            'period_end' => "ALTER TABLE `payroll_list` ADD COLUMN `period_end` date DEFAULT NULL AFTER `period_start`",
            'overtime' => "ALTER TABLE `payroll_list` ADD COLUMN `overtime` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `basic_salary`",
            'other_earnings' => "ALTER TABLE `payroll_list` ADD COLUMN `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `allowances`",
            'gross_salary' => "ALTER TABLE `payroll_list` ADD COLUMN `gross_salary` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `other_earnings`",
            'fnpf_base' => "ALTER TABLE `payroll_list` ADD COLUMN `fnpf_base` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `gross_salary`",
            'employee_fnpf' => "ALTER TABLE `payroll_list` ADD COLUMN `employee_fnpf` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `fnpf_base`",
            'employer_fnpf' => "ALTER TABLE `payroll_list` ADD COLUMN `employer_fnpf` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `employee_fnpf`",
            'taxable_income' => "ALTER TABLE `payroll_list` ADD COLUMN `taxable_income` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `employer_fnpf`",
            'paye_tax' => "ALTER TABLE `payroll_list` ADD COLUMN `paye_tax` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `taxable_income`",
            'srt_tax' => "ALTER TABLE `payroll_list` ADD COLUMN `srt_tax` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `paye_tax`",
            'workcare_levy' => "ALTER TABLE `payroll_list` ADD COLUMN `workcare_levy` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `net_salary`",
            'training_levy' => "ALTER TABLE `payroll_list` ADD COLUMN `training_levy` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `workcare_levy`",
            'employer_cost' => "ALTER TABLE `payroll_list` ADD COLUMN `employer_cost` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `training_levy`",
            'tin' => "ALTER TABLE `payroll_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL AFTER `employer_cost`",
            'fnpf_no' => "ALTER TABLE `payroll_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL AFTER `tin`",
            'tax_code' => "ALTER TABLE `payroll_list` ADD COLUMN `tax_code` varchar(10) DEFAULT 'P' AFTER `fnpf_no`",
            'is_resident' => "ALTER TABLE `payroll_list` ADD COLUMN `is_resident` tinyint(1) DEFAULT 1 AFTER `tax_code`",
            'bank_code' => "ALTER TABLE `payroll_list` ADD COLUMN `bank_code` varchar(20) DEFAULT NULL AFTER `is_resident`",
            'bank_account' => "ALTER TABLE `payroll_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL AFTER `bank_code`"
        );

        foreach ($pay_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_invoicing_migrations(){
        if (!$this->conn) return;

        $queries = array(
            "CREATE TABLE IF NOT EXISTS `party_list` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `party_code` varchar(50) NOT NULL,
              `name` varchar(250) NOT NULL,
              `type` enum('customer','vendor') NOT NULL DEFAULT 'customer',
              `email` varchar(150) DEFAULT NULL,
              `phone` varchar(50) DEFAULT NULL,
              `tin` varchar(50) DEFAULT NULL,
              `address` text DEFAULT NULL,
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              UNIQUE KEY `party_tenant_code` (`tenant_id`, `party_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `invoice_list` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `invoice_no` varchar(100) NOT NULL,
              `party_id` int(30) NOT NULL,
              `invoice_date` date NOT NULL,
              `due_date` date DEFAULT NULL,
              `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
              `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `total` decimal(12,2) NOT NULL DEFAULT 0.00,
              `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
              `status` enum('draft','unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
              `notes` text DEFAULT NULL,
              `journal_id` int(30) DEFAULT NULL,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              KEY `party_id` (`party_id`),
              KEY `journal_id` (`journal_id`),
              CONSTRAINT `invoice_party_fk` FOREIGN KEY (`party_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `invoice_items` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `invoice_id` int(30) NOT NULL,
              `item_name` varchar(250) NOT NULL,
              `description` text DEFAULT NULL,
              `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
              `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
              `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
              `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              PRIMARY KEY (`id`),
              KEY `invoice_id` (`invoice_id`),
              CONSTRAINT `item_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoice_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `payment_list` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `payment_no` varchar(100) NOT NULL,
              `type` enum('receipt','payment') NOT NULL DEFAULT 'receipt',
              `party_id` int(30) NOT NULL,
              `bank_account_id` int(30) DEFAULT NULL,
              `payment_date` date NOT NULL,
              `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `method` varchar(50) NOT NULL DEFAULT 'cash',
              `reference` varchar(100) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'posted',
              `currency` varchar(10) NOT NULL DEFAULT 'FJD',
              `journal_id` int(30) DEFAULT NULL,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              KEY `party_id` (`party_id`),
              KEY `journal_id` (`journal_id`),
              CONSTRAINT `payment_party_fk` FOREIGN KEY (`party_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `payment_allocations` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `payment_id` int(30) NOT NULL,
              `invoice_id` int(30) NOT NULL,
              `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              PRIMARY KEY (`id`),
              KEY `payment_id` (`payment_id`),
              KEY `invoice_id` (`invoice_id`),
              CONSTRAINT `alloc_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payment_list` (`id`) ON DELETE CASCADE,
              CONSTRAINT `alloc_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoice_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }
    }

    public function check_customer_portal_migrations(){
        if (!$this->conn) return;

        $sql1 = "CREATE TABLE IF NOT EXISTS `customer_portal_users` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `party_id` int(30) NOT NULL,
          `email` varchar(150) NOT NULL,
          `password` varchar(255) DEFAULT NULL,
          `invite_token` varchar(100) DEFAULT NULL,
          `invite_expires_at` datetime DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 0,
          `last_login` datetime DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `party_id` (`party_id`),
          UNIQUE KEY `portal_email_tenant` (`tenant_id`, `email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql2 = "CREATE TABLE IF NOT EXISTS `customer_portal_logs` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `portal_user_id` int(30) DEFAULT NULL,
          `party_id` int(30) NOT NULL,
          `action` varchar(100) NOT NULL,
          `details` text DEFAULT NULL,
          `ip_address` varchar(50) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `party_id` (`party_id`),
          KEY `portal_user_id` (`portal_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql1);
        @$this->conn->query($sql2);
    }

    public function check_account_list_migrations(){
        if (!$this->conn) return;

        $acc_cols = array(
            'code' => "ALTER TABLE `account_list` ADD COLUMN `code` varchar(50) DEFAULT NULL AFTER `tenant_id`",
            'type' => "ALTER TABLE `account_list` ADD COLUMN `type` varchar(50) DEFAULT 'Expense' AFTER `name`",
            'tax_rate' => "ALTER TABLE `account_list` ADD COLUMN `tax_rate` varchar(100) DEFAULT 'Tax Exempt (0%)' AFTER `type`",
            'is_archived' => "ALTER TABLE `account_list` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`",
            'bank_name' => "ALTER TABLE `account_list` ADD COLUMN `bank_name` varchar(150) DEFAULT NULL AFTER `tax_rate`",
            'account_number' => "ALTER TABLE `account_list` ADD COLUMN `account_number` varchar(100) DEFAULT NULL AFTER `bank_name`",
            'currency' => "ALTER TABLE `account_list` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT 'FJD' AFTER `account_number`"
        );

        foreach ($acc_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `account_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_fixed_assets_migrations(){
        if (!$this->conn) return;

        $sql = "CREATE TABLE IF NOT EXISTS `fixed_assets` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `asset_number` varchar(50) NOT NULL,
          `asset_name` varchar(250) NOT NULL,
          `purchase_date` date DEFAULT NULL,
          `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00,
          `asset_type` varchar(100) DEFAULT NULL,
          `warranty_expiry` date DEFAULT NULL,
          `serial_number` varchar(100) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `attachment_path` varchar(255) DEFAULT NULL,
          `dep_start_date` date DEFAULT NULL,
          `dep_method` varchar(100) DEFAULT NULL,
          `averaging_method` varchar(100) DEFAULT NULL,
          `dep_calc_by` enum('rate','effective_life') DEFAULT 'rate',
          `dep_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
          `effective_life_years` decimal(5,2) NOT NULL DEFAULT 0.00,
          `cost_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
          `residual_value` decimal(12,2) NOT NULL DEFAULT 0.00,
          `status` enum('draft','registered','disposed') NOT NULL DEFAULT 'draft',
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          UNIQUE KEY `asset_tenant_num` (`tenant_id`, `asset_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql);
    }

    public function check_extrafields_migrations(){
        if (!$this->conn) return;

        $sql1 = "CREATE TABLE IF NOT EXISTS `custom_extrafields` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `element_type` varchar(100) NOT NULL,
          `field_code` varchar(100) NOT NULL,
          `field_label` varchar(200) NOT NULL,
          `field_type` enum('text','number','date','select') NOT NULL DEFAULT 'text',
          `field_options` text DEFAULT NULL,
          `is_required` tinyint(1) NOT NULL DEFAULT 0,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          UNIQUE KEY `extra_tenant_code` (`tenant_id`, `element_type`, `field_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql2 = "CREATE TABLE IF NOT EXISTS `custom_extrafield_values` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `element_type` varchar(100) NOT NULL,
          `element_id` int(30) NOT NULL,
          `field_code` varchar(100) NOT NULL,
          `field_value` text DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `elem_idx` (`element_type`, `element_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql1);
        @$this->conn->query($sql2);
    }

    public function check_dolibarr_extended_migrations(){
        if (!$this->conn) return;

        // 1. Support Tickets
        $sql_tickets = "CREATE TABLE IF NOT EXISTS `tickets` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `ticket_code` varchar(50) NOT NULL,
          `subject` varchar(250) NOT NULL,
          `party_id` int(30) DEFAULT NULL,
          `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
          `status` enum('new','in_progress','waiting_client','resolved','closed') NOT NULL DEFAULT 'new',
          `assigned_to` int(30) DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `party_id` (`party_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql_ticket_messages = "CREATE TABLE IF NOT EXISTS `ticket_messages` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `ticket_id` int(30) NOT NULL,
          `user_id` int(30) DEFAULT NULL,
          `message` text NOT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 2. Contracts & Subscriptions
        $sql_contracts = "CREATE TABLE IF NOT EXISTS `contracts` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `contract_no` varchar(100) NOT NULL,
          `title` varchar(250) NOT NULL,
          `party_id` int(30) NOT NULL,
          `billing_frequency` enum('monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `start_date` date NOT NULL,
          `end_date` date DEFAULT NULL,
          `status` enum('draft','active','expired','cancelled') NOT NULL DEFAULT 'active',
          `notes` text DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `party_id` (`party_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 3. Multi-Warehouses
        $sql_warehouses = "CREATE TABLE IF NOT EXISTS `warehouses` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `name` varchar(150) NOT NULL,
          `location` text DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql_warehouse_transfers = "CREATE TABLE IF NOT EXISTS `warehouse_transfers` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `from_warehouse_id` int(30) NOT NULL,
          `to_warehouse_id` int(30) NOT NULL,
          `product_id` int(30) NOT NULL,
          `qty` decimal(12,2) NOT NULL DEFAULT 0.00,
          `notes` text DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 4. Employee Expense Claims
        $sql_expenses = "CREATE TABLE IF NOT EXISTS `expense_reports` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `claim_no` varchar(50) NOT NULL,
          `employee_id` int(30) NOT NULL,
          `claim_date` date NOT NULL,
          `category` varchar(100) DEFAULT 'Travel & Meals',
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `description` text DEFAULT NULL,
          `attachment_path` varchar(255) DEFAULT NULL,
          `status` enum('pending','approved','reimbursed','rejected') NOT NULL DEFAULT 'pending',
          `approved_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql_tickets);
        @$this->conn->query($sql_ticket_messages);
        @$this->conn->query($sql_contracts);
        @$this->conn->query($sql_warehouses);
        @$this->conn->query($sql_warehouse_transfers);
        @$this->conn->query($sql_expenses);
    }

    public function check_dolibarr_crm_migrations(){
        if (!$this->conn) return;

        $sql1 = "CREATE TABLE IF NOT EXISTS `crm_opportunities` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `title` varchar(250) NOT NULL,
          `party_id` int(30) DEFAULT NULL,
          `stage` enum('qualification','proposal','negotiation','won','lost') NOT NULL DEFAULT 'qualification',
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `probability` int(3) NOT NULL DEFAULT 20,
          `expected_close_date` date DEFAULT NULL,
          `notes` text DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `party_id` (`party_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql2 = "CREATE TABLE IF NOT EXISTS `proposal_signatures` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `sales_order_id` int(30) NOT NULL,
          `signer_name` varchar(150) NOT NULL,
          `signer_email` varchar(150) NOT NULL,
          `signature_data` longtext NOT NULL,
          `signed_at` datetime NOT NULL DEFAULT current_timestamp(),
          `ip_address` varchar(50) DEFAULT NULL,
          `status` varchar(50) NOT NULL DEFAULT 'accepted',
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `sales_order_id` (`sales_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql1);
        @$this->conn->query($sql2);
    }

    public function check_journal_options_migrations(){
        if (!$this->conn) return;

        $j_cols = array(
            'auto_reversing_date' => "ALTER TABLE `journal_entries` ADD COLUMN `auto_reversing_date` date DEFAULT NULL AFTER `journal_date`",
            'default_narration' => "ALTER TABLE `journal_entries` ADD COLUMN `default_narration` tinyint(1) NOT NULL DEFAULT 1 AFTER `description`",
            'show_cash_basis' => "ALTER TABLE `journal_entries` ADD COLUMN `show_cash_basis` tinyint(1) NOT NULL DEFAULT 1 AFTER `default_narration`",
            'tax_mode' => "ALTER TABLE `journal_entries` ADD COLUMN `tax_mode` varchar(50) NOT NULL DEFAULT 'No Tax' AFTER `show_cash_basis`",
            'status' => "ALTER TABLE `journal_entries` ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'posted' AFTER `tax_mode`"
        );

        foreach ($j_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `journal_entries` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        $item_cols = array(
            'description' => "ALTER TABLE `journal_items` ADD COLUMN `description` text DEFAULT NULL AFTER `journal_id`",
            'tax_rate' => "ALTER TABLE `journal_items` ADD COLUMN `tax_rate` varchar(100) DEFAULT 'No Tax (0%)' AFTER `amount`"
        );

        foreach ($item_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `journal_items` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_invoice_template_migrations(){
        if (!$this->conn) return;

        $sql = "CREATE TABLE IF NOT EXISTS `invoice_templates` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `template_name` varchar(50) NOT NULL DEFAULT 'standard',
          `logo_path` varchar(255) DEFAULT NULL,
          `bg_color` varchar(20) NOT NULL DEFAULT '#ffffff',
          `label_color` varchar(20) NOT NULL DEFAULT '#0f172a',
          `font_color` varchar(20) NOT NULL DEFAULT '#334155',
          `font_family` varchar(50) NOT NULL DEFAULT 'sans-serif',
          `footer_notes` text DEFAULT NULL,
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `template_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql);
    }

    public function check_rbac_migrations(){
        if (!$this->conn) return;

        // Check roles table
        $sql = "CREATE TABLE IF NOT EXISTS `roles` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `name` varchar(150) NOT NULL,
          `description` text DEFAULT NULL,
          `permissions` text DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          UNIQUE KEY `role_tenant_name` (`tenant_id`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        @$this->conn->query($sql);

        // Check role_id column in users table
        $res = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE 'role_id'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `users` ADD COLUMN `role_id` int(30) DEFAULT NULL AFTER `type` ");
        }

        // Check employee_id column in users table
        $res_emp = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE 'employee_id'");
        if ($res_emp && $res_emp->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `users` ADD COLUMN `employee_id` int(30) DEFAULT NULL AFTER `role_id` ");
        }

        // Check tenant_id column in users table
        $res_t = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE 'tenant_id'");
        if ($res_t && $res_t->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `users` ADD COLUMN `tenant_id` int(30) NOT NULL DEFAULT 1 AFTER `id` ");
        }
    }

    public function check_api_migrations(){
        if (!$this->conn) return;

        $queries = array(
            "CREATE TABLE IF NOT EXISTS `api_keys` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `name` varchar(150) NOT NULL,
              `api_key` varchar(100) NOT NULL,
              `api_secret` varchar(100) NOT NULL,
              `permissions` text DEFAULT NULL,
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `last_used_at` datetime DEFAULT NULL,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              UNIQUE KEY `key_tenant` (`tenant_id`, `api_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `webhooks` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `event` varchar(100) NOT NULL,
              `target_url` varchar(255) NOT NULL,
              `secret_token` varchar(100) DEFAULT NULL,
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }
    }

    public function check_stock_migrations(){
        if (!$this->conn) return;

        $queries = array(
            "CREATE TABLE IF NOT EXISTS `product_list` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `product_code` varchar(50) NOT NULL,
              `name` varchar(250) NOT NULL,
              `category` varchar(100) DEFAULT NULL,
              `unit` varchar(50) NOT NULL DEFAULT 'pcs',
              `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
              `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
              `stock_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
              `reorder_level` decimal(12,2) NOT NULL DEFAULT 10.00,
              `description` text DEFAULT NULL,
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              UNIQUE KEY `prod_tenant_code` (`tenant_id`, `product_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `inventory_logs` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `product_id` int(30) NOT NULL,
              `type` enum('in','out','adjustment') NOT NULL DEFAULT 'in',
              `qty` decimal(12,2) NOT NULL DEFAULT 0.00,
              `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
              `reference` varchar(100) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              KEY `product_id` (`product_id`),
              CONSTRAINT `log_product_fk` FOREIGN KEY (`product_id`) REFERENCES `product_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `sales_order_list` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `order_no` varchar(100) NOT NULL,
              `party_id` int(30) NOT NULL,
              `order_date` date NOT NULL,
              `type` enum('proforma','sales_order') NOT NULL DEFAULT 'proforma',
              `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
              `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `total` decimal(12,2) NOT NULL DEFAULT 0.00,
              `status` enum('draft','proforma','confirmed','converted_to_invoice','cancelled') NOT NULL DEFAULT 'proforma',
              `stock_adjusted` tinyint(1) NOT NULL DEFAULT 0,
              `invoice_id` int(30) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              KEY `party_id` (`party_id`),
              KEY `invoice_id` (`invoice_id`),
              CONSTRAINT `order_party_fk` FOREIGN KEY (`party_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `sales_order_items` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `sales_order_id` int(30) NOT NULL,
              `product_id` int(30) DEFAULT NULL,
              `item_name` varchar(250) NOT NULL,
              `description` text DEFAULT NULL,
              `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
              `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
              `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
              `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              PRIMARY KEY (`id`),
              KEY `sales_order_id` (`sales_order_id`),
              KEY `product_id` (`product_id`),
              CONSTRAINT `item_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_order_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }
    }

    public function check_frappe_hrms_migrations(){
        if (!$this->conn) return;

        // 1. Shift Types
        $sql1 = "CREATE TABLE IF NOT EXISTS `shift_types` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `name` varchar(150) NOT NULL,
          `start_time` time NOT NULL,
          `end_time` time NOT NULL,
          `break_duration_mins` int(5) NOT NULL DEFAULT 30,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 2. Shift Assignments
        $sql2 = "CREATE TABLE IF NOT EXISTS `shift_assignments` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `employee_id` int(30) NOT NULL,
          `shift_type_id` int(30) NOT NULL,
          `start_date` date NOT NULL,
          `end_date` date DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `employee_id` (`employee_id`),
          KEY `shift_type_id` (`shift_type_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 3. Salary Components / Payheads
        $sql3 = "CREATE TABLE IF NOT EXISTS `salary_components` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `code` varchar(50) NOT NULL,
          `name` varchar(150) NOT NULL,
          `type` enum('earning','deduction') NOT NULL DEFAULT 'earning',
          `is_taxable` tinyint(1) NOT NULL DEFAULT 1,
          `is_fnpf_subject` tinyint(1) NOT NULL DEFAULT 1,
          `default_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          UNIQUE KEY `comp_tenant_code` (`tenant_id`, `code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 4. Payroll Component Items
        $sql4 = "CREATE TABLE IF NOT EXISTS `payroll_component_items` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `payroll_id` int(30) NOT NULL,
          `component_id` int(30) NOT NULL,
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`id`),
          KEY `payroll_id` (`payroll_id`),
          KEY `component_id` (`component_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql1);
        @$this->conn->query($sql2);
        @$this->conn->query($sql3);
        @$this->conn->query($sql4);
    }

    public function check_vms_v3_migrations(){
        if (!$this->conn) return;

        // 1. Tenant VMS Configurations
        $sql1 = "CREATE TABLE IF NOT EXISTS `tenant_vms_config` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `sdc_url` varchar(255) NOT NULL DEFAULT 'http://localhost:8088/api/v1/sdc',
          `tin` varchar(50) NOT NULL DEFAULT '50-00000-0-0',
          `pos_operator_id` varchar(100) NOT NULL DEFAULT 'OPERATOR_01',
          `pac_code` varchar(50) DEFAULT NULL,
          `default_tax_group` varchar(10) NOT NULL DEFAULT 'A',
          `mode` enum('disabled','sandbox','production') NOT NULL DEFAULT 'sandbox',
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `vms_tenant_config` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 2. VMS Transaction Logs
        $sql2 = "CREATE TABLE IF NOT EXISTS `vms_logs` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `invoice_id` int(30) DEFAULT NULL,
          `invoice_no` varchar(100) NOT NULL,
          `sdc_invoice_no` varchar(100) DEFAULT NULL,
          `mrc` varchar(100) DEFAULT NULL,
          `verification_url` text DEFAULT NULL,
          `qr_code_data` text DEFAULT NULL,
          `request_json` longtext DEFAULT NULL,
          `response_json` longtext DEFAULT NULL,
          `status` enum('success','failed','queued') NOT NULL DEFAULT 'success',
          `error_msg` text DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 3. VMS Async Queues
        $sql3 = "CREATE TABLE IF NOT EXISTS `vms_queues` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `invoice_id` int(30) NOT NULL,
          `payload_json` longtext NOT NULL,
          `attempts` int(3) NOT NULL DEFAULT 0,
          `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql1);
        @$this->conn->query($sql2);
        @$this->conn->query($sql3);
    }

    public function check_currency_migrations(){
        if (!$this->conn) return;

        $sql = "CREATE TABLE IF NOT EXISTS `currencies` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `code` varchar(10) NOT NULL,
          `name` varchar(100) NOT NULL,
          `symbol` varchar(10) NOT NULL,
          `exchange_rate` decimal(12,6) NOT NULL DEFAULT 1.000000,
          `is_default` tinyint(1) NOT NULL DEFAULT 0,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          UNIQUE KEY `curr_tenant_code` (`tenant_id`, `code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql);
    }

    public function auto_install_schema(){
        $sql_file = base_app . 'database/nuviserpx.sql';
        if (file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            if (!empty($sql_content)) {
                if (@$this->conn->multi_query($sql_content)) {
                    do {
                        if ($result = $this->conn->store_result()) {
                            $result->free();
                        }
                    } while ($this->conn->more_results() && $this->conn->next_result());
                } else {
                    // Fallback to split execution if multi_query is restricted
                    $queries = preg_split("/;[\r\n]+/", $sql_content);
                    foreach ($queries as $query) {
                        $query = trim($query);
                        if (!empty($query)) {
                            @$this->conn->query($query);
                        }
                    }
                }
            }
        }
    }

    public function __destruct(){
        if (isset($this->conn) && $this->conn instanceof mysqli) {
            @$this->conn->close();
        }
    }
}
?>