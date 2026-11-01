-- Migration 005: Procurement module (Purchase Orders, Goods Receipts, Vendor Bills).
-- Nuvis ERPX — Nuvis Technologies
--
-- Adds a proper purchase-side flow (PO -> Goods Receipt -> Vendor Bill) with
-- correct Accounts Payable journal posting, replacing the previous approach of
-- reusing sales orders / invoices with a `type=po` query flag.
--
-- All tables are tenant-scoped (tenant_id) for the multi-tenant architecture.
-- CREATE TABLE IF NOT EXISTS and ADD COLUMN IF NOT EXISTS keep this idempotent.

-- ---------------------------------------------------------------------------
-- Purchase Orders
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `po_no` varchar(100) NOT NULL,
  `vendor_id` int(30) NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `warehouse_id` int(30) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','sent','confirmed','partially_received','received','billed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `po_status` (`tenant_id`, `status`),
  UNIQUE KEY `po_tenant_no` (`tenant_id`, `po_no`),
  CONSTRAINT `po_vendor_fk` FOREIGN KEY (`vendor_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(30) NOT NULL,
  `product_id` int(30) DEFAULT NULL,
  `item_name` varchar(250) NOT NULL,
  `description` text DEFAULT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `qty_received` decimal(10,2) NOT NULL DEFAULT 0.00,
  `qty_billed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `poi_order_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_order_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Goods Receipts (receiving stock against a PO)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `goods_receipt_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `grn_no` varchar(100) NOT NULL,
  `purchase_order_id` int(30) NOT NULL,
  `vendor_id` int(30) NOT NULL,
  `warehouse_id` int(30) DEFAULT NULL,
  `receipt_date` date NOT NULL,
  `status` enum('draft','received','cancelled') NOT NULL DEFAULT 'received',
  `stock_adjusted` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  UNIQUE KEY `grn_tenant_no` (`tenant_id`, `grn_no`),
  CONSTRAINT `grn_po_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_order_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `goods_receipt_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `goods_receipt_id` int(30) NOT NULL,
  `po_item_id` int(30) DEFAULT NULL,
  `product_id` int(30) DEFAULT NULL,
  `item_name` varchar(250) NOT NULL,
  `qty_received` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `goods_receipt_id` (`goods_receipt_id`),
  CONSTRAINT `gri_grn_fk` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipt_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Vendor Bills (Accounts Payable)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vendor_bill_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `bill_no` varchar(100) NOT NULL,
  `vendor_ref` varchar(100) DEFAULT NULL,
  `purchase_order_id` int(30) DEFAULT NULL,
  `vendor_id` int(30) NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `journal_id` int(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `bill_status` (`tenant_id`, `status`),
  KEY `purchase_order_id` (`purchase_order_id`),
  UNIQUE KEY `bill_tenant_no` (`tenant_id`, `bill_no`),
  CONSTRAINT `bill_vendor_fk` FOREIGN KEY (`vendor_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vendor_bill_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `vendor_bill_id` int(30) NOT NULL,
  `product_id` int(30) DEFAULT NULL,
  `item_name` varchar(250) NOT NULL,
  `description` text DEFAULT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `vendor_bill_id` (`vendor_bill_id`),
  CONSTRAINT `vbi_bill_fk` FOREIGN KEY (`vendor_bill_id`) REFERENCES `vendor_bill_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
