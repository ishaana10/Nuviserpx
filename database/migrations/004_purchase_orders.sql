-- Purchase Orders & Goods Received Notes (GRN) Migration
CREATE TABLE IF NOT EXISTS `purchase_order_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `po_no` varchar(100) NOT NULL,
  `vendor_id` int(30) NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','ordered','received','cancelled') NOT NULL DEFAULT 'ordered',
  `journal_id` int(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_updated` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_po` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `po_id` int(30) NOT NULL,
  `product_id` int(30) DEFAULT NULL,
  `item_name` varchar(250) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `po_id` (`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
