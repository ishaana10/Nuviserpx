CREATE TABLE IF NOT EXISTS `stock_counts` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `count_no` varchar(30) NOT NULL,
  `warehouse_id` int(30) NOT NULL DEFAULT 0,
  `count_date` date NOT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `posted_by` int(30) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_count_no` (`tenant_id`,`count_no`),
  KEY `tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stock_count_lines` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `count_id` int(30) NOT NULL,
  `tenant_id` int(30) NOT NULL,
  `product_id` int(30) NOT NULL,
  `system_qty` decimal(14,4) NOT NULL DEFAULT 0,
  `counted_qty` decimal(14,4) DEFAULT NULL,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0,
  `line_status` enum('pending','posted','failed') NOT NULL DEFAULT 'pending',
  `result_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_count_product` (`count_id`,`product_id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
