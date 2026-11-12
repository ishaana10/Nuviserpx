-- journal_items had no primary key, but the dashboard bank widget, ledger
-- ordering and the journals list all reference ji.id ("Unknown column 'ji.id'
-- in 'where clause'"). Give every journal line a real identity.
ALTER TABLE `journal_items` ADD COLUMN IF NOT EXISTS `id` BIGINT(20) NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST;
