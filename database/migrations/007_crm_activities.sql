-- Migration 007: CRM activities (follow-ups logged against opportunities).
-- Nuvis ERPX — Nuvis Technologies
--
-- Adds a follow-up / activity log to the sales pipeline: calls, meetings,
-- emails and tasks scheduled against an opportunity, each with a due date and a
-- done flag so overdue follow-ups can be surfaced on the board.
--
-- Also records when a won opportunity has been converted to an invoice, so the
-- conversion is not repeated. Tenant-scoped; idempotent.

CREATE TABLE IF NOT EXISTS `crm_activities` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `opportunity_id` int(30) NOT NULL,
  `type` enum('call','meeting','email','task','note') NOT NULL DEFAULT 'task',
  `subject` varchar(250) NOT NULL,
  `due_date` date DEFAULT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `done_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `opportunity_id` (`opportunity_id`),
  KEY `due_scan` (`tenant_id`, `is_done`, `due_date`),
  CONSTRAINT `activity_opp_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `crm_opportunities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link an opportunity to the invoice it was converted into (idempotency + traceability).
ALTER TABLE `crm_opportunities` ADD COLUMN IF NOT EXISTS `invoice_id` int(30) DEFAULT NULL AFTER `notes`;
