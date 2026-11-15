-- Proof of delivery: who received the goods (typed name), their drawn signature, and when/who captured it.
ALTER TABLE `delivery_notes` ADD COLUMN IF NOT EXISTS `received_by` varchar(150) DEFAULT NULL;
ALTER TABLE `delivery_notes` ADD COLUMN IF NOT EXISTS `receiver_signature` mediumtext DEFAULT NULL;
ALTER TABLE `delivery_notes` ADD COLUMN IF NOT EXISTS `received_at` datetime DEFAULT NULL;
ALTER TABLE `delivery_notes` ADD COLUMN IF NOT EXISTS `signed_by_user_id` int(30) DEFAULT NULL;
