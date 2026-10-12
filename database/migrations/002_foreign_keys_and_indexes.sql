-- Indexes and Constraints Migration
CREATE INDEX IF NOT EXISTS `idx_party_tenant` ON `party_list` (`tenant_id`, `delete_flag`);
CREATE INDEX IF NOT EXISTS `idx_product_tenant` ON `product_list` (`tenant_id`, `delete_flag`);
CREATE INDEX IF NOT EXISTS `idx_invoice_tenant` ON `invoice_list` (`tenant_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_payment_tenant` ON `payment_list` (`tenant_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_sales_order_tenant` ON `sales_order_list` (`tenant_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_inventory_product` ON `inventory_logs` (`tenant_id`, `product_id`);
CREATE INDEX IF NOT EXISTS `idx_payroll_employee` ON `payroll_list` (`tenant_id`, `employee_id`);
