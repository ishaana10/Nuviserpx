-- External systems (e.g. All Pest) tag the invoices they raise so a retry never bills twice.
ALTER TABLE `invoice_list` ADD COLUMN IF NOT EXISTS `external_ref` varchar(100) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_invoice_external_ref` ON `invoice_list` (`tenant_id`, `external_ref`);
