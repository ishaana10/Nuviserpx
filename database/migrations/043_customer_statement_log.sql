-- When each customer was last emailed an unpaid-invoices statement (so nobody is chased twice by accident).
CREATE TABLE IF NOT EXISTS `customer_statement_log` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL,
  `party_id` int(30) NOT NULL,
  `as_of` date NOT NULL,
  `sent_to` varchar(190) NOT NULL,
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `sent_by` int(30) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_party` (`tenant_id`,`party_id`,`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
