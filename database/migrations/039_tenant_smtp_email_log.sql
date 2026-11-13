-- Per-tenant SMTP override (encrypted password) and a per-tenant email log.
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_enabled` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_host` varchar(255) DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_port` int DEFAULT 587;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_encryption` varchar(10) DEFAULT 'tls';
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_user` varchar(255) DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_pass` text DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_from_email` varchar(255) DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `smtp_from_name` varchar(255) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `email_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `transport` varchar(20) NOT NULL DEFAULT 'system-smtp',
  `status` varchar(10) NOT NULL DEFAULT 'sent',
  `error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
