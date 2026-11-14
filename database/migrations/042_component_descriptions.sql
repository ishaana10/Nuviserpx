-- Fixed allowances/deductions carry a description; per-employee assignments
-- carry a note (e.g. policy number); payroll snapshots keep the wording.
ALTER TABLE `salary_components` ADD COLUMN IF NOT EXISTS `description` varchar(255) DEFAULT NULL;
ALTER TABLE `employee_salary_components` ADD COLUMN IF NOT EXISTS `note` varchar(255) DEFAULT NULL;
ALTER TABLE `payroll_component_items` ADD COLUMN IF NOT EXISTS `description` varchar(255) DEFAULT NULL;
