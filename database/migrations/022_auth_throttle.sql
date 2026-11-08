-- Sign-in and password-reset throttling, and hashed single-use reset codes with an attempt counter.
CREATE TABLE IF NOT EXISTS `auth_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) NOT NULL,
  `identifier` varchar(190) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `scope_identifier` (`scope`, `identifier`, `date_created`),
  KEY `scope_ip` (`scope`, `ip`, `date_created`),
  KEY `date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `otp_tokens` MODIFY `otp_code` varchar(64) NOT NULL;
ALTER TABLE `otp_tokens` ADD COLUMN `attempts` tinyint(3) NOT NULL DEFAULT 0;
