-- Each company carries its own logo for invoices, receipts and documents.
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `logo` varchar(255) DEFAULT NULL;
