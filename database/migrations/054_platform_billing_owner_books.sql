-- Platform billing moves into the owner company's books. A tenant bill is now an ordinary sales invoice there and
-- platform_invoices only links a customer tenant to it. The first version of 053 kept its own invoice list; those
-- invoices never reached any ledger, so that list is replaced.
DROP TABLE IF EXISTS `platform_invoice_items`;
DROP TABLE IF EXISTS `platform_payments`;
DROP TABLE IF EXISTS `platform_invoices`;
CREATE TABLE `platform_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `owner_invoice_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pinv_owner_invoice` (`owner_invoice_id`),
  KEY `idx_pinv_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which company is the platform owner (exactly one), a customer tenant's record in the owner's books, and the
-- Nuvis product an invoice belongs to (erpx, medcarex, flipex, other) for revenue-by-product reporting.
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `is_platform_owner` tinyint(1) NOT NULL DEFAULT 0;
UPDATE `tenants` SET `is_platform_owner` = 1 WHERE `id` = 1 AND (SELECT `c` FROM (SELECT COUNT(*) AS `c` FROM `tenants` WHERE `is_platform_owner` = 1) `x`) = 0;
ALTER TABLE `party_list` ADD COLUMN IF NOT EXISTS `linked_tenant_id` int(11) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN IF NOT EXISTS `product_code` varchar(30) DEFAULT NULL;
