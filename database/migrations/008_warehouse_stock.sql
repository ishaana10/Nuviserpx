-- Migration 008: Per-warehouse stock levels.
-- Nuvis ERPX — Nuvis Technologies
--
-- Until now stock was a single global number per product (product_list.stock_quantity).
-- This adds a per-location breakdown so stock can be tracked and transferred
-- between warehouses, while product_list.stock_quantity remains the authoritative
-- TOTAL (kept = SUM of all locations) so every existing report and the valuation
-- stay correct and unchanged.
--
-- Warehouse id 0 is the implicit "Main / Unassigned" location — the same id the
-- existing transfer screen already uses for "Main Central Warehouse". Each
-- product's current on-hand is seeded into location 0 so totals reconcile from
-- day one. Tenant-scoped; idempotent.

CREATE TABLE IF NOT EXISTS `product_stock` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `product_id` int(30) NOT NULL,
  `warehouse_id` int(30) NOT NULL DEFAULT 0,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_wh` (`tenant_id`, `product_id`, `warehouse_id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_id` (`warehouse_id`),
  CONSTRAINT `pstock_product_fk` FOREIGN KEY (`product_id`) REFERENCES `product_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed each product's current total into the Main/Unassigned location (0),
-- but only for products that have no per-location rows yet (keeps this re-runnable).
INSERT INTO `product_stock` (`tenant_id`, `product_id`, `warehouse_id`, `quantity`)
SELECT p.tenant_id, p.id, 0, p.stock_quantity
FROM `product_list` p
WHERE p.delete_flag = 0
  AND NOT EXISTS (SELECT 1 FROM `product_stock` s WHERE s.product_id = p.id);

-- Record which warehouse a stock movement happened in (0 = Main/Unassigned),
-- so the movement ledger is location-aware going forward. Existing rows stay 0.
ALTER TABLE `inventory_logs` ADD COLUMN IF NOT EXISTS `warehouse_id` int(30) NOT NULL DEFAULT 0 AFTER `product_id`;
