-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 01, 2022 at 09:31 AM
-- Server version: 10.4.19-MariaDB
-- PHP Version: 8.0.7

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ajms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_code` (`tenant_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `tenants` (`id`, `tenant_code`, `name`, `email`, `phone`, `address`, `status`) VALUES
(1, 'TNT-001', 'Default Enterprise Tenant', 'info@nuvistechnologies.com.fj', '+679 123 4567', 'Suva, Fiji', 1);

-- --------------------------------------------------------

--
-- Table structure for table `account_list`
--

CREATE TABLE `account_list` (
  `id` int(30) NOT NULL,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `account_list`
--

INSERT INTO `account_list` (`id`, `name`, `description`, `status`, `delete_flag`, `date_created`, `date_updated`) VALUES
(1, 'Cash', 'Cash', 1, 0, '2022-02-01 10:09:26', NULL),
(2, 'Petty Cash', 'Petty Cash', 1, 0, '2022-02-01 10:09:40', NULL),
(3, 'Cash Equivalents', 'Cash Equivalents', 1, 0, '2022-02-01 10:09:56', NULL),
(4, 'Accounts Receivable', 'Accounts Receivable', 1, 0, '2022-02-01 10:10:22', NULL),
(5, 'Interest Receivable', 'Interest Receivable', 1, 0, '2022-02-01 10:10:57', NULL),
(6, 'Office Supplies', 'Office Supplies', 1, 0, '2022-02-01 10:11:13', NULL),
(7, 'Accounts Payable', 'Accounts Payable', 1, 0, '2022-02-01 10:11:55', NULL),
(8, 'Insurance Payable', 'Insurance Payable', 1, 0, '2022-02-01 10:12:07', NULL),
(9, 'Interest Payable', 'Interest Payable', 1, 0, '2022-02-01 10:12:20', NULL),
(10, 'Legal Fees Payable', 'Legal Fees Payable', 1, 0, '2022-02-01 10:12:35', NULL),
(11, 'Office Salaries Payable', 'Office Salaries Payable', 1, 0, '2022-02-01 10:12:51', NULL),
(12, 'Salaries Payable', 'Salaries Payable', 1, 0, '2022-02-01 10:13:03', NULL),
(13, 'Wages Payable', 'Wages Payable', 1, 0, '2022-02-01 10:13:24', NULL),
(14, 'Owner’s Capital', 'Owner’s Capital', 1, 0, '2022-02-01 10:13:54', NULL),
(15, 'Owner’s Withdrawals', 'Owner’s Withdrawals', 1, 0, '2022-02-01 10:14:04', NULL),
(16, 'Common Stock, Par Value', 'Common Stock, Par Value', 1, 0, '2022-02-01 10:14:25', NULL),
(17, 'Common stock, no par value', 'Common stock, no par value', 1, 0, '2022-02-01 10:14:34', NULL),
(18, 'Common stock dividend distributable', 'Common stock dividend distributable', 1, 0, '2022-02-01 10:14:50', NULL),
(19, 'Paid-in capital in excess of par value, Common stock', 'Paid-in capital in excess of par value, Common\r\nstock', 1, 0, '2022-02-01 10:15:02', NULL),
(20, 'Paid-in capital in excess of stated value, No-par common stock', 'Paid-in capital in excess of stated value, No-par\r\ncommon stock', 1, 0, '2022-02-01 10:15:11', NULL),
(21, 'Retained earnings', 'Retained earnings', 1, 0, '2022-02-01 10:15:31', NULL),
(22, 'Cash dividends', 'Cash dividends', 1, 0, '2022-02-01 10:15:44', NULL),
(23, 'Stock dividends', 'Stock dividends', 1, 0, '2022-02-01 10:15:58', NULL),
(24, 'Treasury stock, Common', 'Treasury stock, Common', 1, 0, '2022-02-01 10:16:48', NULL),
(25, 'Unrealized gain-Equity', 'Unrealized gain-Equity', 1, 0, '2022-02-01 10:16:57', NULL),
(26, 'Unrealized loss-Equity', 'Unrealized loss-Equity', 1, 0, '2022-02-01 10:17:05', NULL),
(27, 'Fees earned from product one', 'Fees earned from product one', 1, 0, '2022-02-01 10:17:27', NULL),
(28, 'Fees earned from product two', 'Fees earned from product two', 1, 0, '2022-02-01 10:17:43', NULL),
(29, 'Service revenue one', 'Service revenue one', 1, 0, '2022-02-01 10:17:55', NULL),
(30, 'Service revenue two', 'Service revenue two', 1, 0, '2022-02-01 10:18:04', NULL),
(31, 'Commissions earned', 'Commissions earned', 1, 0, '2022-02-01 10:18:14', NULL),
(32, 'Rent revenue', 'Rent revenue', 1, 0, '2022-02-01 10:18:26', NULL),
(33, 'Dividends revenue', 'Dividends revenue', 1, 0, '2022-02-01 10:18:40', NULL),
(34, 'Earnings from investments in “blank”', 'Earnings from investments in “blank”', 1, 0, '2022-02-01 10:18:51', NULL),
(35, 'Interest revenue', 'Interest revenue', 1, 0, '2022-02-01 10:19:03', NULL),
(36, 'Sinking fund earnings', 'Sinking fund earnings', 1, 0, '2022-02-01 11:41:57', NULL),
(37, 'Amortization expense', 'Amortization expense', 1, 0, '2022-02-01 11:42:08', NULL),
(38, 'Depletion expense', 'Depletion expense', 1, 0, '2022-02-01 11:42:16', NULL),
(39, 'Depreciation expense-Automobiles', 'Depreciation expense-Automobiles', 1, 0, '2022-02-01 11:42:25', NULL),
(40, 'Depreciation expense-Building', 'Depreciation expense-Building', 1, 0, '2022-02-01 11:42:34', NULL),
(41, 'Depreciation expense-Furniture', 'Depreciation expense-Furniture', 1, 0, '2022-02-01 11:43:02', NULL),
(42, 'Office salaries expense', 'Office salaries expense', 1, 0, '2022-02-01 11:43:12', NULL),
(43, 'Sales salaries expense', 'Sales salaries expense', 1, 0, '2022-02-01 11:43:21', NULL),
(44, 'Salaries expense', 'Salaries expense', 1, 0, '2022-02-01 11:43:31', NULL),
(45, 'Income taxes expense', 'Income taxes expense', 1, 0, '2022-02-01 11:43:44', NULL),
(46, 'Warranty expense', 'Warranty expense', 1, 0, '2022-02-01 11:44:01', NULL),
(47, 'Utilities expense', 'Utilities expense', 1, 0, '2022-02-01 11:44:10', NULL),
(48, 'Selling expense', 'Selling expense', 1, 0, '2022-02-01 11:44:23', NULL),
(49, 'Sample101', 'Sample101', 0, 1, '2022-02-01 11:45:03', '2022-02-01 11:45:23');

-- --------------------------------------------------------

--
-- Table structure for table `group_list`
--

CREATE TABLE `group_list` (
  `id` int(30) NOT NULL,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Debit, 2= Credit',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Inactive, 1= Active',
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = No, 1 = Yes ',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `group_list`
--

INSERT INTO `group_list` (`id`, `name`, `description`, `type`, `status`, `delete_flag`, `date_created`, `date_updated`) VALUES
(1, 'Assets', 'The cash, inventory, and other resources you owned.', 1, 1, 0, '2022-02-01 09:56:35', '2022-02-01 09:56:58'),
(2, 'Revenue', 'Cash coming into your business through sales and other types of payments', 2, 1, 0, '2022-02-01 09:57:45', NULL),
(3, 'Expenses', 'The amount you’re spending on services and other items, like payroll, utility bills, and fees for contractors.', 1, 1, 0, '2022-02-01 09:58:09', '2022-02-01 09:59:13'),
(4, 'Liabilities', 'The money you still owe on loans, debts, and other obligations.', 2, 1, 0, '2022-02-01 09:58:34', NULL),
(5, 'Equity', 'How much is remaining after you subtract liabilities from assets.', 2, 1, 0, '2022-02-01 09:59:05', NULL),
(6, 'Dividend', 'Form of income that shareholders of corporations receive for each share of stock that they hold.', 1, 1, 0, '2022-02-01 10:00:13', NULL),
(7, 'Sample101', 'Sample', 1, 0, 1, '2022-02-01 10:01:35', '2022-02-01 10:03:28');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(30) NOT NULL,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `code` varchar(100) NOT NULL,
  `journal_date` date NOT NULL,
  `description` text NOT NULL,
  `user_id` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `code`, `journal_date`, `description`, `user_id`, `date_created`, `date_updated`) VALUES
(3, '202202-00001', '2022-02-01', 'Capital', 1, '2022-02-01 14:08:50', NULL),
(4, '202202-00002', '2022-02-01', 'Sample', 1, '2022-02-01 15:55:46', NULL),
(5, '202202-00003', '2022-02-01', 'Sample 102', 1, '2022-02-01 15:59:34', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `journal_items`
--

CREATE TABLE `journal_items` (
  `journal_id` int(30) NOT NULL,
  `account_id` int(30) NOT NULL,
  `group_id` int(30) NOT NULL,
  `amount` float NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `journal_items`
--

INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`, `date_created`) VALUES
(3, 1, 1, 15000, '2022-02-01 14:52:56'),
(3, 14, 5, 15000, '2022-02-01 14:52:56'),
(4, 42, 3, 5000, '2022-02-01 15:55:46'),
(4, 11, 4, 5000, '2022-02-01 15:55:46'),
(5, 31, 2, 5000, '2022-02-01 15:59:34'),
(5, 31, 2, 3000, '2022-02-01 15:59:34'),
(5, 4, 1, 8000, '2022-02-01 15:59:34');

-- --------------------------------------------------------

--
-- Table structure for table `system_info`
--

CREATE TABLE `system_info` (
  `id` int(30) NOT NULL,
  `meta_field` text NOT NULL,
  `meta_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `system_info`
--

INSERT INTO `system_info` (`id`, `meta_field`, `meta_value`) VALUES
(1, 'name', 'Nuvis ERPX'),
(6, 'short_name', 'Nuvis ERPX'),
(11, 'logo', 'uploads/logo-1643680475.png'),
(13, 'user_avatar', 'uploads/user_avatar.jpg'),
(14, 'cover', 'uploads/cover-1643680511.png'),
(15, 'content', 'Array'),
(16, 'email', 'info@nuvistechnologies.com.fj'),
(17, 'contact', '09854698789 / 78945632'),
(18, 'smtp_host', 'smtp.gmail.com'),
(19, 'smtp_port', '587'),
(20, 'smtp_user', 'no-reply@nuvistechnologies.com.fj'),
(21, 'smtp_pass', ''),
(22, 'smtp_encryption', 'tls');

-- --------------------------------------------------------

--
-- Table structure for table `otp_tokens`
--

CREATE TABLE `otp_tokens` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `user_id` int(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = Pending, 1 = Used',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `permissions` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  UNIQUE KEY `role_tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
  `id` int(50) NOT NULL,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `firstname` varchar(250) NOT NULL,
  `middlename` text DEFAULT NULL,
  `lastname` varchar(250) NOT NULL,
  `username` text NOT NULL,
  `password` text NOT NULL,
  `avatar` text DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `role_id` int(30) DEFAULT NULL,
  `status` int(1) NOT NULL DEFAULT 1 COMMENT '0=not verified, 1 = verified',
  `date_added` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `firstname`, `middlename`, `lastname`, `username`, `password`, `avatar`, `last_login`, `type`, `status`, `date_added`, `date_updated`) VALUES
(1, 'Adminstrator', NULL, 'Admin', 'admin', '0192023a7bbd73250516f069df18b500', 'uploads/avatar-1.png?v=1643703899', NULL, 1, 1, '2021-01-20 14:02:37', '2022-02-01 16:24:59'),
(5, 'Claire', NULL, 'Blake', 'cblake', '4744ddea876b11dcb1d169fadf494418', 'uploads/avatar-5.png?v=1643704129', NULL, 2, 1, '2022-02-01 16:28:49', '2022-02-01 16:28:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_list`
--
ALTER TABLE `account_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `group_list`
--
ALTER TABLE `group_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `journal_items`
--
ALTER TABLE `journal_items`
  ADD KEY `journal_id` (`journal_id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `system_info`
--
ALTER TABLE `system_info`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_list`
--
ALTER TABLE `account_list`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `group_list`
--
ALTER TABLE `group_list`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `system_info`
--
ALTER TABLE `system_info`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_items`
--
ALTER TABLE `journal_items`
  ADD CONSTRAINT `journal_items_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_items_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `account_list` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_items_ibfk_3` FOREIGN KEY (`group_id`) REFERENCES `group_list` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `employee_list`
--

CREATE TABLE `employee_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_code` varchar(50) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tin` varchar(50) DEFAULT NULL,
  `fnpf_no` varchar(50) DEFAULT NULL,
  `tax_code` varchar(10) NOT NULL DEFAULT 'P',
  `is_resident` tinyint(1) NOT NULL DEFAULT 1,
  `bank_code` varchar(20) DEFAULT 'bsp',
  `bank_account` varchar(50) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  UNIQUE KEY `emp_tenant_code` (`tenant_id`, `employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_list`
--

CREATE TABLE `attendance_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `status` enum('present','absent','late','half_day') NOT NULL DEFAULT 'present',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_date` (`employee_id`,`attendance_date`),
  KEY `tenant_id` (`tenant_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `leave_list`
--

CREATE TABLE `leave_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) NOT NULL,
  `leave_type` enum('casual','sick','paid','unpaid') NOT NULL DEFAULT 'casual',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `leave_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_list`
--

-- --------------------------------------------------------

--
-- Table structure for table `party_list`
--

CREATE TABLE IF NOT EXISTS `party_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `party_code` varchar(50) NOT NULL,
  `name` varchar(250) NOT NULL,
  `type` enum('customer','vendor') NOT NULL DEFAULT 'customer',
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  UNIQUE KEY `party_tenant_code` (`tenant_id`, `party_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_list`
--

CREATE TABLE IF NOT EXISTS `invoice_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `invoice_no` varchar(100) NOT NULL,
  `party_id` int(30) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','unpaid','partially_paid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `journal_id` int(30) DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `party_id` (`party_id`),
  KEY `journal_id` (`journal_id`),
  CONSTRAINT `invoice_party_fk` FOREIGN KEY (`party_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(30) NOT NULL,
  `item_name` varchar(250) NOT NULL,
  `description` text DEFAULT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `item_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoice_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `payment_list`
--

CREATE TABLE IF NOT EXISTS `payment_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `payment_no` varchar(100) NOT NULL,
  `type` enum('receipt','payment') NOT NULL DEFAULT 'receipt',
  `party_id` int(30) NOT NULL,
  `bank_account_id` int(30) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `method` varchar(50) NOT NULL DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'posted',
  `currency` varchar(10) NOT NULL DEFAULT 'FJD',
  `journal_id` int(30) DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `party_id` (`party_id`),
  KEY `journal_id` (`journal_id`),
  CONSTRAINT `payment_party_fk` FOREIGN KEY (`party_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `payment_allocations`
--

CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `payment_id` int(30) NOT NULL,
  `invoice_id` int(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `alloc_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payment_list` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alloc_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoice_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- --------------------------------------------------------

--
-- Table structure for table `product_list`
--

CREATE TABLE IF NOT EXISTS `product_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `product_code` varchar(50) NOT NULL,
  `name` varchar(250) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `unit` varchar(50) NOT NULL DEFAULT 'pcs',
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reorder_level` decimal(12,2) NOT NULL DEFAULT 10.00,
  `description` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `delete_flag` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  UNIQUE KEY `prod_tenant_code` (`tenant_id`, `product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE IF NOT EXISTS `inventory_logs` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `product_id` int(30) NOT NULL,
  `type` enum('in','out','adjustment') NOT NULL DEFAULT 'in',
  `qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `log_product_fk` FOREIGN KEY (`product_id`) REFERENCES `product_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `sales_order_list`
--

CREATE TABLE IF NOT EXISTS `sales_order_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `order_no` varchar(100) NOT NULL,
  `party_id` int(30) NOT NULL,
  `order_date` date NOT NULL,
  `type` enum('proforma','sales_order') NOT NULL DEFAULT 'proforma',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','proforma','confirmed','converted_to_invoice','cancelled') NOT NULL DEFAULT 'proforma',
  `stock_adjusted` tinyint(1) NOT NULL DEFAULT 0,
  `invoice_id` int(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `party_id` (`party_id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `order_party_fk` FOREIGN KEY (`party_id`) REFERENCES `party_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `sales_order_items`
--

CREATE TABLE IF NOT EXISTS `sales_order_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `sales_order_id` int(30) NOT NULL,
  `product_id` int(30) DEFAULT NULL,
  `item_name` varchar(250) NOT NULL,
  `description` text DEFAULT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `sales_order_id` (`sales_order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `item_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_order_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `api_key` varchar(100) NOT NULL,
  `api_secret` varchar(100) NOT NULL,
  `permissions` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  UNIQUE KEY `key_tenant` (`tenant_id`, `api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `webhooks`
--

CREATE TABLE IF NOT EXISTS `webhooks` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `event` varchar(100) NOT NULL,
  `target_url` varchar(255) NOT NULL,
  `secret_token` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(30) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

CREATE TABLE `payroll_list` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(30) NOT NULL DEFAULT 1,
  `employee_id` int(30) NOT NULL,
  `journal_id` int(30) DEFAULT NULL,
  `payroll_run_ref` varchar(100) DEFAULT NULL,
  `salary_month` varchar(50) NOT NULL,
  `pay_frequency` varchar(20) NOT NULL DEFAULT 'Monthly',
  `pay_date` date DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `overtime` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allowances` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fnpf_base` decimal(12,2) NOT NULL DEFAULT 0.00,
  `employee_fnpf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `employer_fnpf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_income` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paye_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `srt_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `workcare_levy` decimal(12,2) NOT NULL DEFAULT 0.00,
  `training_levy` decimal(12,2) NOT NULL DEFAULT 0.00,
  `employer_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tin` varchar(50) DEFAULT NULL,
  `fnpf_no` varchar(50) DEFAULT NULL,
  `tax_code` varchar(10) DEFAULT 'P',
  `is_resident` tinyint(1) DEFAULT 1,
  `bank_code` varchar(20) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `employee_id` (`employee_id`),
  KEY `journal_id` (`journal_id`),
  CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee_list` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_ibfk_2` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
