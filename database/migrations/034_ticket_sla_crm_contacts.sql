ALTER TABLE `tickets` ADD COLUMN `first_response_at` datetime DEFAULT NULL;
ALTER TABLE `tickets` ADD COLUMN `resolved_at` datetime DEFAULT NULL;
ALTER TABLE `tickets` ADD COLUMN `paused_at` datetime DEFAULT NULL;
ALTER TABLE `tickets` ADD COLUMN `paused_seconds` int(11) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `ticket_sla_policies` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL,
  `response_hours` decimal(6,2) NOT NULL,
  `resolve_hours` decimal(7,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sla_priority` (`tenant_id`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `crm_opportunities` ADD COLUMN `lead_source` varchar(30) DEFAULT NULL;
ALTER TABLE `crm_opportunities` ADD COLUMN `lost_reason` varchar(30) DEFAULT NULL;
ALTER TABLE `crm_opportunities` ADD COLUMN `contact_id` int(30) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `crm_contacts` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `party_id` int(30) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(500) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_party` (`tenant_id`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
