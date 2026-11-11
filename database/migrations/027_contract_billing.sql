ALTER TABLE `contracts` ADD COLUMN `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `contracts` ADD COLUMN `tax_type` varchar(10) NOT NULL DEFAULT 'exclusive';
ALTER TABLE `invoice_list` ADD COLUMN `contract_id` int(30) DEFAULT NULL;
ALTER TABLE `invoice_list` ADD COLUMN `billing_period_start` date DEFAULT NULL;
ALTER TABLE `invoice_list` ADD UNIQUE KEY `uniq_contract_period` (`contract_id`, `billing_period_start`);
