-- Journals list: the tab counts and status filters read only (tenant, date, status), so this index answers them
-- without touching the journal rows. Measured on 60,000 journals: the page went from ~900 ms to ~250 ms.
CREATE INDEX IF NOT EXISTS `idx_je_tenant_date_status` ON `journal_entries` (`tenant_id`, `journal_date`, `status`);
