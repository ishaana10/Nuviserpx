-- Migration 014: customer credit limit and default payment terms.
-- Nuvis ERPX — Nuvis Technologies
--
-- credit_limit NULL means no limit. Also applied automatically by DBConnection::check_credit_control_migrations().

ALTER TABLE `party_list` ADD COLUMN `credit_limit` decimal(18,2) DEFAULT NULL;
ALTER TABLE `party_list` ADD COLUMN `payment_terms_days` int(11) DEFAULT NULL;
