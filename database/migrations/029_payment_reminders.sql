CREATE TABLE IF NOT EXISTS `reminder_settings` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `stages` varchar(60) NOT NULL DEFAULT '3,10,20',
  `fee_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `fee_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `fee_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fee_grace_days` int(11) NOT NULL DEFAULT 14,
  `fee_cap` decimal(12,2) NOT NULL DEFAULT 0.00,
  `note` varchar(500) DEFAULT NULL,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `invoice_reminders` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `invoice_id` int(30) NOT NULL,
  `stage_days` int(11) NOT NULL,
  `status` enum('sent','skipped') NOT NULL DEFAULT 'sent',
  `sent_to` varchar(190) DEFAULT NULL,
  `days_overdue` int(11) NOT NULL DEFAULT 0,
  `balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_stage` (`invoice_id`, `stage_days`),
  KEY `tenant_date` (`tenant_id`, `date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `invoice_list` ADD COLUMN `late_fee_invoice_id` int(30) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN `late_fee_for_id` int(30) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD UNIQUE KEY `uniq_late_fee_for` (`late_fee_for_id`);
