ALTER TABLE `product_list` ADD COLUMN `track_batches` tinyint(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `product_batches` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `product_id` int(30) NOT NULL,
  `warehouse_id` int(30) NOT NULL DEFAULT 0,
  `batch_no` varchar(60) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `qty_remaining` decimal(14,4) NOT NULL DEFAULT 0,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0,
  `received_on` date DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_batch` (`tenant_id`,`product_id`,`warehouse_id`,`batch_no`),
  KEY `expiry` (`tenant_id`,`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
