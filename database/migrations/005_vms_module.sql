-- VMS 3.0 Module Schema Migration for Nuvis ERPX
-- Multi-Tenant, Async Queue, Audit/Fail/Retry/Queue Logs, and Fiscal Receipts

CREATE TABLE IF NOT EXISTS `vms_configs` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `sdc_url` varchar(255) NOT NULL DEFAULT 'https://vsdc.vms.frcs.org.fj/api/v3/sdc',
  `pac_code` varchar(100) DEFAULT NULL,
  `cashier_tin` varchar(100) DEFAULT NULL,
  `pos_number` varchar(50) DEFAULT 'POS-01',
  `cert_path` varchar(255) DEFAULT NULL,
  `cert_pass` varchar(255) DEFAULT NULL,
  `is_sandbox` tinyint(1) NOT NULL DEFAULT 1,
  `auto_fiscalize` tinyint(1) NOT NULL DEFAULT 1,
  `max_retries` int(11) NOT NULL DEFAULT 5,
  `retry_interval_sec` int(11) NOT NULL DEFAULT 300,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_updated` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_vms_config_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vms_queues` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `invoice_id` int(30) NOT NULL,
  `status` enum('pending','processing','completed','failed','retrying') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 5,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `response` longtext DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_updated` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vms_queue_tenant_status` (`tenant_id`, `status`),
  KEY `idx_vms_queue_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vms_logs` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `queue_id` int(30) DEFAULT NULL,
  `invoice_id` int(30) DEFAULT NULL,
  `log_type` enum('audit','fail','retry','queue') NOT NULL DEFAULT 'audit',
  `action` varchar(100) NOT NULL,
  `status_code` int(11) NOT NULL DEFAULT 0,
  `request_data` longtext DEFAULT NULL,
  `response_data` longtext DEFAULT NULL,
  `execution_time_ms` int(11) NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vms_logs_tenant_type` (`tenant_id`, `log_type`),
  KEY `idx_vms_logs_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vms_fiscal_receipts` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `invoice_id` int(30) NOT NULL,
  `sdc_invoice_no` varchar(100) NOT NULL,
  `sdc_date_time` varchar(100) NOT NULL,
  `verification_url` varchar(500) NOT NULL,
  `verification_qr` text DEFAULT NULL,
  `mrc_code` varchar(100) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `raw_response` longtext DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_vms_receipt_invoice` (`invoice_id`),
  KEY `idx_vms_receipt_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
