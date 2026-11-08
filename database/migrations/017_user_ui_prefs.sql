-- Per-user look and feel (colour mode, accent, side menu style, density, text size, pinned menu items).
CREATE TABLE IF NOT EXISTS `user_ui_prefs` (
  `user_id` int(30) NOT NULL,
  `prefs` text NOT NULL,
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
