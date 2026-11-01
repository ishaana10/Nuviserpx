-- Migration 004: Invoice integrations (webhooks + API), workflow automation, and SaaS tenant plans.
-- Nuvis ERPX — Nuvis Technologies
--
-- NOTE: `ADD COLUMN IF NOT EXISTS` requires MariaDB 10.3+ or MySQL 8.0.1+.
-- On older MySQL (5.7) run the app once instead — DBConnection::check_api_migrations()
-- and check_automation_migrations() apply the same columns idempotently via SHOW COLUMNS
-- guards, which work on every supported version.

-- Enrich the existing webhooks registry.
ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `name` varchar(150) DEFAULT NULL AFTER `tenant_id`;
ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `max_retries` tinyint(2) NOT NULL DEFAULT 5 AFTER `status`;
ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `last_triggered_at` datetime DEFAULT NULL AFTER `max_retries`;
ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `last_status` varchar(20) DEFAULT NULL AFTER `last_triggered_at`;
ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `failure_count` int(11) NOT NULL DEFAULT 0 AFTER `last_status`;
ALTER TABLE `webhooks` ADD COLUMN IF NOT EXISTS `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp() AFTER `date_created`;

-- Outgoing webhook delivery queue + audit log.
CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- API usage metrics for the developer application-performance dashboard.
CREATE TABLE IF NOT EXISTS `api_request_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customisable automation rules (trigger + JSON conditions + JSON actions).
CREATE TABLE IF NOT EXISTS `workflows` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `workflow_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SaaS subscription tiers.
CREATE TABLE IF NOT EXISTS `tenant_plans` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Plan assignment on tenants.
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `plan_id` int(30) DEFAULT NULL AFTER `status`;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `plan_started_at` date DEFAULT NULL AFTER `plan_id`;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `plan_expires_at` date DEFAULT NULL AFTER `plan_started_at`;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `plan_status` enum('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'active' AFTER `plan_expires_at`;

-- Seed default plans.
INSERT INTO `tenant_plans` (`code`,`name`,`description`,`price`,`billing_cycle`,`max_users`,`max_invoices`,`max_api_calls`,`features`,`sort_order`)
SELECT * FROM (SELECT
  'starter','Starter','For small businesses getting started',0.00,'monthly',3,50,1000,'["Invoicing","Accounting","1 Tenant"]',1) AS t
WHERE NOT EXISTS (SELECT 1 FROM `tenant_plans` WHERE `code` = 'starter');

INSERT INTO `tenant_plans` (`code`,`name`,`description`,`price`,`billing_cycle`,`max_users`,`max_invoices`,`max_api_calls`,`features`,`sort_order`)
SELECT * FROM (SELECT
  'professional','Professional','Growing teams with automation needs',99.00,'monthly',15,1000,50000,'["Everything in Starter","Webhooks","Workflow Automation","API Access"]',2) AS t
WHERE NOT EXISTS (SELECT 1 FROM `tenant_plans` WHERE `code` = 'professional');

INSERT INTO `tenant_plans` (`code`,`name`,`description`,`price`,`billing_cycle`,`max_users`,`max_invoices`,`max_api_calls`,`features`,`sort_order`)
SELECT * FROM (SELECT
  'enterprise','Enterprise','Full platform with unlimited scale',299.00,'monthly',0,0,0,'["Everything in Professional","Unlimited Users","Unlimited Invoices","Priority Support"]',3) AS t
WHERE NOT EXISTS (SELECT 1 FROM `tenant_plans` WHERE `code` = 'enterprise');
