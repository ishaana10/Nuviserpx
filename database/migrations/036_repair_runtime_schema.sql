-- Repair pass: some installations recorded 024_runtime_schema_catchup as
-- executed although its statements never ran (missing audit_logs and
-- document_sequences on live systems). Every statement here is the same as
-- in 024 and safe to re-run: CREATE TABLE IF NOT EXISTS, and ADD COLUMNs
-- that error harmlessly where the column already exists.

-- Catch-up for schema that older releases created only when the app started (classes/DBConnection.php).
-- Everything here is guarded, so installs that already have it are unchanged.
-- Triggers, seed rows and data backfills stay in DBConnection because they cannot run from this file.

CREATE TABLE IF NOT EXISTS `tax_brackets` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_portal_users` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_portal_logs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `custom_extrafields` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `custom_extrafield_values` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `element_type` varchar(100) NOT NULL,
          `element_id` int(30) NOT NULL,
          `field_code` varchar(100) NOT NULL,
          `field_value` text DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `elem_idx` (`element_type`, `element_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tickets` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_messages` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `ticket_id` int(30) NOT NULL,
          `user_id` int(30) DEFAULT NULL,
          `message` text NOT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `contracts` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `warehouses` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `name` varchar(150) NOT NULL,
          `location` text DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT 1,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `warehouse_transfers` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `expense_reports` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `period_locks` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `lock_date` date DEFAULT NULL,
          `note` varchar(255) DEFAULT NULL,
          `locked_by` int(50) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `tenant_latest` (`tenant_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `budget_lines` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tax_codes` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `exchange_rates` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL DEFAULT 1,
          `currency_code` varchar(10) NOT NULL,
          `rate_date` date NOT NULL,
          `rate` decimal(18,8) NOT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `rate_day` (`tenant_id`, `currency_code`, `rate_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `document_sequences` (
          `tenant_id` int(30) NOT NULL,
          `seq_key` varchar(60) NOT NULL,
          `last_value` bigint(20) NOT NULL DEFAULT 0,
          PRIMARY KEY (`tenant_id`, `seq_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `approval_policies` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(30) NOT NULL,
          `doc_type` varchar(30) NOT NULL DEFAULT 'journal',
          `enabled` tinyint(1) NOT NULL DEFAULT 0,
          `threshold` decimal(18,2) NOT NULL DEFAULT 0.00,
          `allow_self` tinyint(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `policy_doc` (`tenant_id`, `doc_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `asset_depreciation` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bill_payments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bill_payment_allocations` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `payment_id` int(30) NOT NULL,
          `bill_id` int(30) NOT NULL,
          `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`id`),
          KEY `payment` (`payment_id`),
          KEY `bill` (`bill_id`),
          CONSTRAINT `bpa_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `bill_payments` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tax_returns` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `journal_revisions` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `credit_notes` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `credit_note_lines` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `credit_note_refunds` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `invoice_templates` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shift_types` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shift_assignments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tenant_vsdc_certificates` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `frcs_audit_vault` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tenant_vms_config` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vms_logs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vms_queues` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `currencies` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `account_list` ADD COLUMN `code` varchar(50) DEFAULT NULL;
ALTER TABLE `account_list` ADD COLUMN `type` varchar(50) DEFAULT 'Expense';
ALTER TABLE `account_list` ADD COLUMN `tax_rate` varchar(100) DEFAULT 'Tax Exempt (0%)';
ALTER TABLE `account_list` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `account_list` ADD COLUMN `bank_name` varchar(150) DEFAULT NULL;
ALTER TABLE `account_list` ADD COLUMN `account_number` varchar(100) DEFAULT NULL;
ALTER TABLE `account_list` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT '';
ALTER TABLE `journal_entries` ADD COLUMN `auto_reversing_date` date DEFAULT NULL;
ALTER TABLE `journal_entries` ADD COLUMN `default_narration` tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE `journal_entries` ADD COLUMN `show_cash_basis` tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE `journal_entries` ADD COLUMN `tax_mode` varchar(50) NOT NULL DEFAULT 'No Tax';
ALTER TABLE `journal_entries` ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'posted';
ALTER TABLE `journal_items` ADD COLUMN `description` text DEFAULT NULL;
ALTER TABLE `journal_items` ADD COLUMN `tax_rate` varchar(100) DEFAULT 'No Tax (0%)';
ALTER TABLE `journal_entries` ADD COLUMN `reverses_id` int(30) DEFAULT NULL;
ALTER TABLE `journal_items` ADD COLUMN `currency` varchar(10) DEFAULT NULL;
ALTER TABLE `journal_items` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL;
ALTER TABLE `journal_items` ADD COLUMN `amount_fc` decimal(18,4) DEFAULT NULL;
ALTER TABLE `journal_entries` ADD COLUMN `approved_by` int(50) DEFAULT NULL;
ALTER TABLE `journal_entries` ADD COLUMN `approved_at` datetime DEFAULT NULL;
ALTER TABLE `journal_entries` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL;
ALTER TABLE `fixed_assets` ADD COLUMN `disposal_date` date DEFAULT NULL;
ALTER TABLE `fixed_assets` ADD COLUMN `disposal_proceeds` decimal(18,4) DEFAULT NULL;
ALTER TABLE `fixed_assets` ADD COLUMN `disposal_journal_id` int(30) DEFAULT NULL;
ALTER TABLE `bill_payments` ADD COLUMN `approved_by` int(30) DEFAULT NULL;
ALTER TABLE `bill_payments` ADD COLUMN `approved_at` datetime DEFAULT NULL;
ALTER TABLE `bill_payments` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN `currency` varchar(10) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN `total_fc` decimal(18,4) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN `paid_fc` decimal(18,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE `invoice_list` ADD COLUMN `credited_fc` decimal(18,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE `credit_notes` ADD COLUMN `currency` varchar(10) DEFAULT NULL;
ALTER TABLE `credit_notes` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL;
ALTER TABLE `credit_notes` ADD COLUMN `total_fc` decimal(18,4) DEFAULT NULL;
ALTER TABLE `payment_list` ADD COLUMN `fx_rate` decimal(18,8) DEFAULT NULL;
ALTER TABLE `payment_list` ADD COLUMN `amount_fc` decimal(18,4) DEFAULT NULL;
ALTER TABLE `payment_allocations` ADD COLUMN `amount_fc` decimal(18,4) DEFAULT NULL;
ALTER TABLE `vendor_bill_list` ADD COLUMN `submitted_by` int(50) DEFAULT NULL;
ALTER TABLE `vendor_bill_list` ADD COLUMN `approved_by` int(50) DEFAULT NULL;
ALTER TABLE `vendor_bill_list` ADD COLUMN `approved_at` datetime DEFAULT NULL;
ALTER TABLE `vendor_bill_list` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `employee_id` int(30) DEFAULT NULL ;
ALTER TABLE `tenants` ADD COLUMN `modules` text DEFAULT NULL;

ALTER TABLE `journal_items` MODIFY `amount` decimal(18,4) NOT NULL DEFAULT 0;
ALTER TABLE `journal_entries` ADD INDEX `reverses_id` (`reverses_id`);
