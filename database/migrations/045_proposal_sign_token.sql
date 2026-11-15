-- Secret link token for customer proposal sign-off. Without it a proposal could be opened by counting up ids.
-- Tokens are created on demand when staff copy the signing link (see ContractService::proposal_link).
ALTER TABLE `sales_order_list` ADD COLUMN IF NOT EXISTS `sign_token` char(64) DEFAULT NULL;
