-- Draft invoices used to save their journal as "posted", so Trial Balance, Balance Sheet and Profit & Loss counted
-- receivables and revenue for invoices that had not been issued. Mark those journals as drafts, which every ledger
-- report ignores. Journals in a closed period are left alone (the period lock refuses changes to them).
UPDATE `journal_entries` j
JOIN `invoice_list` i ON i.journal_id = j.id
SET j.status = 'draft'
WHERE i.status = 'draft'
  AND j.status = 'posted'
  AND COALESCE((SELECT p.lock_date FROM `period_locks` p WHERE p.tenant_id = j.tenant_id ORDER BY p.id DESC LIMIT 1), '1000-01-01') < j.journal_date;
