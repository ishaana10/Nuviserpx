-- Employee self-service: staff sign in to read their own payslips.
-- The invitation token is stored hashed, so a copy of the database cannot be used to activate an account.
ALTER TABLE `employee_list` ADD COLUMN `email` varchar(150) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `employee_portal_users` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `invite_token_hash` char(64) DEFAULT NULL,
  `invite_expires_at` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `portal_employee` (`tenant_id`, `employee_id`),
  KEY `email` (`email`),
  KEY `invite_token_hash` (`invite_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employee_portal_logs` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_employee` (`tenant_id`, `employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
