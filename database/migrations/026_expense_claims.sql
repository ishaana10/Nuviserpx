-- Expense claims that reach the ledger: categories mapped to expense accounts, approval and reimbursement postings.
ALTER TABLE `expense_reports` MODIFY `status` enum('pending','approved','reimbursed','rejected','cancelled','voided') NOT NULL DEFAULT 'pending';
ALTER TABLE `expense_reports` ADD COLUMN `submitted_by` int(30) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `approved_at` datetime DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `approval_note` varchar(500) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `expense_account_id` int(30) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `journal_id` int(30) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `payment_date` date DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `payment_account_id` int(30) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `payment_journal_id` int(30) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `payment_reference` varchar(100) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD COLUMN `reimbursed_by` int(30) DEFAULT NULL;
ALTER TABLE `expense_reports` ADD INDEX `journal_id` (`journal_id`);
ALTER TABLE `expense_reports` ADD INDEX `payment_journal_id` (`payment_journal_id`);

CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `account_id` int(30) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
