-- User accounts get a registered email address with verification.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` varchar(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verified_at` datetime DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verify_token` varchar(64) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verify_expires` datetime DEFAULT NULL;
