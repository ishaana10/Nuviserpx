CREATE TABLE IF NOT EXISTS `landed_costs` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `landed_no` varchar(30) NOT NULL,
  `grn_id` int(30) NOT NULL,
  `cost_date` date NOT NULL,
  `basis` enum('value','qty') NOT NULL DEFAULT 'value',
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `capitalised` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_account_id` int(30) NOT NULL DEFAULT 0,
  `journal_id` int(30) NOT NULL DEFAULT 0,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_landed_no` (`tenant_id`,`landed_no`),
  KEY `grn_id` (`grn_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `landed_cost_charges` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `landed_id` int(30) NOT NULL,
  `description` varchar(150) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `landed_id` (`landed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `landed_cost_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `landed_id` int(30) NOT NULL,
  `product_id` int(30) NOT NULL,
  `qty_received` decimal(14,4) NOT NULL DEFAULT 0,
  `allocated` decimal(14,2) NOT NULL DEFAULT 0.00,
  `capitalised` decimal(14,2) NOT NULL DEFAULT 0.00,
  `old_cost` decimal(14,4) NOT NULL DEFAULT 0,
  `new_cost` decimal(14,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_landed_product` (`landed_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
