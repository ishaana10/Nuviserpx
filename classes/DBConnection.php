<?php
if(!defined('DB_SERVER')){
    require_once("../initialize.php");
}
class DBConnection{

    private $host = DB_SERVER;
    private $username = DB_USERNAME;
    private $password = DB_PASSWORD;
    private $database = DB_NAME;
    
    public $conn;
    
    public function __construct(){

        if (!isset($this->conn)) {
            // Disable default mysqli exception throwing to handle errors gracefully
            if (function_exists('mysqli_report')) {
                mysqli_report(MYSQLI_REPORT_OFF);
            }

            // Attempt connection to host and database
            @$this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
            
            if ($this->conn->connect_error) {
                // If database does not exist or access failed, connect without database to create it
                @$server_conn = new mysqli($this->host, $this->username, $this->password);
                if ($server_conn->connect_error) {
                    die("<div style='padding:20px; font-family:sans-serif; background:#fee2e2; border:1px solid #f87171; color:#991b1b; border-radius:8px;'>
                        <h3 style='margin-top:0;'>Database Connection Failed</h3>
                        <p>Unable to connect to the MySQL database server at <b>{$this->host}</b> with username <b>{$this->username}</b>.</p>
                        <p><b>Error Details:</b> " . htmlspecialchars($server_conn->connect_error) . "</p>
                        <p>Please check your database credentials in <code>initialize.php</code>.</p>
                    </div>");
                }

                // Auto-create database
                $create_db_sql = "CREATE DATABASE IF NOT EXISTS `" . $this->database . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                if ($server_conn->query($create_db_sql)) {
                    $server_conn->close();
                    @$this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
                } else {
                    die("<div style='padding:20px; font-family:sans-serif; background:#fee2e2; border:1px solid #f87171; color:#991b1b; border-radius:8px;'>
                        <h3 style='margin-top:0;'>Database Auto-Creation Failed</h3>
                        <p>Could not auto-create database <b>{$this->database}</b>: " . htmlspecialchars($server_conn->error) . "</p>
                    </div>");
                }
            }

            // Auto-install schema if system_info table is missing
            $check_tbl = @$this->conn->query("SHOW TABLES LIKE 'system_info'");
            if (!$check_tbl || $check_tbl->num_rows == 0) {
                $this->auto_install_schema();
            } else {
                $this->check_fiji_payroll_migrations();
                $this->check_invoicing_migrations();
                $this->check_stock_migrations();
                $this->check_api_migrations();
                $this->check_rbac_migrations();
            }

            // Run versioned migrations
            if (class_exists('App\Database\MigrationRunner')) {
                $runner = new \App\Database\MigrationRunner($this->conn);
                $runner->run(base_app . 'database/migrations');
            }
        }    
        
    }

    public function check_fiji_payroll_migrations(){
        if (!$this->conn) return;

        // Check employee_list columns
        $emp_cols = array(
            'tin' => "ALTER TABLE `employee_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL AFTER `salary`",
            'fnpf_no' => "ALTER TABLE `employee_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL AFTER `tin`",
            'tax_code' => "ALTER TABLE `employee_list` ADD COLUMN `tax_code` varchar(10) NOT NULL DEFAULT 'P' AFTER `fnpf_no`",
            'is_resident' => "ALTER TABLE `employee_list` ADD COLUMN `is_resident` tinyint(1) NOT NULL DEFAULT 1 AFTER `tax_code`",
            'bank_code' => "ALTER TABLE `employee_list` ADD COLUMN `bank_code` varchar(20) DEFAULT 'bsp' AFTER `is_resident`",
            'bank_account' => "ALTER TABLE `employee_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL AFTER `bank_code`"
        );

        foreach ($emp_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `employee_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }

        // Check payroll_list columns
        $pay_cols = array(
            'payroll_run_ref' => "ALTER TABLE `payroll_list` ADD COLUMN `payroll_run_ref` varchar(100) DEFAULT NULL AFTER `journal_id`",
            'pay_frequency' => "ALTER TABLE `payroll_list` ADD COLUMN `pay_frequency` varchar(20) NOT NULL DEFAULT 'Monthly' AFTER `salary_month`",
            'pay_date' => "ALTER TABLE `payroll_list` ADD COLUMN `pay_date` date DEFAULT NULL AFTER `pay_frequency`",
            'period_start' => "ALTER TABLE `payroll_list` ADD COLUMN `period_start` date DEFAULT NULL AFTER `pay_date`",
            'period_end' => "ALTER TABLE `payroll_list` ADD COLUMN `period_end` date DEFAULT NULL AFTER `period_start`",
            'overtime' => "ALTER TABLE `payroll_list` ADD COLUMN `overtime` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `basic_salary`",
            'other_earnings' => "ALTER TABLE `payroll_list` ADD COLUMN `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `allowances`",
            'gross_salary' => "ALTER TABLE `payroll_list` ADD COLUMN `gross_salary` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `other_earnings`",
            'fnpf_base' => "ALTER TABLE `payroll_list` ADD COLUMN `fnpf_base` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `gross_salary`",
            'employee_fnpf' => "ALTER TABLE `payroll_list` ADD COLUMN `employee_fnpf` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `fnpf_base`",
            'employer_fnpf' => "ALTER TABLE `payroll_list` ADD COLUMN `employer_fnpf` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `employee_fnpf`",
            'taxable_income' => "ALTER TABLE `payroll_list` ADD COLUMN `taxable_income` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `employer_fnpf`",
            'paye_tax' => "ALTER TABLE `payroll_list` ADD COLUMN `paye_tax` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `taxable_income`",
            'srt_tax' => "ALTER TABLE `payroll_list` ADD COLUMN `srt_tax` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `paye_tax`",
            'workcare_levy' => "ALTER TABLE `payroll_list` ADD COLUMN `workcare_levy` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `net_salary`",
            'training_levy' => "ALTER TABLE `payroll_list` ADD COLUMN `training_levy` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `workcare_levy`",
            'employer_cost' => "ALTER TABLE `payroll_list` ADD COLUMN `employer_cost` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `training_levy`",
            'tin' => "ALTER TABLE `payroll_list` ADD COLUMN `tin` varchar(50) DEFAULT NULL AFTER `employer_cost`",
            'fnpf_no' => "ALTER TABLE `payroll_list` ADD COLUMN `fnpf_no` varchar(50) DEFAULT NULL AFTER `tin`",
            'tax_code' => "ALTER TABLE `payroll_list` ADD COLUMN `tax_code` varchar(10) DEFAULT 'P' AFTER `fnpf_no`",
            'is_resident' => "ALTER TABLE `payroll_list` ADD COLUMN `is_resident` tinyint(1) DEFAULT 1 AFTER `tax_code`",
            'bank_code' => "ALTER TABLE `payroll_list` ADD COLUMN `bank_code` varchar(20) DEFAULT NULL AFTER `is_resident`",
            'bank_account' => "ALTER TABLE `payroll_list` ADD COLUMN `bank_account` varchar(50) DEFAULT NULL AFTER `bank_code`"
        );

        foreach ($pay_cols as $col => $sql) {
            $res = @$this->conn->query("SHOW COLUMNS FROM `payroll_list` LIKE '{$col}'");
            if ($res && $res->num_rows == 0) {
                @$this->conn->query($sql);
            }
        }
    }

    public function check_invoicing_migrations(){
        if (!$this->conn) return;

        $queries = array(
            "CREATE TABLE IF NOT EXISTS `party_list` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `invoice_list` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `invoice_items` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `payment_list` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `payment_allocations` (
              `id` int(30) NOT NULL AUTO_INCREMENT,
              `payment_id` int(30) NOT NULL,
              `invoice_id` int(30) NOT NULL,
              `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              PRIMARY KEY (`id`),
              KEY `payment_id` (`payment_id`),
              KEY `invoice_id` (`invoice_id`),
              CONSTRAINT `alloc_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payment_list` (`id`) ON DELETE CASCADE,
              CONSTRAINT `alloc_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoice_list` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }
    }

    public function check_rbac_migrations(){
        if (!$this->conn) return;

        // Check roles table
        $sql = "CREATE TABLE IF NOT EXISTS `roles` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        @$this->conn->query($sql);

        // Check role_id column in users table
        $res = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE 'role_id'");
        if ($res && $res->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `users` ADD COLUMN `role_id` int(30) DEFAULT NULL AFTER `type` ");
        }

        // Check employee_id column in users table
        $res_emp = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE 'employee_id'");
        if ($res_emp && $res_emp->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `users` ADD COLUMN `employee_id` int(30) DEFAULT NULL AFTER `role_id` ");
        }

        // Check tenant_id column in users table
        $res_t = @$this->conn->query("SHOW COLUMNS FROM `users` LIKE 'tenant_id'");
        if ($res_t && $res_t->num_rows == 0) {
            @$this->conn->query("ALTER TABLE `users` ADD COLUMN `tenant_id` int(30) NOT NULL DEFAULT 1 AFTER `id` ");
        }
    }

    public function check_api_migrations(){
        if (!$this->conn) return;

        $queries = array(
            "CREATE TABLE IF NOT EXISTS `api_keys` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `webhooks` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }
    }

    public function check_stock_migrations(){
        if (!$this->conn) return;

        $queries = array(
            "CREATE TABLE IF NOT EXISTS `product_list` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `inventory_logs` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `sales_order_list` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `sales_order_items` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($queries as $sql) {
            @$this->conn->query($sql);
        }
    }

    public function auto_install_schema(){
        $sql_file = base_app . 'database/nuviserpx.sql';
        if (file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            if (!empty($sql_content)) {
                if (@$this->conn->multi_query($sql_content)) {
                    do {
                        if ($result = $this->conn->store_result()) {
                            $result->free();
                        }
                    } while ($this->conn->more_results() && $this->conn->next_result());
                } else {
                    // Fallback to split execution if multi_query is restricted
                    $queries = preg_split("/;[\r\n]+/", $sql_content);
                    foreach ($queries as $query) {
                        $query = trim($query);
                        if (!empty($query)) {
                            @$this->conn->query($query);
                        }
                    }
                }
            }
        }
    }

    public function __destruct(){
        if (isset($this->conn) && $this->conn instanceof mysqli) {
            @$this->conn->close();
        }
    }
}
?>