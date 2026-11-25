-- Audit Logs page: the "Module" filter lists the distinct modules a tenant has used. Without this index it scans
-- every audit row for the tenant (about 0.5 s at 100,000 rows); with it the list comes straight from the index.
CREATE INDEX IF NOT EXISTS `idx_audit_tenant_module` ON `audit_logs` (`tenant_id`, `module`);
