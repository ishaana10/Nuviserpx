<?php
header('Content-Type: application/json');

$lock_file = __DIR__ . '/installed.lock';
if (file_exists($lock_file)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'System is already installed. Delete install/installed.lock to re-install.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$db_server   = trim($_POST['db_server'] ?? 'localhost');
$db_name     = trim($_POST['db_name'] ?? 'nuviserpx');
$db_username = trim($_POST['db_username'] ?? 'root');
$db_password = $_POST['db_password'] ?? '';

$app_name    = trim($_POST['app_name'] ?? 'NuvisERPX Enterprise ERP');
$short_name  = trim($_POST['short_name'] ?? 'NuvisERPX');
$app_url     = trim($_POST['app_url'] ?? '');

$admin_firstname = trim($_POST['admin_firstname'] ?? 'Super');
$admin_lastname  = trim($_POST['admin_lastname'] ?? 'Admin');
$admin_username  = trim($_POST['admin_username'] ?? '');
$admin_password  = $_POST['admin_password'] ?? '';

if (empty($admin_username) || empty($admin_password)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please specify a Super Administrator username and password.'
    ]);
    exit;
}

if (strlen($admin_password) < 6) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Administrator password must be at least 6 characters long.'
    ]);
    exit;
}

// Format app URL with trailing slash
if (substr($app_url, -1) !== '/') {
    $app_url .= '/';
}

// 1. Connect to MySQL server
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$conn = @new mysqli($db_server, $db_username, $db_password);
if ($conn->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to connect to MySQL database server: ' . $conn->connect_error
    ]);
    exit;
}

// 2. Create database if it doesn't exist
$create_db_sql = "CREATE DATABASE IF NOT EXISTS `" . $conn->real_escape_string($db_name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if (!$conn->query($create_db_sql)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to create database ' . $db_name . ': ' . $conn->error
    ]);
    $conn->close();
    exit;
}

$conn->select_db($db_name);

// 3. Import Schema nuviserpx.sql
$sql_file = __DIR__ . '/../database/nuviserpx.sql';
if (!file_exists($sql_file)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database schema file database/nuviserpx.sql not found.'
    ]);
    $conn->close();
    exit;
}

$sql_content = file_get_contents($sql_file);
if (!empty($sql_content)) {
    if (@$conn->multi_query($sql_content)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    } else {
        $queries = preg_split("/;[\r\n]+/", $sql_content);
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                @$conn->query($query);
            }
        }
    }
}

// 4. Update system_info branding
$conn->query("UPDATE `system_info` SET `meta_value` = '" . $conn->real_escape_string($app_name) . "' WHERE `meta_field` = 'name'");
$conn->query("UPDATE `system_info` SET `meta_value` = '" . $conn->real_escape_string($short_name) . "' WHERE `meta_field` = 'short_name'");

// 5. Ensure Users Table Columns
$res_role = @$conn->query("SHOW COLUMNS FROM `users` LIKE 'role_id'");
if ($res_role && $res_role->num_rows == 0) {
    @$conn->query("ALTER TABLE `users` ADD COLUMN `role_id` int(30) DEFAULT NULL AFTER `type` ");
}
$res_emp = @$conn->query("SHOW COLUMNS FROM `users` LIKE 'employee_id'");
if ($res_emp && $res_emp->num_rows == 0) {
    @$conn->query("ALTER TABLE `users` ADD COLUMN `employee_id` int(30) DEFAULT NULL AFTER `role_id` ");
}
$res_tenant = @$conn->query("SHOW COLUMNS FROM `users` LIKE 'tenant_id'");
if ($res_tenant && $res_tenant->num_rows == 0) {
    @$conn->query("ALTER TABLE `users` ADD COLUMN `tenant_id` int(30) NOT NULL DEFAULT 1 AFTER `id` ");
}

// 6. Remove any default exposed sample admin users if existing
$conn->query("DELETE FROM `users` WHERE `username` = 'admin' OR `username` = 'dev_oretnom'");

// 7. Insert Custom Super Admin / Developer user with Bcrypt password hashing
$hashed_password = password_hash($admin_password, PASSWORD_BCRYPT);
$fn_esc = $conn->real_escape_string($admin_firstname);
$ln_esc = $conn->real_escape_string($admin_lastname);
$un_esc = $conn->real_escape_string($admin_username);
$pw_esc = $conn->real_escape_string($hashed_password);

$insert_admin_sql = "INSERT INTO `users` (`tenant_id`, `firstname`, `lastname`, `username`, `password`, `avatar`, `type`, `date_added`)
    VALUES (1, '{$fn_esc}', '{$ln_esc}', '{$un_esc}', '{$pw_esc}', 'uploads/avatar-1.png', 1, NOW())";

if (!$conn->query($insert_admin_sql)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to create Super Administrator account: ' . $conn->error
    ]);
    $conn->close();
    exit;
}

$conn->close();

// 8. Create or update .env file
$env_file = __DIR__ . '/../.env';
$env_content = "APP_NAME=\"" . addslashes($app_name) . "\"\n" .
               "APP_URL=\"" . addslashes($app_url) . "\"\n" .
               "DB_SERVER=\"" . addslashes($db_server) . "\"\n" .
               "DB_USERNAME=\"" . addslashes($db_username) . "\"\n" .
               "DB_PASSWORD=\"" . addslashes($db_password) . "\"\n" .
               "DB_NAME=\"" . addslashes($db_name) . "\"\n" .
               "SESSION_DRIVER=file\n" .
               "REDIS_HOST=127.0.0.1\n" .
               "REDIS_PORT=6379\n" .
               "REDIS_PREFIX=nuviserpxssid:\n";

@file_put_contents($env_file, $env_content);

// 9. Lock installer
@file_put_contents($lock_file, date('Y-m-d H:i:s') . " - Installed successfully.\n");

echo json_encode([
    'status' => 'success',
    'message' => 'NuvisERPX has been successfully installed!',
    'redirect' => $app_url . 'admin/login.php'
]);
