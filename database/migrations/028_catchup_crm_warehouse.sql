-- Migration 028: catch-up for objects that migrations 007 and 008 never created.
-- The old runner split every file on semicolons, including ones inside comments, so the first statement of 007 and 008 was lost.
-- Everything here is safe to run on a database that already has the objects.

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
  KEY `due_scan` (`tenant_id`, `is_done`, `due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `crm_opportunities` ADD COLUMN `invoice_id` int(30) DEFAULT NULL AFTER `notes`;

CREATE TABLE IF NOT EXISTS `product_stock` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `product_id` int(30) NOT NULL,
  `warehouse_id` int(30) NOT NULL DEFAULT 0,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_wh` (`tenant_id`, `product_id`, `warehouse_id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_id` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `product_stock` (`tenant_id`, `product_id`, `warehouse_id`, `quantity`)
SELECT p.tenant_id, p.id, 0, p.stock_quantity
FROM `product_list` p
WHERE p.delete_flag = 0
  AND NOT EXISTS (SELECT 1 FROM `product_stock` s WHERE s.product_id = p.id);

ALTER TABLE `inventory_logs` ADD COLUMN `warehouse_id` int(30) NOT NULL DEFAULT 0 AFTER `product_id`;
