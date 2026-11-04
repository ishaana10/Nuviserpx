-- Migration 012: sales returns that restock.
-- Nuvis ERPX — Nuvis Technologies
--
-- A credit-note line can name a product and a quantity coming back. With inventory accounting on, the stock goes
-- back on the shelf and the cost returns to Inventory (Dr Inventory, Cr Cost of Sales). Also applied automatically
-- by DBConnection::check_sales_return_migrations() (each column only if missing).

ALTER TABLE `credit_note_lines` ADD COLUMN `product_id` int(30) DEFAULT NULL;
ALTER TABLE `credit_note_lines` ADD COLUMN `return_qty` decimal(12,4) DEFAULT NULL;
ALTER TABLE `credit_note_lines` ADD COLUMN `unit_cost` decimal(14,4) DEFAULT NULL;
ALTER TABLE `credit_notes` ADD COLUMN `stock_journal_id` int(30) DEFAULT NULL;
ALTER TABLE `credit_notes` ADD COLUMN `stock_warehouse_id` int(30) DEFAULT NULL;
