-- Migration 006: Bank reconciliation.
-- Nuvis ERPX — Nuvis Technologies
--
-- Lets a user reconcile a bank/cash GL account against a bank statement:
-- enter the statement's opening/closing balance and its dated lines, match each
-- statement line to a posted journal entry that touches the bank account, and
-- finalize the reconciliation once the matched book movement agrees with the
-- statement movement.
--
-- Non-invasive by design: matches reference journal_entries.id (a stable PK) +
-- the bank account_id, so no changes are made to journal_items or any core
-- accounting table. All tables are tenant-scoped.

-- A reconciliation session for one bank account against one statement period.
CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `account_id` int(30) NOT NULL,
  `statement_date` date NOT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `closing_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('in_progress','reconciled') NOT NULL DEFAULT 'in_progress',
  `reconciled_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `account_id` (`account_id`),
  KEY `rec_status` (`tenant_id`, `account_id`, `status`),
  CONSTRAINT `rec_account_fk` FOREIGN KEY (`account_id`) REFERENCES `account_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lines read off the bank statement (imported from CSV or entered by hand).
-- `amount` is signed: positive = money into the account (deposit/credit on the
-- statement), negative = money out (withdrawal/debit).
CREATE TABLE IF NOT EXISTS `bank_statement_lines` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `reconciliation_id` int(30) NOT NULL,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `line_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `matched_journal_id` int(30) DEFAULT NULL,
  `status` enum('unmatched','matched') NOT NULL DEFAULT 'unmatched',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reconciliation_id` (`reconciliation_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `matched_journal_id` (`matched_journal_id`),
  CONSTRAINT `stmt_rec_fk` FOREIGN KEY (`reconciliation_id`) REFERENCES `bank_reconciliations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A confirmed match between a statement line and a book (journal) line on the
-- bank account. One row per cleared statement line. Kept separate from
-- bank_statement_lines so match history survives even if a line is re-matched.
CREATE TABLE IF NOT EXISTS `bank_reconciliation_matches` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `reconciliation_id` int(30) NOT NULL,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `statement_line_id` int(30) DEFAULT NULL,
  `journal_id` int(30) NOT NULL,
  `account_id` int(30) NOT NULL,
  `book_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `matched_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reconciliation_id` (`reconciliation_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `journal_id` (`journal_id`),
  UNIQUE KEY `rec_journal` (`reconciliation_id`, `journal_id`),
  CONSTRAINT `match_rec_fk` FOREIGN KEY (`reconciliation_id`) REFERENCES `bank_reconciliations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
