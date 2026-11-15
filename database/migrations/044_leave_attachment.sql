-- A medical certificate (or similar) the employee attaches to a leave request. The file itself lives in
-- uploads/leave_docs/ (private, served only through a signed-in script); these columns say which file.
ALTER TABLE `leave_list` ADD COLUMN IF NOT EXISTS `attachment_path` varchar(255) DEFAULT NULL;
ALTER TABLE `leave_list` ADD COLUMN IF NOT EXISTS `attachment_name` varchar(150) DEFAULT NULL;
ALTER TABLE `leave_list` ADD COLUMN IF NOT EXISTS `attachment_size` int(11) DEFAULT NULL;
