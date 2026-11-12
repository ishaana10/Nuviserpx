ALTER TABLE `party_list` ADD COLUMN `wht_rate` decimal(5,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `bill_payments` ADD COLUMN `wht_amount` decimal(14,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `bill_payments` ADD COLUMN `wht_account_id` int(30) NOT NULL DEFAULT 0;
