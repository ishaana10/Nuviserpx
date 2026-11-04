-- Migration 015: quotation validity and versions.
-- Nuvis ERPX — Nuvis Technologies
--
-- valid_until: the last day a quote can be accepted (NULL = no expiry). version/parent_id/quote_root_id keep the
-- chain of revisions of one quote. Also applied automatically by DBConnection::check_quotation_migrations(), which
-- adds each column only if missing and widens the type/status lists only if they lack the new values.

ALTER TABLE `sales_order_list` ADD COLUMN `valid_until` date DEFAULT NULL;
ALTER TABLE `sales_order_list` ADD COLUMN `version` int(11) NOT NULL DEFAULT 1;
ALTER TABLE `sales_order_list` ADD COLUMN `parent_id` int(30) DEFAULT NULL;
ALTER TABLE `sales_order_list` ADD COLUMN `quote_root_id` int(30) DEFAULT NULL;
ALTER TABLE `sales_order_list` ADD COLUMN `decline_reason` varchar(250) DEFAULT NULL;
ALTER TABLE `sales_order_list` ADD COLUMN `declined_at` datetime DEFAULT NULL;
ALTER TABLE `sales_order_list` MODIFY `type` enum('proforma','quote','sales_order') NOT NULL DEFAULT 'proforma';
ALTER TABLE `sales_order_list` MODIFY `status` enum('draft','proforma','confirmed','converted_to_invoice','cancelled','superseded','declined') NOT NULL DEFAULT 'proforma';
ALTER TABLE `sales_order_list` ADD INDEX `idx_quote_root` (`quote_root_id`);
