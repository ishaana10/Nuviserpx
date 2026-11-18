-- Company profile: tax and registration numbers, website and the bank details printed on invoices.
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `tin` varchar(50) DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `vat_no` varchar(50) DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `website` varchar(190) DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `bank_details` text DEFAULT NULL;
