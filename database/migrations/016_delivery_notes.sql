-- Migration 016: delivery notes and partial fulfilment of sales orders.
-- Nuvis ERPX — Nuvis Technologies
--
-- A sales order confirmed "for delivery" (delivery_mode = 1) is fulfilled by delivery notes, each issuing stock for
-- the quantities sent, and invoiced for what has been delivered. Also applied automatically by
-- DBConnection::check_delivery_migrations() (tables if missing, columns only if missing).

CREATE TABLE IF NOT EXISTS `delivery_notes` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `dn_no` varchar(50) NOT NULL,
  `sales_order_id` int(30) NOT NULL,
  `party_id` int(30) NOT NULL,
  `delivery_date` date NOT NULL,
  `warehouse_id` int(30) NOT NULL DEFAULT 0,
  `status` enum('delivered','cancelled') NOT NULL DEFAULT 'delivered',
  `stock_journal_id` int(30) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dn_tenant_no` (`tenant_id`, `dn_no`),
  KEY `sales_order_id` (`sales_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `delivery_note_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `delivery_note_id` int(30) NOT NULL,
  `order_item_id` int(30) NOT NULL,
  `product_id` int(30) DEFAULT NULL,
  `item_name` varchar(250) NOT NULL,
  `qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(14,4) DEFAULT NULL,
  `invoice_id` int(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_note_id` (`delivery_note_id`),
  KEY `order_item_id` (`order_item_id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `dni_note_fk` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `sales_order_list` ADD COLUMN `delivery_mode` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `sales_order_items` ADD COLUMN `delivered_qty` decimal(12,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE `sales_order_items` ADD COLUMN `invoiced_qty` decimal(12,4) NOT NULL DEFAULT 0.0000;
