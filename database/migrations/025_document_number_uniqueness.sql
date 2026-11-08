-- One document number per company. Purchase orders and vendor bills already have this from migration 005. Each statement is skipped by the database if duplicates already exist,
-- and the application checks typed numbers itself before saving.
ALTER TABLE `sales_order_list` ADD UNIQUE INDEX `tenant_order_no` (`tenant_id`, `order_no`);
ALTER TABLE `expense_reports` ADD UNIQUE INDEX `tenant_claim_no` (`tenant_id`, `claim_no`);
ALTER TABLE `contracts` ADD UNIQUE INDEX `tenant_contract_no` (`tenant_id`, `contract_no`);
ALTER TABLE `tickets` ADD UNIQUE INDEX `tenant_ticket_code` (`tenant_id`, `ticket_code`);
