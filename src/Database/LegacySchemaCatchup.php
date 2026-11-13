<?php

declare(strict_types=1);

namespace App\Database;

/**
 * The legacy runtime schema catch-up, moved verbatim out of DBConnection.
 * These 44 checks predate the versioned migrations in database/migrations
 * and still run on every connection before them. New schema changes belong
 * in a numbered migration, not here.
 */
class LegacySchemaCatchup
{
    private \mysqli $conn;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    /** Every check, in the order DBConnection ran them. */
    public function run(): void
    {
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
        $this->check_approval_migrations();
        $this->check_asset_depreciation_migrations();
        $this->check_bill_approval_migrations();
        $this->check_credit_note_migrations();
        $this->check_fx_document_migrations();
        $this->check_journal_trail_migrations();
        $this->check_bill_payment_migrations();
        $this->check_bill_payment_approval_migrations();
        $this->check_payroll_adjustment_migrations();
        $this->check_tax_return_migrations();
        $this->check_dolibarr_crm_migrations();
        $this->check_dolibarr_extended_migrations();
        $this->check_extrafields_migrations();
        $this->check_currency_migrations();
        $this->check_frappe_hrms_migrations();
        $this->check_vms_v3_migrations();
        $this->check_frcs_accreditation_migrations();
        $this->check_2026_statutory_migrations();
        $this->check_integrity_hardening_migrations();
        $this->check_inventory_accounting_migrations();
        $this->check_sales_account_migrations();
        $this->check_sales_return_migrations();
        $this->check_pricing_migrations();
        $this->check_credit_control_migrations();
        $this->check_quotation_migrations();
        $this->check_delivery_migrations();
        $this->check_ui_pref_migrations();
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
              `currency` varchar(10) NOT NULL DEFAULT '',
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
            'currency' => "ALTER TABLE `account_list` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT '' AFTER `account_number`"
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

    /** Pure: the SQL list of account types the chart offers, e.g. 'Bank','Current Asset',… */
    public static function account_type_sql_list(array $groups){
        $out = array();
        foreach ($groups as $types) {
            foreach ($types as $t) {
                $out[] = "'" . str_replace("'", "''", $t) . "'";
            }
        }
        return implode(',', $out);
    }

    /** True at most once a day per key, so heavy integrity sweeps do not run on every request. */
    private function once_a_day($key){
        $today = date('Y-m-d');
        $k = $this->conn->real_escape_string($key);
        $r = @$this->conn->query("SELECT meta_value FROM `system_info` WHERE meta_field = '{$k}' LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row && $row['meta_value'] === $today) return false;
        if ($row) {
            @$this->conn->query("UPDATE `system_info` SET meta_value = '{$today}' WHERE meta_field = '{$k}'");
        } else {
            @$this->conn->query("INSERT INTO `system_info` (meta_field, meta_value) VALUES ('{$k}', '{$today}')");
        }
        return true;
    }

    private function table_has_constraint($table, $name){
        $r = @$this->conn->query("SHOW CREATE TABLE `{$table}`");
        $row = $r ? $r->fetch_row() : null;
        return $row && stripos((string)$row[1], $name) !== false;
    }

    /**
     * Database-level integrity, so data that bypasses the app (imports, scripts, SQL) cannot break the ledger:
     *  1. account_list.type must be a chart-of-accounts type (CHECK constraint).
     *  2. account_list.code is required (NOT NULL + CHECK not empty).
     *  3. every tenant_id column references tenants(id).
     * Each step is added only when the existing data already satisfies it, so nothing is rejected or rewritten
     * silently; a step that is skipped is written to the error log with the reason. Runs at most once a day.
     */
    public function check_integrity_hardening_migrations(){
        if (!$this->conn || !$this->table_exists('account_list')) return;
        if (!$this->once_a_day('integrity_sweep')) return;

        // 1. Account type.
        if (class_exists('App\\Services\\AccountService') && !$this->table_has_constraint('account_list', 'chk_account_type')) {
            $list = self::account_type_sql_list(\App\Services\AccountService::ACCOUNT_TYPES);
            $bad = @$this->conn->query("SELECT type, COUNT(*) n FROM `account_list` WHERE delete_flag = 0 AND type NOT IN ({$list}) GROUP BY type LIMIT 5");
            if ($bad && $bad->num_rows === 0) {
                // Archived accounts keep whatever type they had, so the constraint covers live accounts only via a lenient check.
                $bad2 = @$this->conn->query("SELECT 1 FROM `account_list` WHERE type NOT IN ({$list}) LIMIT 1");
                if ($bad2 && $bad2->num_rows === 0) {
                    @$this->conn->query("ALTER TABLE `account_list` ADD CONSTRAINT `chk_account_type` CHECK (`type` IN ({$list}))");
                } else {
                    error_log('Nuvis: account type constraint skipped: deleted accounts carry unrecognised types.');
                }
            } elseif ($bad) {
                $found = array();
                while ($b = $bad->fetch_assoc()) $found[] = $b['type'] . ' (' . $b['n'] . ')';
                error_log('Nuvis: account type constraint skipped: unrecognised types in use: ' . implode(', ', $found));
            }
        }

        // 2. Account code.
        $col = @$this->conn->query("SHOW COLUMNS FROM `account_list` LIKE 'code'");
        $c = $col ? $col->fetch_assoc() : null;
        if ($c && ($c['Null'] ?? '') === 'YES') {
            $miss = @$this->conn->query("SELECT 1 FROM `account_list` WHERE code IS NULL OR TRIM(code) = '' LIMIT 1");
            if ($miss && $miss->num_rows === 0) {
                @$this->conn->query("ALTER TABLE `account_list` MODIFY `code` varchar(50) NOT NULL");
            } elseif ($miss) {
                error_log('Nuvis: account code requirement skipped: some accounts have no code.');
            }
        }
        if ($c && !$this->table_has_constraint('account_list', 'chk_account_code')) {
            $miss = @$this->conn->query("SELECT 1 FROM `account_list` WHERE TRIM(code) = '' LIMIT 1");
            if ($miss && $miss->num_rows === 0) {
                @$this->conn->query("ALTER TABLE `account_list` ADD CONSTRAINT `chk_account_code` CHECK (TRIM(`code`) <> '')");
            }
        }

        // 3. Tenant foreign keys on every table that has a tenant_id and does not have one yet.
        $db = $this->conn->real_escape_string($this->db_name());
        $r = @$this->conn->query("SELECT c.TABLE_NAME t FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
            WHERE c.TABLE_SCHEMA = '{$db}' AND c.COLUMN_NAME = 'tenant_id' AND c.TABLE_NAME <> 'tenants'
              AND NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME
                              AND k.COLUMN_NAME = 'tenant_id' AND k.REFERENCED_TABLE_NAME = 'tenants')");
        if ($r) {
            $tables = array();
            while ($row = $r->fetch_assoc()) $tables[] = $row['t'];
            foreach ($tables as $tbl) {
                $before = $this->table_has_constraint($tbl, 'fk_' . $tbl . '_tenant');
                $this->add_tenant_fk($tbl);
                if (!$before && !$this->table_has_constraint($tbl, 'fk_' . $tbl . '_tenant')) {
                    error_log("Nuvis: tenant foreign key skipped on {$tbl} (not InnoDB, or rows point at a missing tenant).");
                }
            }
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

    public function check_approval_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `approval_policies` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL,
          `doc_type` varchar(30) NOT NULL DEFAULT 'journal',
          `enabled` tinyint(1) NOT NULL DEFAULT 0,
          `threshold` decimal(18,2) NOT NULL DEFAULT 0.00,
          `allow_self` tinyint(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `policy_doc` (`tenant_id`, `doc_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = array(
            'approved_by'   => "ALTER TABLE `journal_entries` ADD COLUMN `approved_by` int(50) DEFAULT NULL",
            'approved_at'   => "ALTER TABLE `journal_entries` ADD COLUMN `approved_at` datetime DEFAULT NULL",
            'approval_note' => "ALTER TABLE `journal_entries` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL",
        );
        foreach ($cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `journal_entries` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_asset_depreciation_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `asset_depreciation` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `asset_id` int(30) NOT NULL,
          `period_end` date NOT NULL,
          `amount` decimal(18,4) NOT NULL DEFAULT 0.0000,
          `journal_id` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `asset_month` (`asset_id`, `period_end`),
          KEY `tenant_period` (`tenant_id`, `period_end`),
          KEY `journal_id` (`journal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = array(
            'disposal_date'       => "ALTER TABLE `fixed_assets` ADD COLUMN `disposal_date` date DEFAULT NULL",
            'disposal_proceeds'   => "ALTER TABLE `fixed_assets` ADD COLUMN `disposal_proceeds` decimal(18,4) DEFAULT NULL",
            'disposal_journal_id' => "ALTER TABLE `fixed_assets` ADD COLUMN `disposal_journal_id` int(30) DEFAULT NULL",
        );
        foreach ($cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `fixed_assets` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_bill_payment_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `bill_payments` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `payment_no` varchar(50) NOT NULL,
          `vendor_id` int(30) NOT NULL,
          `payment_date` date NOT NULL,
          `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
          `method` varchar(50) NOT NULL DEFAULT 'bank_transfer',
          `bank_account_id` int(30) DEFAULT NULL,
          `reference` varchar(100) DEFAULT NULL,
          `notes` varchar(500) DEFAULT NULL,
          `status` enum('posted','void') NOT NULL DEFAULT 'posted',
          `journal_id` int(30) DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `pay_tenant_no` (`tenant_id`, `payment_no`),
          KEY `vendor` (`tenant_id`, `vendor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `bill_payment_allocations` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `payment_id` int(30) NOT NULL,
          `bill_id` int(30) NOT NULL,
          `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`id`),
          KEY `payment` (`payment_id`),
          KEY `bill` (`bill_id`),
          CONSTRAINT `bpa_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `bill_payments` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function check_payroll_adjustment_migrations(){
        if (!$this->conn) return;

        // Back-pay waiting to be paid in an employee's next payroll
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `employee_id` int(30) NOT NULL,
          `effective_date` date NOT NULL,
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `reason` varchar(500) DEFAULT NULL,
          `status` enum('pending','applied','cancelled') NOT NULL DEFAULT 'pending',
          `created_by` int(30) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `applied_payroll_id` int(30) DEFAULT NULL,
          `applied_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `tenant_employee_status` (`tenant_id`,`employee_id`,`status`),
          KEY `applied_payroll_id` (`applied_payroll_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $r = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE 'retro_pay'");
        if ($r && $r->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `payroll_list` ADD COLUMN `retro_pay` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `other_earnings`");
        }

        // Payroll approval (maker/checker) and the tax-year-to-date lump tax
        $pl = array(
            'status'        => "ALTER TABLE `payroll_list` ADD COLUMN `status` enum('posted','pending_approval','rejected') NOT NULL DEFAULT 'posted'",
            'created_by'    => "ALTER TABLE `payroll_list` ADD COLUMN `created_by` int(30) DEFAULT NULL",
            'approved_by'   => "ALTER TABLE `payroll_list` ADD COLUMN `approved_by` int(30) DEFAULT NULL",
            'approved_at'   => "ALTER TABLE `payroll_list` ADD COLUMN `approved_at` datetime DEFAULT NULL",
            'approval_note' => "ALTER TABLE `payroll_list` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL",
            'paye_lump'     => "ALTER TABLE `payroll_list` ADD COLUMN `paye_lump` decimal(12,2) NOT NULL DEFAULT 0.00",
        );
        foreach ($pl as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        // Payroll status: installs from the base SQL have a tinyint here, so the enum above was never added.
        // Convert once (legacy numeric values count as posted) and allow 'voided' for reversed payroll.
        $st = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE 'status'");
        $stType = $st && ($stRow = $st->fetch_assoc()) ? strtolower((string)$stRow['Type']) : '';
        if ($stType !== '' && strpos($stType, 'voided') === false) {
            if (strpos($stType, 'enum') === false) {
                @$this->conn->query("ALTER TABLE `payroll_list` MODIFY `status` varchar(20) NOT NULL DEFAULT 'posted'");
                @$this->conn->query("UPDATE `payroll_list` SET `status` = 'posted' WHERE `status` NOT IN ('posted','pending_approval','rejected')");
            }
            @$this->conn->query("ALTER TABLE `payroll_list` MODIFY `status` enum('posted','pending_approval','rejected','voided') NOT NULL DEFAULT 'posted'");
        }

        // Unpaid leave / absence and salary components paid on a payroll (deductions column holds the total of other deductions).
        $pc = array(
            'unpaid_days'          => "ALTER TABLE `payroll_list` ADD COLUMN `unpaid_days` decimal(6,2) NOT NULL DEFAULT 0.00",
            'unpaid_deduction'     => "ALTER TABLE `payroll_list` ADD COLUMN `unpaid_deduction` decimal(12,2) NOT NULL DEFAULT 0.00",
            'component_earnings'   => "ALTER TABLE `payroll_list` ADD COLUMN `component_earnings` decimal(12,2) NOT NULL DEFAULT 0.00",
            'component_deductions' => "ALTER TABLE `payroll_list` ADD COLUMN `component_deductions` decimal(12,2) NOT NULL DEFAULT 0.00",
            'leave_payout'         => "ALTER TABLE `payroll_list` ADD COLUMN `leave_payout` decimal(12,2) NOT NULL DEFAULT 0.00",
            'redundancy_pay'       => "ALTER TABLE `payroll_list` ADD COLUMN `redundancy_pay` decimal(12,2) NOT NULL DEFAULT 0.00",
            'loan_recovery'        => "ALTER TABLE `payroll_list` ADD COLUMN `loan_recovery` decimal(12,2) NOT NULL DEFAULT 0.00",
        );
        foreach ($pc as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        // Leavers, and staff loans / advances recovered through payroll.
        $res = @$this->conn->query("SHOW COLUMNS FROM `employee_list` LIKE 'termination_date'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `employee_list` ADD COLUMN `termination_date` date DEFAULT NULL");
        }
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `employee_loans` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `employee_id` int(30) NOT NULL,
          `kind` enum('loan','advance') NOT NULL DEFAULT 'loan',
          `description` varchar(200) DEFAULT NULL,
          `principal` decimal(18,4) NOT NULL DEFAULT 0,
          `installment` decimal(18,4) NOT NULL DEFAULT 0,
          `start_date` date NOT NULL,
          `balance` decimal(18,4) NOT NULL DEFAULT 0,
          `status` enum('active','paid','cancelled') NOT NULL DEFAULT 'active',
          `journal_id` int(30) DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `loan_repayments` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `loan_id` int(30) NOT NULL,
          `payroll_id` int(30) NOT NULL,
          `amount` decimal(18,4) NOT NULL DEFAULT 0,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `loan_payroll` (`loan_id`, `payroll_id`),
          KEY `payroll_id` (`payroll_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_component_items` LIKE 'loan_id'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `payroll_component_items` ADD COLUMN `loan_id` int(30) DEFAULT NULL");
        }

        // Payroll amounts are held at the same precision as the ledger (DECIMAL(18,4)); the engine still rounds to the cent.
        foreach (array('basic_salary','overtime','allowances','other_earnings','retro_pay','gross_salary','fnpf_base','employee_fnpf','employer_fnpf','taxable_income','paye_tax','paye_lump','srt_tax','deductions','net_salary','workcare_levy','training_levy','employer_cost','unpaid_deduction','component_earnings','component_deductions','leave_payout','redundancy_pay','loan_recovery') as $mc) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE '{$mc}'");
            if ($res && ($mr = $res->fetch_assoc()) && stripos((string)$mr['Type'], 'decimal(12,2)') !== false) {
                @$this->conn->query("ALTER TABLE `payroll_list` MODIFY `{$mc}` decimal(18,4) NOT NULL DEFAULT 0");
            }
        }

        // Payroll rules belong to each company, not the installation.
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `payroll_rule_sets` (
          `tenant_id` int(30) NOT NULL,
          `setting` mediumtext NOT NULL,
          `updated_by` int(30) DEFAULT NULL,
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Net pay is paid as a separate step. Rows that existed before this were posted straight to the bank, so they
        // count as paid; new payroll starts as 'unpaid' (set explicitly on insert).
        $pp = array(
            'payment_status' => "ALTER TABLE `payroll_list` ADD COLUMN `payment_status` varchar(10) NOT NULL DEFAULT 'paid'",
            'payment_id'     => "ALTER TABLE `payroll_list` ADD COLUMN `payment_id` int(30) DEFAULT NULL",
        );
        foreach ($pp as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `payroll_payments` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `pay_date` date NOT NULL,
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `employees` int(11) NOT NULL DEFAULT 0,
          `bank_account_id` int(30) NOT NULL,
          `reference` varchar(100) DEFAULT NULL,
          `journal_id` int(30) DEFAULT NULL,
          `status` enum('posted','reversed') NOT NULL DEFAULT 'posted',
          `created_by` int(30) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `journal_id` (`journal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Payments of pension, tax and levy liabilities to the fund / authority
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `payroll_remittances` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `liability` varchar(20) NOT NULL,
          `remit_date` date NOT NULL,
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `bank_account_id` int(30) NOT NULL,
          `reference` varchar(100) DEFAULT NULL,
          `note` varchar(500) DEFAULT NULL,
          `journal_id` int(30) DEFAULT NULL,
          `status` enum('posted','reversed','pending_approval','rejected') NOT NULL DEFAULT 'posted',
          `created_by` int(30) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`,`liability`),
          KEY `journal_id` (`journal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $r = @$this->conn->query("SHOW COLUMNS FROM `payroll_remittances` LIKE 'status'");
        $col = $r ? $r->fetch_assoc() : null;
        if ($col && stripos((string)$col['Type'], 'pending_approval') === false) {
            @$this->conn->query("ALTER TABLE `payroll_remittances` MODIFY `status` enum('posted','reversed','pending_approval','rejected') NOT NULL DEFAULT 'posted'");
        }
        $rc = array(
            'approved_by'   => "ALTER TABLE `payroll_remittances` ADD COLUMN `approved_by` int(30) DEFAULT NULL",
            'approved_at'   => "ALTER TABLE `payroll_remittances` ADD COLUMN `approved_at` datetime DEFAULT NULL",
            'approval_note' => "ALTER TABLE `payroll_remittances` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL",
        );
        foreach ($rc as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_remittances` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_sales_account_migrations(){
        if (!$this->conn) return;
        // The accounts invoices, receipts and credit notes post to (see AccountDefaultsService).
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `account_defaults` (
          `tenant_id` int(30) NOT NULL,
          `role` varchar(30) NOT NULL,
          `account_id` int(30) NOT NULL,
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`tenant_id`,`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->add_tenant_fk('account_defaults');

        // Document numbers must not repeat within a company. Added only where the existing data already satisfies it,
        // so a company that has duplicates keeps working (the app refuses new duplicates either way).
        foreach (array('invoice_list' => array('uniq_invoice_no', 'invoice_no'), 'payment_list' => array('uniq_payment_no', 'payment_no')) as $table => $def) {
            if (!$this->table_exists($table) || $this->index_exists($table, $def[0])) continue;
            $dups = @$this->conn->query("SELECT 1 FROM `{$table}` GROUP BY tenant_id, `{$def[1]}` HAVING COUNT(*) > 1 LIMIT 1");
            if ($dups && $dups->num_rows == 0) {
                @$this->conn->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$def[0]}` (`tenant_id`, `{$def[1]}`)");
            }
        }
    }

    public function check_ui_pref_migrations(){
        if (!$this->conn) return;
        // Per-user look and feel (see UiPrefsService).
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `user_ui_prefs` (
          `user_id` int(30) NOT NULL,
          `prefs` text NOT NULL,
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function check_delivery_migrations(){
        if (!$this->conn) return;
        // Delivery notes and partial fulfilment of sales orders (see DeliveryService).
        if (!$this->table_exists('sales_order_list') || !$this->table_exists('sales_order_items')) return;
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `delivery_notes` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL,
          `dn_no` varchar(50) NOT NULL,
          `sales_order_id` int(30) NOT NULL,
          `party_id` int(30) NOT NULL,
          `delivery_date` date NOT NULL,
          `warehouse_id` int(30) NOT NULL DEFAULT 0,
          `status` enum('delivered','cancelled') NOT NULL DEFAULT 'delivered',
          `stock_journal_id` int(30) DEFAULT NULL,
          `notes` varchar(500) DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `dn_tenant_no` (`tenant_id`, `dn_no`),
          KEY `sales_order_id` (`sales_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->add_tenant_fk('delivery_notes');
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `delivery_note_items` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `delivery_note_id` int(30) NOT NULL,
          `order_item_id` int(30) NOT NULL,
          `product_id` int(30) DEFAULT NULL,
          `item_name` varchar(250) NOT NULL,
          `qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
          `unit_cost` decimal(14,4) DEFAULT NULL,
          `invoice_id` int(30) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `delivery_note_id` (`delivery_note_id`),
          KEY `order_item_id` (`order_item_id`),
          KEY `invoice_id` (`invoice_id`),
          CONSTRAINT `dni_note_fk` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = array(
            'sales_order_list'  => array('delivery_mode' => "ALTER TABLE `sales_order_list` ADD COLUMN `delivery_mode` tinyint(1) NOT NULL DEFAULT 0"),
            'sales_order_items' => array(
                'delivered_qty' => "ALTER TABLE `sales_order_items` ADD COLUMN `delivered_qty` decimal(12,4) NOT NULL DEFAULT 0.0000",
                'invoiced_qty'  => "ALTER TABLE `sales_order_items` ADD COLUMN `invoiced_qty` decimal(12,4) NOT NULL DEFAULT 0.0000",
            ),
        );
        foreach ($cols as $table => $defs) {
            foreach ($defs as $col => $sql) {
                $res = @$this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
                if ($res && $res->num_rows == 0) {
                    @$this->conn->query($sql);
                }
            }
        }
    }

    public function check_credit_control_migrations(){
        if (!$this->conn) return;
        // Customer credit limit and default payment terms (see CreditControlService).
        if (!$this->table_exists('party_list')) return;
        $cols = array(
            'credit_limit'       => "ALTER TABLE `party_list` ADD COLUMN `credit_limit` decimal(18,2) DEFAULT NULL",
            'payment_terms_days' => "ALTER TABLE `party_list` ADD COLUMN `payment_terms_days` int(11) DEFAULT NULL",
        );
        foreach ($cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `party_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_quotation_migrations(){
        if (!$this->conn) return;
        // Quotation validity and versions (see QuotationService).
        if (!$this->table_exists('sales_order_list')) return;
        $cols = array(
            'valid_until'   => "ALTER TABLE `sales_order_list` ADD COLUMN `valid_until` date DEFAULT NULL",
            'version'       => "ALTER TABLE `sales_order_list` ADD COLUMN `version` int(11) NOT NULL DEFAULT 1",
            'parent_id'     => "ALTER TABLE `sales_order_list` ADD COLUMN `parent_id` int(30) DEFAULT NULL",
            'quote_root_id' => "ALTER TABLE `sales_order_list` ADD COLUMN `quote_root_id` int(30) DEFAULT NULL",
            'decline_reason'=> "ALTER TABLE `sales_order_list` ADD COLUMN `decline_reason` varchar(250) DEFAULT NULL",
            'declined_at'   => "ALTER TABLE `sales_order_list` ADD COLUMN `declined_at` datetime DEFAULT NULL",
        );
        foreach ($cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `sales_order_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
        // The type list gains 'quote' (the order form already offers it) and the status list 'superseded' and 'declined'.
        $enums = array(
            'type'   => array('quote', "ALTER TABLE `sales_order_list` MODIFY `type` enum('proforma','quote','sales_order') NOT NULL DEFAULT 'proforma'"),
            'status' => array('superseded', "ALTER TABLE `sales_order_list` MODIFY `status` enum('draft','proforma','confirmed','converted_to_invoice','cancelled','superseded','declined') NOT NULL DEFAULT 'proforma'"),
        );
        foreach ($enums as $col => $def) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `sales_order_list` LIKE '{$col}'");
            $row = $res ? $res->fetch_assoc() : null;
            if ($row && stripos((string)$row['Type'], "'{$def[0]}'") === false) {
                @$this->conn->query($def[1]);
            }
        }
        $this->add_index_if_missing('sales_order_list', 'idx_quote_root', '(`quote_root_id`)');
    }

    public function check_pricing_migrations(){
        if (!$this->conn) return;
        // Customer price lists (see PriceListService) and the discount a line carried when it was sold.
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `price_lists` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL,
          `name` varchar(100) NOT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `price_list_name` (`tenant_id`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->add_tenant_fk('price_lists');
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `price_list_items` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL,
          `price_list_id` int(30) NOT NULL,
          `product_id` int(30) NOT NULL,
          `min_qty` decimal(12,4) NOT NULL DEFAULT 1.0000,
          `price` decimal(18,4) NOT NULL DEFAULT 0.0000,
          PRIMARY KEY (`id`),
          UNIQUE KEY `list_product_qty` (`price_list_id`, `product_id`, `min_qty`),
          KEY `product` (`product_id`),
          CONSTRAINT `pli_list_fk` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->add_tenant_fk('price_list_items');
        $cols = array(
            'party_list'    => array('price_list_id' => "ALTER TABLE `party_list` ADD COLUMN `price_list_id` int(30) DEFAULT NULL"),
            'invoice_items' => array('discount_pct' => "ALTER TABLE `invoice_items` ADD COLUMN `discount_pct` decimal(7,4) NOT NULL DEFAULT 0.0000"),
        );
        foreach ($cols as $table => $defs) {
            if (!$this->table_exists($table)) continue;
            foreach ($defs as $col => $sql) {
                $res = @$this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
                if ($res && $res->num_rows == 0) {
                    @$this->conn->query($sql);
                }
            }
        }
    }

    public function check_sales_return_migrations(){
        if (!$this->conn) return;
        // Goods that come back with a credit note: which product, how many, at what cost, and the stock journal.
        $cols = array(
            'credit_note_lines' => array(
                'product_id' => "ALTER TABLE `credit_note_lines` ADD COLUMN `product_id` int(30) DEFAULT NULL",
                'return_qty' => "ALTER TABLE `credit_note_lines` ADD COLUMN `return_qty` decimal(12,4) DEFAULT NULL",
                'unit_cost'  => "ALTER TABLE `credit_note_lines` ADD COLUMN `unit_cost` decimal(14,4) DEFAULT NULL",
            ),
            'credit_notes' => array(
                'stock_journal_id'   => "ALTER TABLE `credit_notes` ADD COLUMN `stock_journal_id` int(30) DEFAULT NULL",
                'stock_warehouse_id' => "ALTER TABLE `credit_notes` ADD COLUMN `stock_warehouse_id` int(30) DEFAULT NULL",
            ),
        );
        foreach ($cols as $table => $defs) {
            if (!$this->table_exists($table)) continue;
            foreach ($defs as $col => $sql) {
                $res = @$this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
                if ($res && $res->num_rows == 0) {
                    @$this->conn->query($sql);
                }
            }
        }
    }

    public function check_inventory_accounting_migrations(){
        if (!$this->conn) return;
        // Per-company switch and account choices for posting stock to the ledger (see InventoryAccountingService).
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `inventory_settings` (
          `tenant_id` int(30) NOT NULL,
          `enabled` tinyint(1) NOT NULL DEFAULT 0,
          `inventory_account_id` int(30) DEFAULT NULL,
          `cogs_account_id` int(30) DEFAULT NULL,
          `adjustment_account_id` int(30) DEFAULT NULL,
          `offset_account_id` int(30) DEFAULT NULL,
          `enabled_on` date DEFAULT NULL,
          `opening_journal_id` int(30) DEFAULT NULL,
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->add_tenant_fk('inventory_settings');

        $cols = array(
            'invoice_items' => array(
                'product_id' => "ALTER TABLE `invoice_items` ADD COLUMN `product_id` int(30) DEFAULT NULL AFTER `invoice_id`",
                'unit_cost'  => "ALTER TABLE `invoice_items` ADD COLUMN `unit_cost` decimal(14,4) DEFAULT NULL",
            ),
            'invoice_list' => array(
                'cogs_journal_id' => "ALTER TABLE `invoice_list` ADD COLUMN `cogs_journal_id` int(30) DEFAULT NULL",
                'stock_warehouse_id' => "ALTER TABLE `invoice_list` ADD COLUMN `stock_warehouse_id` int(30) NOT NULL DEFAULT 0",
            ),
            'inventory_logs' => array(
                'warehouse_id' => "ALTER TABLE `inventory_logs` ADD COLUMN `warehouse_id` int(30) NOT NULL DEFAULT 0 AFTER `product_id`",
                'journal_id'   => "ALTER TABLE `inventory_logs` ADD COLUMN `journal_id` int(30) DEFAULT NULL",
            ),
        );
        foreach ($cols as $table => $adds) {
            if (!$this->table_exists($table)) continue;
            foreach ($adds as $col => $sql) {
                $r = @$this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
                if ($r && $r->num_rows == 0) {
                    @$this->conn->query($sql);
                }
            }
        }
        $this->add_index_if_missing('invoice_items', 'idx_invoice_items_product', '(`product_id`)');
        $this->add_index_if_missing('invoice_list', 'idx_invoice_cogs_journal', '(`cogs_journal_id`)');
        $this->add_index_if_missing('inventory_logs', 'idx_inventory_logs_journal', '(`journal_id`)');
    }

    public function check_tax_return_migrations(){
        if (!$this->conn) return;
        // Filed VAT returns: the figures as filed, kept even if the books are later corrected.
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `tax_returns` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `period_from` date NOT NULL,
          `period_to` date NOT NULL,
          `taxable_sales` decimal(18,2) NOT NULL DEFAULT 0.00,
          `taxable_purchases` decimal(18,2) NOT NULL DEFAULT 0.00,
          `output_tax` decimal(18,2) NOT NULL DEFAULT 0.00,
          `input_tax` decimal(18,2) NOT NULL DEFAULT 0.00,
          `net_tax` decimal(18,2) NOT NULL DEFAULT 0.00,
          `documents` int(11) NOT NULL DEFAULT 0,
          `reference` varchar(100) DEFAULT NULL,
          `note` varchar(500) DEFAULT NULL,
          `filed_date` date NOT NULL,
          `status` enum('filed','voided') NOT NULL DEFAULT 'filed',
          `filed_by` int(30) DEFAULT NULL,
          `filed_at` datetime NOT NULL DEFAULT current_timestamp(),
          `voided_by` int(30) DEFAULT NULL,
          `voided_at` datetime DEFAULT NULL,
          `void_reason` varchar(500) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `tenant_period` (`tenant_id`,`period_from`,`period_to`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->add_tenant_fk('tax_returns');
    }

    public function check_bill_payment_approval_migrations(){
        if (!$this->conn) return;

        $r = @$this->conn->query("SHOW COLUMNS FROM `bill_payments` LIKE 'status'");
        $col = $r ? $r->fetch_assoc() : null;
        if ($col && stripos((string)$col['Type'], 'pending_approval') === false) {
            @$this->conn->query("ALTER TABLE `bill_payments` MODIFY `status` enum('posted','void','pending_approval') NOT NULL DEFAULT 'posted'");
        }
        $cols = array(
            'approved_by'   => "ALTER TABLE `bill_payments` ADD COLUMN `approved_by` int(30) DEFAULT NULL",
            'approved_at'   => "ALTER TABLE `bill_payments` ADD COLUMN `approved_at` datetime DEFAULT NULL",
            'approval_note' => "ALTER TABLE `bill_payments` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL",
        );
        foreach ($cols as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `bill_payments` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_journal_trail_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `journal_revisions` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `journal_id` int(30) NOT NULL,
          `source_table` varchar(40) NOT NULL,
          `document_id` int(30) NOT NULL DEFAULT 0,
          `label` varchar(150) NOT NULL DEFAULT '',
          `changed_by` int(30) DEFAULT NULL,
          `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
          `before_json` mediumtext NOT NULL,
          `after_json` mediumtext NOT NULL,
          PRIMARY KEY (`id`),
          KEY `journal` (`tenant_id`, `journal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function check_fx_document_migrations(){
        if (!$this->conn) return;

        // A foreign-currency invoice keeps its own currency, rate and amounts; the usual columns stay in base currency.
        $cols = array(
            'invoice_list' => array(
                'currency' => "ALTER TABLE `invoice_list` ADD COLUMN `currency` varchar(10) DEFAULT NULL",
                'fx_rate'  => "ALTER TABLE `invoice_list` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL",
                'total_fc' => "ALTER TABLE `invoice_list` ADD COLUMN `total_fc` decimal(18,4) DEFAULT NULL",
                'paid_fc'  => "ALTER TABLE `invoice_list` ADD COLUMN `paid_fc` decimal(18,4) NOT NULL DEFAULT 0.0000",
                'credited_fc' => "ALTER TABLE `invoice_list` ADD COLUMN `credited_fc` decimal(18,4) NOT NULL DEFAULT 0.0000",
            ),
            'credit_notes' => array(
                'currency'  => "ALTER TABLE `credit_notes` ADD COLUMN `currency` varchar(10) DEFAULT NULL",
                'fx_rate'   => "ALTER TABLE `credit_notes` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL",
                'total_fc'  => "ALTER TABLE `credit_notes` ADD COLUMN `total_fc` decimal(18,4) DEFAULT NULL",
            ),
            'payment_list' => array(
                'fx_rate'   => "ALTER TABLE `payment_list` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL",
                'amount_fc' => "ALTER TABLE `payment_list` ADD COLUMN `amount_fc` decimal(18,4) DEFAULT NULL",
            ),
            'payment_allocations' => array(
                'amount_fc' => "ALTER TABLE `payment_allocations` ADD COLUMN `amount_fc` decimal(18,4) DEFAULT NULL",
            ),
        );
        foreach ($cols as $tbl => $defs) {
            foreach ($defs as $c => $sql) {
                $res = @$this->conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$c}'");
                if ($res && $res->num_rows == 0) {
                    @$this->conn->query($sql);
                }
            }
        }
    }

    public function check_bill_approval_migrations(){
        if (!$this->conn) return;

        $r = @$this->conn->query("SHOW COLUMNS FROM `vendor_bill_list` LIKE 'status'");
        $col = $r ? $r->fetch_assoc() : null;
        if ($col && stripos((string)$col['Type'], 'pending_approval') === false) {
            @$this->conn->query("ALTER TABLE `vendor_bill_list` MODIFY `status` enum('draft','pending_approval','unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid'");
        }
        $cols = array(
            'submitted_by'  => "ALTER TABLE `vendor_bill_list` ADD COLUMN `submitted_by` int(50) DEFAULT NULL",
            'approved_by'   => "ALTER TABLE `vendor_bill_list` ADD COLUMN `approved_by` int(50) DEFAULT NULL",
            'approved_at'   => "ALTER TABLE `vendor_bill_list` ADD COLUMN `approved_at` datetime DEFAULT NULL",
            'approval_note' => "ALTER TABLE `vendor_bill_list` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL",
        );
        foreach ($cols as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `vendor_bill_list` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_credit_note_migrations(){
        if (!$this->conn) return;

        @$this->conn->query("CREATE TABLE IF NOT EXISTS `credit_notes` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `note_type` enum('credit','debit') NOT NULL DEFAULT 'credit',
          `note_no` varchar(50) NOT NULL,
          `party_id` int(30) DEFAULT NULL,
          `document_id` int(30) NOT NULL,
          `note_date` date NOT NULL,
          `reason` varchar(500) NOT NULL DEFAULT '',
          `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
          `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
          `total` decimal(18,2) NOT NULL DEFAULT 0.00,
          `status` enum('issued','void') NOT NULL DEFAULT 'issued',
          `journal_id` int(30) DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `note_tenant_no` (`tenant_id`, `note_no`),
          KEY `document` (`note_type`, `document_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Credits raised against a document, kept apart from cash paid so payment reports stay true.
        foreach (array('invoice_list', 'vendor_bill_list') as $tbl) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'credited_amount'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query("ALTER TABLE `{$tbl}` ADD COLUMN `credited_amount` decimal(18,2) NOT NULL DEFAULT 0.00");
            }
        }

        // What a note is made of, line by line.
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `credit_note_lines` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `note_id` int(30) NOT NULL,
          `description` varchar(250) NOT NULL,
          `qty` decimal(12,4) NOT NULL DEFAULT 1.0000,
          `unit_price` decimal(18,4) NOT NULL DEFAULT 0.0000,
          `tax_rate` decimal(7,4) NOT NULL DEFAULT 0.0000,
          `net` decimal(18,2) NOT NULL DEFAULT 0.00,
          `tax` decimal(18,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`id`),
          KEY `note` (`note_id`),
          CONSTRAINT `cnl_note_fk` FOREIGN KEY (`note_id`) REFERENCES `credit_notes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Money handed back (customer) or received back (supplier) against the credit a note created.
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `credit_note_refunds` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `credit_note_id` int(30) NOT NULL,
          `refund_no` varchar(50) NOT NULL,
          `refund_date` date NOT NULL,
          `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
          `method` varchar(50) NOT NULL DEFAULT 'bank_transfer',
          `bank_account_id` int(30) DEFAULT NULL,
          `reference` varchar(100) DEFAULT NULL,
          `status` enum('issued','void') NOT NULL DEFAULT 'issued',
          `journal_id` int(30) DEFAULT NULL,
          `created_by` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `refund_tenant_no` (`tenant_id`, `refund_no`),
          KEY `note` (`credit_note_id`),
          CONSTRAINT `cnr_note_fk` FOREIGN KEY (`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

        // Email registration & verification columns in users table
        foreach ([
            'email'                => "ADD COLUMN `email` varchar(255) DEFAULT NULL AFTER `username`",
            'email_verified_at'    => "ADD COLUMN `email_verified_at` datetime DEFAULT NULL AFTER `email`",
            'email_verify_token'   => "ADD COLUMN `email_verify_token` varchar(64) DEFAULT NULL AFTER `email_verified_at`",
            'email_verify_expires' => "ADD COLUMN `email_verify_expires` datetime DEFAULT NULL AFTER `email_verify_token`",
        ] as $nv_col => $nv_ddl) {
            $nv_res = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE '{$nv_col}'");
            if ($nv_res && $nv_res->num_rows == 0) {
                @$this->conn->query("ALTER TABLE `users` {$nv_ddl}");
            }
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

        // Components assigned to employees, and the snapshot each payroll keeps of the lines it paid.
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `employee_salary_components` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `employee_id` int(30) NOT NULL,
          `component_id` int(30) NOT NULL,
          `amount` decimal(12,2) DEFAULT NULL,
          `start_date` date DEFAULT NULL,
          `end_date` date DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `employee_id` (`employee_id`),
          KEY `component_id` (`component_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $items = array(
            'code' => "ALTER TABLE `payroll_component_items` ADD COLUMN `code` varchar(50) NOT NULL DEFAULT ''",
            'name' => "ALTER TABLE `payroll_component_items` ADD COLUMN `name` varchar(150) NOT NULL DEFAULT ''",
            'type' => "ALTER TABLE `payroll_component_items` ADD COLUMN `type` enum('earning','deduction') NOT NULL DEFAULT 'earning'",
            'is_taxable' => "ALTER TABLE `payroll_component_items` ADD COLUMN `is_taxable` tinyint(1) NOT NULL DEFAULT 1",
            'is_fnpf_subject' => "ALTER TABLE `payroll_component_items` ADD COLUMN `is_fnpf_subject` tinyint(1) NOT NULL DEFAULT 1",
        );
        foreach ($items as $c => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_component_items` LIKE '{$c}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
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
}
