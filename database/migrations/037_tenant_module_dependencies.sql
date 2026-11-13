-- Tenant modules now cover invoicing, inventory and purchasing, which were
-- previously ungoverned (always on). Tenants that already have a recorded
-- module assignment get the new keys appended so nothing they use switches
-- off; tenants with NULL stay unrestricted.
ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `modules` text DEFAULT NULL;
UPDATE `tenants`
   SET `modules` = TRIM(BOTH ',' FROM CONCAT(IFNULL(`modules`, ''), ',mod_sales,mod_inventory,mod_purchases'))
 WHERE `modules` IS NOT NULL;
