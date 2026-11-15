-- When a signed delivery note was last emailed to the customer, and to which address.
ALTER TABLE `delivery_notes` ADD COLUMN IF NOT EXISTS `last_emailed_at` datetime DEFAULT NULL;
ALTER TABLE `delivery_notes` ADD COLUMN IF NOT EXISTS `last_emailed_to` varchar(190) DEFAULT NULL;
