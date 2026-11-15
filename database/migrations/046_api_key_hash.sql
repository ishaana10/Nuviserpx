-- API keys are stored as a SHA-256 hash; the plain key is shown once, when it is created.
-- Additive on purpose: existing keys keep working (their hash is filled in here) and the old plain-text
-- column is left alone, so rolling the code back is safe. Scrub it later with: php bin/scrub-api-keys.php --yes
ALTER TABLE `api_keys` ADD COLUMN IF NOT EXISTS `key_hash` CHAR(64) DEFAULT NULL;
ALTER TABLE `api_keys` ADD COLUMN IF NOT EXISTS `key_prefix` VARCHAR(16) DEFAULT NULL;
UPDATE `api_keys` SET `key_hash` = SHA2(`api_key`, 256), `key_prefix` = LEFT(`api_key`, 9) WHERE `key_hash` IS NULL AND `api_key` <> '' AND `api_key` NOT LIKE '%…%';
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_api_key_hash` ON `api_keys` (`key_hash`);
