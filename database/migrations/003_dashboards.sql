-- Per-user customisable dashboards (widget layouts stored as JSON)
CREATE TABLE IF NOT EXISTS `dashboards` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `user_id` int(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `layout` longtext NOT NULL,
  `date_range` varchar(20) NOT NULL DEFAULT 'this_month',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dash_owner` (`tenant_id`, `user_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
