<?php
require_once __DIR__ . '/../config.php';

echo "=== TEST: Customer Portal DB & Logic Check ===\n";

// 1. Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS `customer_portal_users` (
    `id` int(30) NOT NULL AUTO_INCREMENT,
    `client_id` int(30) NOT NULL,
    `name` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `password` varchar(255) DEFAULT NULL,
    `invitation_token` varchar(255) DEFAULT NULL,
    `status` tinyint(1) NOT NULL DEFAULT 0,
    `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `client_id` (`client_id`),
    KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$res = $conn->query("SHOW TABLES LIKE 'customer_portal_users'");
if ($res->num_rows !== 1) {
    echo "FAILED: customer_portal_users table does not exist.\n";
    exit(1);
}

// 2. Insert test customer
$conn->query("INSERT INTO `client_list` (`name`, `email`, `type`, `status`) VALUES ('Portal Test Customer', 'portal_test@example.com', 'customer', 1)");
$client_id = $conn->insert_id;

// 3. Insert portal user invitation
$token = bin2hex(random_bytes(16));
$conn->query("INSERT INTO `customer_portal_users` (`client_id`, `name`, `email`, `invitation_token`, `status`) VALUES ('{$client_id}', 'Portal Test Customer', 'portal_test@example.com', '{$token}', 0)");
$user_id = $conn->insert_id;

if (!$user_id) {
    echo "FAILED: Could not create portal user record.\n";
    exit(1);
}

// 4. Test account activation
$password = password_hash('Secret123!', PASSWORD_BCRYPT);
$conn->query("UPDATE `customer_portal_users` SET `password` = '{$password}', `status` = 1, `invitation_token` = NULL WHERE id = '{$user_id}' AND `invitation_token` = '{$token}'");

// 5. Test login query verification
$qry = $conn->query("SELECT * FROM `customer_portal_users` WHERE email = 'portal_test@example.com' AND status = 1");
if ($qry->num_rows !== 1) {
    echo "FAILED: Could not fetch active portal user.\n";
    exit(1);
}

$user = $qry->fetch_assoc();
if (!password_verify('Secret123!', $user['password'])) {
    echo "FAILED: Password verification failed.\n";
    exit(1);
}

// Clean up
$conn->query("DELETE FROM `customer_portal_users` WHERE id = '{$user_id}'");
$conn->query("DELETE FROM `client_list` WHERE id = '{$client_id}'");

echo "CUSTOMER PORTAL DB & LOGIC TEST PASSED!\n";
