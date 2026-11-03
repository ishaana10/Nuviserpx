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
                $this->check_payroll_migrations();
                $this->check_invoicing_migrations();
                $this->check_stock_migrations();
                $this->check_api_migrations();
                $this->check_automation_migrations();
                $this->check_rbac_migrations();
                $this->check_invoice_template_migrations();
                $this->check_account_list_migrations();
                $this->check_customer_portal_migrations();
                $this->check_fixed_assets_migrations();
                $this->check_journal_options_migrations();
                $this->check_ledger_precision_migrations();
                $this->check_ledger_controls_migrations();
                $this->check_budget_migrations();
                $this->check_tax_migrations();
                $this->check_fx_migrations();
                $this->check_tenant_integrity_migrations();
                $this->check_dolibarr_crm_migrations();
                $this->check_dolibarr_extended_migrations();
                $this->check_extrafields_migrations();
                $this->check_currency_migrations();
                $this->check_frappe_hrms_migrations();
                $this->check_vms_v3_migrations();
                $this->check_frcs_accreditation_migrations();
                $this->check_2026_statutory_migrations();
            }

            // Run versioned migrations
            if (class_exists('App\Database\MigrationRunner')) {
                $runner = new \App\Database\MigrationRunner($this->conn);
                $runner->run(base_app . 'database/migrations');
            }
        }    
        
    }

    public function check_2026_statutory_migrations(){
        if (!$this->conn) return;

        // Employee List Statutory Columns
        $emp_cols = array(
            'birth_count_tracker' => "ALTER TABLE `employee_list` ADD COLUMN `birth_count_tracker` int(3) NOT NULL DEFAULT 0 AFTER `bank_account`",
            'ytd_opening_gross' => "ALTER TABLE `employee_list` ADD COLUMN `ytd_opening_gross` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `birth_count_tracker`",
            'ytd_opening_paye' => "ALTER TABLE `employee_list` ADD COLUMN `ytd_opening_paye` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `ytd_opening_gross`",
            'fnpf_employer_voluntary' => "ALTER TABLE `employee_list` ADD COLUMN `fnpf_employer_voluntary` decimal(5,2) NOT NULL DEFAULT 0.00 AFTER `ytd_opening_paye`"
        );

        foreach ($emp_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `employee_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        // Tax Brackets Table
        $sql_brackets = "CREATE TABLE IF NOT EXISTS `tax_brackets` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `residency_status` enum('RESIDENT','NON_RESIDENT') NOT NULL DEFAULT 'RESIDENT',
          `band` int(3) NOT NULL,
          `min_income` decimal(12,2) NOT NULL DEFAULT 0.00,
          `max_income` decimal(12,2) NOT NULL DEFAULT 0.00,
          `base_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
          `rate_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Payroll Run Detailed Lines Table
        $sql_lines = "CREATE TABLE IF NOT EXISTS `payroll_run_lines` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `payroll_id` int(30) NOT NULL,
          `employee_id` int(30) NOT NULL,
          `pay_period_n` int(3) NOT NULL DEFAULT 1,
          `normal_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
          `overtime_1_5_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
          `overtime_1_5_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `overtime_2_0_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
          `overtime_2_0_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `allowances_taxable` decimal(12,2) NOT NULL DEFAULT 0.00,
          `allowances_nontaxable` decimal(12,2) NOT NULL DEFAULT 0.00,
          `lump_sum_payments` decimal(12,2) NOT NULL DEFAULT 0.00,
          `redundancy_payments` decimal(12,2) NOT NULL DEFAULT 0.00,
          `annual_leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `sick_leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `bereavement_leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `family_care_leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `maternity_leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `paternity_leave_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `paye_normal` decimal(12,2) NOT NULL DEFAULT 0.00,
          `paye_lump_sum` decimal(12,2) NOT NULL DEFAULT 0.00,
          `fnpf_emp_mandatory` decimal(12,2) NOT NULL DEFAULT 0.00,
          `fnpf_emp_voluntary` decimal(12,2) NOT NULL DEFAULT 0.00,
          `fnpf_er_mandatory` decimal(12,2) NOT NULL DEFAULT 0.00,
          `fnpf_er_voluntary_excess` decimal(12,2) NOT NULL DEFAULT 0.00,
          `fnu_levy_er` decimal(12,2) NOT NULL DEFAULT 0.00,
          `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `payroll_id` (`payroll_id`),
          KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql_brackets);
        @$this->conn->query($sql_lines);
    }

    public function check_payroll_migrations(){
        if (!$this->conn) return;

        // Check employee_list columns
        $emp_cols = array(
            'tin' => "ALTER TABLE `employee_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL AFTER `salary`",
            'fnpf_no' => "ALTER TABLE `employee_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL AFTER `tin`",
            'tax_code' => "ALTER TABLE `employee_list` ADD COLUMN `tax_code` varchar(10) NOT NULL DEFAULT 'P' AFTER `fnpf_no`",
            'is_resident' => "ALTER TABLE `employee_list` ADD COLUMN `is_resident` tinyint(1) NOT NULL DEFAULT 1 AFTER `tax_code`",
            'bank_code' => "ALTER TABLE `employee_list` ADD COLUMN `bank_code` varchar(20) DEFAULT 'bsp' AFTER `is_resident`",
            'bank_account' => "ALTER TABLE `employee_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL AFTER `bank_code`",
            'approver_id' => "ALTER TABLE `employee_list` ADD COLUMN `approver_id` int(11) DEFAULT NULL AFTER `bank_account`"
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

    /**
     * Ledger data-model & precision hardening:
     *  1. journal_items.amount  float -> DECIMAL(18,4)   (stored 4dp; the app displays 2dp)
     *  2. journal_items.entry_side ENUM('debit','credit') — an explicit side, no longer
     *     riding on group_id; backfilled from the legacy group_id encoding.
     *  3. account_list.normal_balance ENUM('debit','credit') — the account's natural side,
     *     set from its type (classify() still governs the statements at runtime).
     *  4. Backfill any missing account codes with a class-banded number.
     *  5. Seed one "Opening Balance Equity" account per tenant (contra for opening journals).
     * Every step is guarded so re-running is a no-op.
     */
    public function check_ledger_precision_migrations(){
        if (!$this->conn) return;

        // 1. Money precision.
        $res = @$this->conn->query("SHOW COLUMNS FROM `journal_items` LIKE 'amount'");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && stripos((string)($row['Type'] ?? ''), 'decimal(18,4)') === false) {
            @$this->conn->query("ALTER TABLE `journal_items` MODIFY `amount` decimal(18,4) NOT NULL DEFAULT 0");
        }

        // 2. Explicit debit/credit side.
        $res = @$this->conn->query("SHOW COLUMNS FROM `journal_items` LIKE 'entry_side'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `journal_items` ADD COLUMN `entry_side` enum('debit','credit') DEFAULT NULL AFTER `group_id`");
            @$this->conn->query(
                "UPDATE `journal_items` ji LEFT JOIN `group_list` g ON g.id = ji.group_id
                 SET ji.entry_side = CASE
                        WHEN ji.group_id = 1 THEN 'debit'
                        WHEN ji.group_id = 2 THEN 'credit'
                        WHEN COALESCE(g.type, 1) = 2 THEN 'credit'
                        ELSE 'debit' END
                 WHERE ji.entry_side IS NULL"
            );
            @$this->conn->query("ALTER TABLE `journal_items` ADD INDEX `entry_side` (`entry_side`)");
        }

        // Rows written by older code paths (invoices, payments, payroll, purchases, API) only set group_id.
        // Best effort: fill entry_side for them. If the DB user lacks TRIGGER privilege this is skipped and
        // LedgerService::SIDE_SQL falls back to group_id, so balances stay correct either way.
        $trg = @$this->conn->query("SHOW TRIGGERS WHERE `Trigger` = 'journal_items_entry_side_bi'");
        if ($trg && $trg->num_rows == 0) {
            @$this->conn->query(
                "CREATE TRIGGER `journal_items_entry_side_bi` BEFORE INSERT ON `journal_items` FOR EACH ROW
                 SET NEW.entry_side = COALESCE(NEW.entry_side,
                     CASE WHEN NEW.group_id = 1 THEN 'debit'
                          WHEN NEW.group_id = 2 THEN 'credit'
                          WHEN (SELECT g.type FROM group_list g WHERE g.id = NEW.group_id) = 2 THEN 'credit'
                          ELSE 'debit' END)"
            );
        }

        // 3. Stored normal balance side on each account.
        $res = @$this->conn->query("SHOW COLUMNS FROM `account_list` LIKE 'normal_balance'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `account_list` ADD COLUMN `normal_balance` enum('debit','credit') NOT NULL DEFAULT 'debit' AFTER `type`");
            // Assets & expenses are debit-normal; liabilities, equity and income are credit-normal.
            @$this->conn->query(
                "UPDATE `account_list`
                 SET normal_balance = CASE
                        WHEN LOWER(type) REGEXP 'liabilit|payable|equity|capital|retain|draw|revenue|sales|income'
                             AND LOWER(type) NOT REGEXP 'asset|expense|cost|purchase|bank|cash|receivable|inventory|prepay|depreciat|overhead'
                        THEN 'credit' ELSE 'debit' END"
            );
        }

        // 4. Backfill missing account codes with a class-banded number.
        if (class_exists('App\\Services\\LedgerService')) {
            $bands = array('asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'income' => 4000, 'expense' => 5000);
            $res = @$this->conn->query("SELECT id, tenant_id, type FROM `account_list` WHERE code IS NULL OR code = ''");
            if ($res && $res->num_rows > 0) {
                $next = array();
                while ($a = $res->fetch_assoc()) {
                    $tid = (int)$a['tenant_id'];
                    $class = \App\Services\LedgerService::classify($a['type'] ?? '')['class'];
                    $base = $bands[$class] ?? 5000;
                    $key = $tid . ':' . $base;
                    if (!isset($next[$key])) {
                        $top = $base + 998; // 3999 is reserved for Opening Balance Equity
                        $mx = @$this->conn->query("SELECT MAX(CAST(code AS UNSIGNED)) m FROM `account_list` WHERE tenant_id = {$tid} AND CAST(code AS UNSIGNED) BETWEEN {$base} AND {$top}");
                        $cur = $mx ? (int)($mx->fetch_assoc()['m'] ?? 0) : 0;
                        $next[$key] = $cur >= $base ? $cur : $base - 10;
                    }
                    $next[$key] += 10;
                    $code = (string)$next[$key];
                    $id = (int)$a['id'];
                    @$this->conn->query("UPDATE `account_list` SET code = '{$code}' WHERE id = {$id} AND (code IS NULL OR code = '')");
                }
            }
        }

        // 5. Seed an Opening Balance Equity account per tenant (code 3999 avoids the +10 band sequence).
        @$this->conn->query(
            "INSERT INTO `account_list` (tenant_id, code, name, description, type, normal_balance, status, delete_flag)
             SELECT t.id, '3999', 'Opening Balance Equity', 'Opening Balance Equity', 'Equity', 'credit', 1, 0
             FROM `tenants` t
             WHERE NOT EXISTS (SELECT 1 FROM `account_list` a WHERE a.tenant_id = t.id AND a.name = 'Opening Balance Equity' AND a.delete_flag = 0)"
        );
    }

    /**
     * Ledger controls:
     *  - audit_logs: the table log_audit() writes to. It was never created by any schema or migration,
     *    so on a fresh install every audit entry was silently dropped. Append-only (triggers refuse
     *    UPDATE and DELETE, best effort).
     *  - period_locks: "books closed up to <date>" history; the newest row per company is the lock.
     *  - journal_entries.reverses_id: links a reversing journal to the journal it reverses.
     *  - triggers: refuse any journal write dated on or before the lock, whatever code path made it.
     * Everything is guarded, so re-running is a no-op. Trigger creation needs the TRIGGER privilege; without
     * it the application-level checks (PeriodLockService::guard) still apply.
     */
    public function check_ledger_controls_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `audit_logs` (
          `id` bigint(20) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `user_id` int(50) DEFAULT NULL,
          `action` varchar(150) NOT NULL,
          `module` varchar(100) NOT NULL,
          `details` text DEFAULT NULL,
          `ip_address` varchar(64) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_created` (`tenant_id`, `date_created`),
          KEY `module_idx` (`module`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `period_locks` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `lock_date` date DEFAULT NULL,
          `note` varchar(255) DEFAULT NULL,
          `locked_by` int(50) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_latest` (`tenant_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = @$this->conn->query("SHOW COLUMNS FROM `journal_entries` LIKE 'reverses_id'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `journal_entries` ADD COLUMN `reverses_id` int(30) DEFAULT NULL AFTER `status`");
            @$this->conn->query("ALTER TABLE `journal_entries` ADD INDEX `reverses_id` (`reverses_id`)");
        }

        // Newest lock row for a company (NULL when none or fully reopened).
        $lk = fn(string $tenantExpr) => "(SELECT p.lock_date FROM period_locks p WHERE p.tenant_id = {$tenantExpr} ORDER BY p.id DESC LIMIT 1)";
        $deny = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The books are closed for this date'";
        $jd = "(SELECT je.journal_date FROM journal_entries je WHERE je.id = %s.journal_id)";
        $jt = "(SELECT je.tenant_id FROM journal_entries je WHERE je.id = %s.journal_id)";

        $triggers = array(
            'journal_entries_lock_bi' => array('BEFORE INSERT', 'journal_entries',
                "BEGIN DECLARE lk DATE; SET lk = " . $lk('NEW.tenant_id') . ";
                 IF lk IS NOT NULL AND NEW.journal_date <= lk THEN {$deny}; END IF; END"),
            'journal_entries_lock_bu' => array('BEFORE UPDATE', 'journal_entries',
                "BEGIN DECLARE lk DATE; SET lk = " . $lk('OLD.tenant_id') . ";
                 IF lk IS NOT NULL AND (OLD.journal_date <= lk OR NEW.journal_date <= lk) THEN {$deny}; END IF; END"),
            'journal_entries_lock_bd' => array('BEFORE DELETE', 'journal_entries',
                "BEGIN DECLARE lk DATE; SET lk = " . $lk('OLD.tenant_id') . ";
                 IF lk IS NOT NULL AND OLD.journal_date <= lk THEN {$deny}; END IF; END"),
        );
        foreach (array('INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD') as $event => $row) {
            $triggers['journal_items_lock_b' . strtolower($event[0])] = array("BEFORE {$event}", 'journal_items',
                "BEGIN DECLARE jd DATE; DECLARE lk DATE;
                 SET jd = " . sprintf($jd, $row) . ";
                 SET lk = " . $lk(sprintf($jt, $row)) . ";
                 IF lk IS NOT NULL AND jd IS NOT NULL AND jd <= lk THEN {$deny}; END IF; END");
        }
        // The audit trail can be added to, never rewritten.
        $triggers['audit_logs_no_update'] = array('BEFORE UPDATE', 'audit_logs',
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log cannot be changed'");
        $triggers['audit_logs_no_delete'] = array('BEFORE DELETE', 'audit_logs',
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log cannot be changed'");

        foreach ($triggers as $name => [$when, $table, $body]) {
            $t = @$this->conn->query("SHOW TRIGGERS WHERE `Trigger` = '{$name}'");
            if ($t && $t->num_rows == 0) {
                @$this->conn->query("CREATE TRIGGER `{$name}` {$when} ON `{$table}` FOR EACH ROW {$body}");
            }
        }
    }

    /** Budgets: one row per profit-and-loss account per month (natural sign, see BudgetService). */
    public function check_budget_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `budget_lines` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `account_id` int(30) NOT NULL,
          `month_start` date NOT NULL,
          `amount` decimal(18,4) NOT NULL DEFAULT 0.0000,
          `updated_by` int(50) DEFAULT NULL,
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `budget_cell` (`tenant_id`, `account_id`, `month_start`),
          KEY `budget_month` (`tenant_id`, `month_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function check_tax_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `tax_codes` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `name` varchar(150) NOT NULL,
          `rate` decimal(7,4) NOT NULL DEFAULT 0.0000,
          `applies_to` enum('sale','purchase','both') NOT NULL DEFAULT 'both',
          `is_default` tinyint(1) NOT NULL DEFAULT 0,
          `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tax_tenant` (`tenant_id`, `delete_flag`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function check_fx_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `exchange_rates` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `currency_code` varchar(10) NOT NULL,
          `rate_date` date NOT NULL,
          `rate` decimal(18,8) NOT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `rate_day` (`tenant_id`, `currency_code`, `rate_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // A journal line may be in a foreign currency: the original amount is kept beside the base-currency amount.
        $cols = array(
            'currency'  => "ALTER TABLE `journal_items` ADD COLUMN `currency` varchar(10) DEFAULT NULL",
            'fx_rate'   => "ALTER TABLE `journal_items` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL",
            'amount_fc' => "ALTER TABLE `journal_items` ADD COLUMN `amount_fc` decimal(18,4) DEFAULT NULL",
        );
        foreach ($cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `journal_items` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    /**
     * Multi-tenant hardening for the ledger tables.
     *  - document_sequences: one atomic counter per company and series (journal numbers).
     *  - A company with no accounts gets the standard chart (new companies get it when created).
     *  - tenant_id indexes, a unique journal code per company, and foreign keys to tenants.
     * Every step is guarded and skipped, never forced: a unique index or foreign key is only added when the
     * existing data already satisfies it, so an old database with duplicates or orphans still starts.
     */
    public function check_tenant_integrity_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `document_sequences` (
          `tenant_id` int(30) NOT NULL,
          `seq_key` varchar(60) NOT NULL,
          `last_value` bigint(20) NOT NULL DEFAULT 0,
          PRIMARY KEY (`tenant_id`, `seq_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Companies with no chart of accounts.
        $empty = @$this->conn->query("SELECT t.id FROM tenants t
            WHERE t.delete_flag = 0 AND NOT EXISTS (SELECT 1 FROM account_list a WHERE a.tenant_id = t.id AND a.delete_flag = 0)");
        if ($empty) {
            foreach ($empty->fetch_all(MYSQLI_ASSOC) as $t) {
                \App\Services\ChartProvisioner::provision($this->conn, (int)$t['id']);
            }
        }

        $indexes = array(
            array('account_list', 'tenant_active', '(`tenant_id`, `delete_flag`)'),
            array('group_list', 'tenant_active', '(`tenant_id`, `delete_flag`)'),
            array('journal_entries', 'tenant_date', '(`tenant_id`, `journal_date`)'),
            array('journal_items', 'account_id', '(`account_id`)'),
        );
        foreach ($indexes as $ix) {
            $this->add_index_if_missing($ix[0], $ix[1], $ix[2]);
        }

        // One journal number per company. Only when no duplicates already exist.
        if ($this->table_exists('journal_entries') && !$this->index_exists('journal_entries', 'tenant_code')) {
            $dup = @$this->conn->query("SELECT 1 FROM journal_entries GROUP BY tenant_id, code HAVING COUNT(*) > 1 LIMIT 1");
            if ($dup && $dup->num_rows == 0) {
                @$this->conn->query("ALTER TABLE `journal_entries` ADD UNIQUE INDEX `tenant_code` (`tenant_id`, `code`)");
            }
        }

        foreach (array('account_list', 'group_list', 'journal_entries', 'invoice_list', 'vendor_bill_list', 'payment_list', 'budget_lines', 'tax_codes', 'exchange_rates', 'document_sequences', 'period_locks') as $tbl) {
            $this->add_tenant_fk($tbl);
        }
    }

    private function table_exists($table){
        $t = $this->conn->real_escape_string($table);
        $r = @$this->conn->query("SHOW TABLES LIKE '{$t}'");
        return $r && $r->num_rows > 0;
    }

    private function index_exists($table, $name){
        $r = @$this->conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '" . $this->conn->real_escape_string($name) . "'");
        return $r && $r->num_rows > 0;
    }

    private function add_index_if_missing($table, $name, $cols){
        if ($this->table_exists($table) && !$this->index_exists($table, $name)) {
            @$this->conn->query("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}");
        }
    }

    /** Foreign key tenant_id -> tenants(id), added only if the table is InnoDB and has no orphan rows. */
    private function add_tenant_fk($table){
        if (!$this->table_exists($table)) return;
        $fk = 'fk_' . $table . '_tenant';
        $db = $this->conn->real_escape_string($this->db_name());
        $r = @$this->conn->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$db}' AND TABLE_NAME = '{$table}' AND CONSTRAINT_NAME = '{$fk}'");
        if (!$r || $r->num_rows > 0) return;
        $eng = @$this->conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = '{$table}'");
        $engine = $eng ? ($eng->fetch_assoc()['ENGINE'] ?? '') : '';
        if (strcasecmp($engine, 'InnoDB') !== 0) return;
        $orph = @$this->conn->query("SELECT 1 FROM `{$table}` x LEFT JOIN tenants t ON t.id = x.tenant_id WHERE t.id IS NULL LIMIT 1");
        if (!$orph || $orph->num_rows > 0) return;
        if (!$this->index_exists($table, 'tenant_id') && !$this->index_exists($table, 'fk_tenant_idx')) {
            @$this->conn->query("ALTER TABLE `{$table}` ADD INDEX `fk_tenant_idx` (`tenant_id`)");
        }
        @$this->conn->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE");
    }

    private function db_name(){
        $r = @$this->conn->query("SELECT DATABASE() d");
        return $r ? (string)($r->fetch_assoc()['d'] ?? '') : '';
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
              `name` varchar(150) DEFAULT NULL,
              `event` varchar(100) NOT NULL,
              `target_url` varchar(255) NOT NULL,
              `secret_token` varchar(100) DEFAULT NULL,
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `max_retries` tinyint(2) NOT NULL DEFAULT 5,
              `last_triggered_at` datetime DEFAULT NULL,
              `last_status` varchar(20) DEFAULT NULL,
              `failure_count` int(11) NOT NULL DEFAULT 0,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_id` (`tenant_id`),
              KEY `event_status` (`event`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            /*
             * Webhook delivery queue + audit log.
             * status: pending -> delivering -> delivered | failed
             * Drained asynchronously by cron/webhook_worker.php, or inline as a fallback.
             */
            "CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `webhook_id` int(30) DEFAULT NULL,
              `event` varchar(100) NOT NULL,
              `target_url` varchar(255) NOT NULL,
              `payload` longtext DEFAULT NULL,
              `signature` varchar(255) DEFAULT NULL,
              `status` enum('pending','delivering','delivered','failed') NOT NULL DEFAULT 'pending',
              `attempts` int(11) NOT NULL DEFAULT 0,
              `max_retries` tinyint(2) NOT NULL DEFAULT 5,
              `response_code` int(11) DEFAULT NULL,
              `response_body` text DEFAULT NULL,
              `error` text DEFAULT NULL,
              `next_attempt_at` datetime DEFAULT NULL,
              `delivered_at` datetime DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `queue_scan` (`status`, `next_attempt_at`),
              KEY `tenant_event` (`tenant_id`, `event`),
              KEY `webhook_id` (`webhook_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            /* Lightweight API request log for the developer application-performance dashboard. */
            "CREATE TABLE IF NOT EXISTS `api_request_logs` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `api_key_id` int(30) DEFAULT NULL,
              `endpoint` varchar(150) NOT NULL,
              `method` varchar(10) NOT NULL,
              `status_code` int(11) NOT NULL DEFAULT 200,
              `duration_ms` int(11) NOT NULL DEFAULT 0,
              `ip_address` varchar(64) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_created` (`tenant_id`, `date_created`),
              KEY `endpoint_idx` (`endpoint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }

        // Non-destructive column upgrades for pre-existing `webhooks` tables.
        $webhook_cols = array(
            'name'              => "ALTER TABLE `webhooks` ADD COLUMN `name` varchar(150) DEFAULT NULL AFTER `tenant_id`",
            'max_retries'       => "ALTER TABLE `webhooks` ADD COLUMN `max_retries` tinyint(2) NOT NULL DEFAULT 5 AFTER `status`",
            'last_triggered_at' => "ALTER TABLE `webhooks` ADD COLUMN `last_triggered_at` datetime DEFAULT NULL AFTER `max_retries`",
            'last_status'       => "ALTER TABLE `webhooks` ADD COLUMN `last_status` varchar(20) DEFAULT NULL AFTER `last_triggered_at`",
            'failure_count'     => "ALTER TABLE `webhooks` ADD COLUMN `failure_count` int(11) NOT NULL DEFAULT 0 AFTER `last_status`",
            'date_updated'      => "ALTER TABLE `webhooks` ADD COLUMN `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp() AFTER `date_created`"
        );
        foreach ($webhook_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `webhooks` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    /**
     * Automation / Workflow engine + SaaS tenant subscription plans.
     * Backs the customisable workflow builder and the developer customer-plans panel.
     */
    public function check_automation_migrations(){
        if (!$this->conn) return;

        $queries = array(
            /*
             * A workflow = one trigger event + JSON conditions + JSON ordered actions.
             * conditions/actions are stored as JSON so the builder is fully customisable
             * without schema changes.
             */
            "CREATE TABLE IF NOT EXISTS `workflows` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `name` varchar(150) NOT NULL,
              `description` varchar(255) DEFAULT NULL,
              `trigger_event` varchar(100) NOT NULL,
              `conditions` longtext DEFAULT NULL,
              `actions` longtext DEFAULT NULL,
              `match_type` enum('all','any') NOT NULL DEFAULT 'all',
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `run_count` int(11) NOT NULL DEFAULT 0,
              `last_run_at` datetime DEFAULT NULL,
              `created_by` int(30) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `tenant_trigger` (`tenant_id`, `trigger_event`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `workflow_logs` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `tenant_id` int(30) NOT NULL DEFAULT 1,
              `workflow_id` int(30) NOT NULL,
              `trigger_event` varchar(100) NOT NULL,
              `matched` tinyint(1) NOT NULL DEFAULT 0,
              `actions_run` text DEFAULT NULL,
              `result` enum('success','partial','failed','skipped') NOT NULL DEFAULT 'success',
              `message` text DEFAULT NULL,
              `context_ref` varchar(100) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `workflow_idx` (`workflow_id`),
              KEY `tenant_created` (`tenant_id`, `date_created`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            /* SaaS subscription tiers assignable to each tenant/company. */
            "CREATE TABLE IF NOT EXISTS `tenant_plans` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `code` varchar(50) NOT NULL,
              `name` varchar(100) NOT NULL,
              `description` varchar(255) DEFAULT NULL,
              `price` decimal(12,2) NOT NULL DEFAULT 0.00,
              `billing_cycle` enum('monthly','quarterly','yearly','one_time') NOT NULL DEFAULT 'monthly',
              `max_users` int(11) NOT NULL DEFAULT 0,
              `max_invoices` int(11) NOT NULL DEFAULT 0,
              `max_api_calls` int(11) NOT NULL DEFAULT 0,
              `features` longtext DEFAULT NULL,
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `sort_order` int(11) NOT NULL DEFAULT 0,
              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
              `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `plan_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }

        // Plan assignment columns on the existing `tenants` table.
        $tenant_cols = array(
            'plan_id'          => "ALTER TABLE `tenants` ADD COLUMN `plan_id` int(30) DEFAULT NULL AFTER `status`",
            'plan_started_at'  => "ALTER TABLE `tenants` ADD COLUMN `plan_started_at` date DEFAULT NULL AFTER `plan_id`",
            'plan_expires_at'  => "ALTER TABLE `tenants` ADD COLUMN `plan_expires_at` date DEFAULT NULL AFTER `plan_started_at`",
            'modules'          => "ALTER TABLE `tenants` ADD COLUMN `modules` text DEFAULT NULL AFTER `status`",
            'plan_status'      => "ALTER TABLE `tenants` ADD COLUMN `plan_status` enum('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'active' AFTER `plan_expires_at`"
        );
        foreach ($tenant_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `tenants` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        // Seed default plans once.
        $has_plans = @$this->conn->query("SELECT id FROM `tenant_plans` LIMIT 1");
        if ($has_plans && $has_plans->num_rows == 0) {
            @$this->conn->query("INSERT INTO `tenant_plans` (`code`,`name`,`description`,`price`,`billing_cycle`,`max_users`,`max_invoices`,`max_api_calls`,`features`,`sort_order`) VALUES
              ('starter','Starter','For small businesses getting started',0.00,'monthly',3,50,1000,'[\"Invoicing\",\"Accounting\",\"1 Tenant\"]',1),
              ('professional','Professional','Growing teams with automation needs',99.00,'monthly',15,1000,50000,'[\"Everything in Starter\",\"Webhooks\",\"Workflow Automation\",\"API Access\"]',2),
              ('enterprise','Enterprise','Full platform with unlimited scale',299.00,'monthly',0,0,0,'[\"Everything in Professional\",\"Unlimited Users\",\"Unlimited Invoices\",\"Priority Support\"]',3)");
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

    public function check_frcs_accreditation_migrations(){
        if (!$this->conn) return;

        // 1. Tenant PKCS#12 SDC Certificates
        $sql1 = "CREATE TABLE IF NOT EXISTS `tenant_vsdc_certificates` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `cert_name` varchar(150) NOT NULL,
          `p12_path` varchar(255) NOT NULL,
          `cert_expires_at` datetime DEFAULT NULL,
          `serial_number` varchar(100) DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_uploaded` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 2. FRCS Non-Repudiation Audit Vault
        $sql2 = "CREATE TABLE IF NOT EXISTS `frcs_audit_vault` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `invoice_id` int(30) DEFAULT NULL,
          `invoice_no` varchar(100) NOT NULL,
          `sdc_invoice_no` varchar(100) NOT NULL,
          `invoice_type` int(2) NOT NULL DEFAULT 1,
          `transaction_type` int(2) NOT NULL DEFAULT 1,
          `mrc` varchar(100) NOT NULL,
          `verification_url` text NOT NULL,
          `raw_payload` longtext NOT NULL,
          `signature` text NOT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @$this->conn->query($sql1);
        @$this->conn->query($sql2);
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