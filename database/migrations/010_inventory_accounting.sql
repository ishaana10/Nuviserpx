-- Migration 010: Inventory accounting (cost of sales and inventory posting).
-- Nuvis ERPX — Nuvis Technologies
--
-- Adds the per-company switch and account choices, remembers which product each invoice line sold and at what
-- cost, and links stock movements to the journal that valued them. Nothing changes for a company until it
-- switches inventory accounting on under Stock Adjustments > Inventory accounting. Idempotent (also applied
-- automatically by DBConnection::check_inventory_accounting_migrations()).

CREATE TABLE IF NOT EXISTS `inventory_settings` (
  `tenant_id` int(30) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `inventory_account_id` int(30) DEFAULT NULL,
  `cogs_account_id` int(30) DEFAULT NULL,
  `adjustment_account_id` int(30) DEFAULT NULL,
  `offset_account_id` int(30) DEFAULT NULL,
  `enabled_on` date DEFAULT NULL,
  `opening_journal_id` int(30) DEFAULT NULL,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `invoice_items` ADD COLUMN IF NOT EXISTS `product_id` int(30) DEFAULT NULL AFTER `invoice_id`;
ALTER TABLE `invoice_items` ADD COLUMN IF NOT EXISTS `unit_cost` decimal(14,4) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN IF NOT EXISTS `cogs_journal_id` int(30) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN IF NOT EXISTS `stock_warehouse_id` int(30) NOT NULL DEFAULT 0;
ALTER TABLE `inventory_logs` ADD COLUMN IF NOT EXISTS `warehouse_id` int(30) NOT NULL DEFAULT 0 AFTER `product_id`;
ALTER TABLE `inventory_logs` ADD COLUMN IF NOT EXISTS `journal_id` int(30) DEFAULT NULL;
