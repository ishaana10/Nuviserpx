-- Leave balances: per-type entitlement policy and manual balance adjustments.
-- Leave requests can be cancelled by the employee, so the status list must accept it.
ALTER TABLE `leave_list` MODIFY `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS `leave_policies` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `leave_type` enum('casual','sick','paid') NOT NULL,
  `tracked` tinyint(1) NOT NULL DEFAULT 1,
  `annual_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `carry_over_max` decimal(6,2) DEFAULT 0.00,
  `max_balance` decimal(6,2) DEFAULT NULL,
  `allow_negative` tinyint(1) NOT NULL DEFAULT 0,
  `payable_on_exit` tinyint(1) NOT NULL DEFAULT 0,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_type` (`tenant_id`, `leave_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `leave_adjustments` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) NOT NULL,
  `leave_type` enum('casual','sick','paid') NOT NULL,
  `days` decimal(6,2) NOT NULL,
  `effective_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_employee` (`tenant_id`, `employee_id`, `leave_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
