-- Public holidays, half-day leave and individual leave entitlements.
-- A day fraction of 1 is a full day and 0.5 a half day, and a half day is only for a single date.
CREATE TABLE IF NOT EXISTS `public_holidays` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `holiday_date` date NOT NULL,
  `name` varchar(120) NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_date` (`tenant_id`, `holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `leave_list` ADD COLUMN `day_fraction` decimal(3,2) NOT NULL DEFAULT 1.00;

CREATE TABLE IF NOT EXISTS `employee_leave_entitlements` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) NOT NULL,
  `leave_type` enum('casual','sick','paid') NOT NULL,
  `annual_days` decimal(6,2) NOT NULL,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_employee_type` (`tenant_id`, `employee_id`, `leave_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
