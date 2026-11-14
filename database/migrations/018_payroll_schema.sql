-- Payroll schema, complete. Everything the payroll module needs, in one versioned migration.
-- Until now most of it was only created by on-load ALTERs in classes/DBConnection.php, so an install built from
-- database/nuviserpx.sql plus migrations was missing columns. Each statement is safe to run on a database that
-- already has the change: the runner skips a statement that fails because the column or table is already there.
-- tests/test_payroll_schema.php fails if the runtime code adds a payroll column this file does not.

-- Tables
CREATE TABLE IF NOT EXISTS `salary_components` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_component_items` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `payroll_id` int(30) NOT NULL,
          `component_id` int(30) NOT NULL,
          `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`id`),
          KEY `payroll_id` (`payroll_id`),
          KEY `component_id` (`component_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employee_salary_components` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_run_lines` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_rule_sets` (
          `tenant_id` int(30) NOT NULL,
          `setting` mediumtext NOT NULL,
          `updated_by` int(30) DEFAULT NULL,
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_payments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_remittances` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employee_loans` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `loan_repayments` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `loan_id` int(30) NOT NULL,
          `payroll_id` int(30) NOT NULL,
          `amount` decimal(18,4) NOT NULL DEFAULT 0,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `loan_payroll` (`loan_id`, `payroll_id`),
          KEY `payroll_id` (`payroll_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_list columns
ALTER TABLE `employee_list` ADD COLUMN `birth_count_tracker` int(3) NOT NULL DEFAULT 0;
ALTER TABLE `employee_list` ADD COLUMN `ytd_opening_gross` decimal(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `employee_list` ADD COLUMN `ytd_opening_paye` decimal(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `employee_list` ADD COLUMN `fnpf_employer_voluntary` decimal(5,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `employee_list` ADD COLUMN `annual_salary` decimal(14,2) DEFAULT NULL;
ALTER TABLE `employee_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL;
ALTER TABLE `employee_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL;
ALTER TABLE `employee_list` ADD COLUMN `tax_code` varchar(10) NOT NULL DEFAULT 'P';
ALTER TABLE `employee_list` ADD COLUMN `is_resident` tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE `employee_list` ADD COLUMN `bank_code` varchar(20) DEFAULT 'bsp';
ALTER TABLE `employee_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL;
ALTER TABLE `employee_list` ADD COLUMN `approver_id` int(11) DEFAULT NULL;
ALTER TABLE `employee_list` ADD COLUMN `termination_date` date DEFAULT NULL;

-- payroll_list columns (money columns are created at the ledger's precision)
ALTER TABLE `payroll_list` ADD COLUMN `payroll_run_ref` varchar(100) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `pay_frequency` varchar(20) NOT NULL DEFAULT 'Monthly';
ALTER TABLE `payroll_list` ADD COLUMN `pay_date` date DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `period_start` date DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `period_end` date DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `overtime` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `other_earnings` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `gross_salary` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `fnpf_base` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `employee_fnpf` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `employer_fnpf` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `taxable_income` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `paye_tax` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `srt_tax` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `workcare_levy` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `training_levy` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `employer_cost` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `tax_code` varchar(10) DEFAULT 'P';
ALTER TABLE `payroll_list` ADD COLUMN `is_resident` tinyint(1) DEFAULT 1;
ALTER TABLE `payroll_list` ADD COLUMN `bank_code` varchar(20) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `retro_pay` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `created_by` int(30) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `approved_by` int(30) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `approved_at` datetime DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL;
ALTER TABLE `payroll_list` ADD COLUMN `paye_lump` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `unpaid_days` decimal(6,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `unpaid_deduction` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `component_earnings` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `component_deductions` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `leave_payout` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `redundancy_pay` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `loan_recovery` decimal(18,4) NOT NULL DEFAULT 0.00;
ALTER TABLE `payroll_list` ADD COLUMN `payment_status` varchar(10) NOT NULL DEFAULT 'paid';
ALTER TABLE `payroll_list` ADD COLUMN `payment_id` int(30) DEFAULT NULL;

-- payroll_component_items, payroll_remittances columns
ALTER TABLE `payroll_component_items` ADD COLUMN `loan_id` int(30) DEFAULT NULL;
ALTER TABLE `payroll_remittances` ADD COLUMN `approved_by` int(30) DEFAULT NULL;
ALTER TABLE `payroll_remittances` ADD COLUMN `approved_at` datetime DEFAULT NULL;
ALTER TABLE `payroll_remittances` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL;
ALTER TABLE `payroll_component_items` ADD COLUMN `code` varchar(50) NOT NULL DEFAULT '';
ALTER TABLE `payroll_component_items` ADD COLUMN `name` varchar(150) NOT NULL DEFAULT '';
ALTER TABLE `payroll_component_items` ADD COLUMN `type` enum('earning','deduction') NOT NULL DEFAULT 'earning';
ALTER TABLE `payroll_component_items` ADD COLUMN `is_taxable` tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE `payroll_component_items` ADD COLUMN `is_fnpf_subject` tinyint(1) NOT NULL DEFAULT 1;

-- payroll_list.status was a tinyint in the base schema: make it the status enum (old numeric values count as posted)
ALTER TABLE `payroll_list` MODIFY `status` varchar(20) NOT NULL DEFAULT 'posted';
UPDATE `payroll_list` SET `status` = 'posted' WHERE `status` NOT IN ('posted','pending_approval','rejected','voided');
ALTER TABLE `payroll_list` MODIFY `status` enum('posted','pending_approval','rejected','voided') NOT NULL DEFAULT 'posted';
ALTER TABLE `payroll_remittances` MODIFY `status` enum('posted','reversed','pending_approval','rejected') NOT NULL DEFAULT 'posted';

-- Money columns that came from the base schema at 2 decimals, widened to the ledger's DECIMAL(18,4)

ALTER TABLE `payroll_list` MODIFY `basic_salary` decimal(18,4) NOT NULL DEFAULT 0;
ALTER TABLE `payroll_list` MODIFY `allowances` decimal(18,4) NOT NULL DEFAULT 0;

-- Reference sequence for payroll runs: one counter per company per month
CREATE TABLE IF NOT EXISTS `payroll_run_counters` (
  `tenant_id` int(30) NOT NULL,
  `ym` char(6) NOT NULL,
  `last_no` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tenant_id`, `ym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
