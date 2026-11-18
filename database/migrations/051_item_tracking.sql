-- Services and other non-stock items. Existing items stay as they were (tracked, sold and purchased).
ALTER TABLE `product_list` ADD COLUMN IF NOT EXISTS `is_tracked` tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE `product_list` ADD COLUMN IF NOT EXISTS `is_sold` tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE `product_list` ADD COLUMN IF NOT EXISTS `is_purchased` tinyint(1) NOT NULL DEFAULT 1;
