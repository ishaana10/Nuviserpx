-- Pay is set as an annual gross figure; per-period pay derives from it.
ALTER TABLE `employee_list` ADD COLUMN IF NOT EXISTS `annual_salary` decimal(14,2) DEFAULT NULL;
UPDATE `employee_list` SET `annual_salary` = `salary` * 12
 WHERE (`annual_salary` IS NULL OR `annual_salary` = 0) AND `salary` > 0;
