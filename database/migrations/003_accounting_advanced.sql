-- Advanced Accounting Schema Migration
CREATE TABLE IF NOT EXISTS `accounting_periods` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `period_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('open','closed','locked') NOT NULL DEFAULT 'open',
  `closed_by` int(30) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_period` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `recurring_templates` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `type` enum('journal','invoice') NOT NULL DEFAULT 'journal',
  `frequency` enum('weekly','monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
  `next_run_date` date NOT NULL,
  `template_data` longtext NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_type` (`tenant_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `currency_code` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL DEFAULT 1.000000,
  `effective_date` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_currency` (`tenant_id`, `currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `account_id` int(30) NOT NULL,
  `statement_date` date NOT NULL,
  `statement_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gl_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `difference` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','reconciled') NOT NULL DEFAULT 'draft',
  `reconciled_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_account` (`tenant_id`, `account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
