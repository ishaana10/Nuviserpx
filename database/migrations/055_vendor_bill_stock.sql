-- Stock received by a vendor bill. With inventory accounting on, a bill that buys stock products and is not tied to a
-- purchase order books the goods into Inventory in the ledger; this records the quantity and cost it put on the shelf so
-- the stock valuation follows the ledger and an edit, cancellation or approval moves exactly that much.
CREATE TABLE IF NOT EXISTS `vendor_bill_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bill_product` (`tenant_id`, `bill_id`, `product_id`),
  KEY `bill` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
