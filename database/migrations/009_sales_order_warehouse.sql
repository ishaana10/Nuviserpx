-- Migration 009: Outbound stock location on sales orders.
-- Nuvis ERPX — Nuvis Technologies
--
-- Follow-up to 008 (per-warehouse stock). Lets a sales order declare which
-- warehouse its stock is fulfilled from, so confirming the sale draws down that
-- location (not just the global total). 0 = Main / Unassigned, matching 008.
-- Idempotent.

ALTER TABLE `sales_order_list` ADD COLUMN IF NOT EXISTS `warehouse_id` int(30) NOT NULL DEFAULT 0 AFTER `type`;
