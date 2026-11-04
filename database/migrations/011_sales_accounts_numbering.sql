-- Migration 011: default sales accounts and unique document numbers.
-- Nuvis ERPX — Nuvis Technologies
--
-- account_defaults holds the accounts invoices, receipts and credit notes post to. The unique keys stop a
-- company reusing an invoice or receipt number; add them only if no duplicates exist (find them first with
--   SELECT tenant_id, invoice_no, COUNT(*) FROM invoice_list GROUP BY tenant_id, invoice_no HAVING COUNT(*) > 1;
-- ). Also applied automatically, with that duplicate check, by DBConnection::check_sales_account_migrations().

CREATE TABLE IF NOT EXISTS `account_defaults` (
  `tenant_id` int(30) NOT NULL,
  `role` varchar(30) NOT NULL,
  `account_id` int(30) NOT NULL,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `invoice_list` ADD UNIQUE KEY `uniq_invoice_no` (`tenant_id`, `invoice_no`);
ALTER TABLE `payment_list` ADD UNIQUE KEY `uniq_payment_no` (`tenant_id`, `payment_no`);
