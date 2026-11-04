-- Migration 013: customer price lists and the line discount kept on invoice items.
-- Nuvis ERPX — Nuvis Technologies
--
-- Also applied automatically by DBConnection::check_pricing_migrations() (tables if missing, columns only if missing).

CREATE TABLE IF NOT EXISTS `price_lists` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_list_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `price_list_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `price_list_id` int(30) NOT NULL,
  `product_id` int(30) NOT NULL,
  `min_qty` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `price` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `list_product_qty` (`price_list_id`, `product_id`, `min_qty`),
  KEY `product` (`product_id`),
  CONSTRAINT `pli_list_fk` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `party_list` ADD COLUMN `price_list_id` int(30) DEFAULT NULL;
ALTER TABLE `invoice_items` ADD COLUMN `discount_pct` decimal(7,4) NOT NULL DEFAULT 0.0000;
