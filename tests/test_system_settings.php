<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any previous session state for clean testing
$_SESSION = [];

// Mock DBConnection if real DB connection is unavailable in CLI testing environment
if (!class_exists('DBConnection')) {
    class DBConnection {
        public $conn;
        public function __construct() {
            $this->conn = new class {
                public function query($sql) {
                    return false;
                }
                public function real_escape_string($str) {
                    return addslashes($str);
                }
            };
        }
        public function auto_install_schema() {}
    }
}

require_once __DIR__ . '/../classes/SystemSettings.php';

echo "=== TEST 1: Userdata Management (set_userdata, userdata, sess_des) ===\n";
$sys = new SystemSettings();

// Test empty parameter
assert($sys->userdata('') === false, "userdata('') should return false");
assert($sys->userdata('non_existent') === null, "userdata('non_existent') should return null");

// Set userdata
$sys->set_userdata('name', 'John Doe');
$sys->set_userdata('type', 1);
assert($sys->userdata('name') === 'John Doe', "userdata('name') should return 'John Doe'");
assert($sys->userdata('type') === 1, "userdata('type') should return 1");

// Session destroy helper
assert($sys->sess_des() === true, "sess_des should return true");
assert($sys->userdata('name') === null, "userdata('name') should be null after sess_des()");

echo "=== TEST 2: System Info Management (set_info, info) ===\n";
assert($sys->info('') === false, "info('') should return false");
assert($sys->info('non_existent') === false, "info('non_existent') should return false");

$sys->set_info('name', 'Nuvis ERPX');
$sys->set_info('smtp_host', 'smtp.example.com');
assert($sys->info('name') === 'Nuvis ERPX', "info('name') should return 'Nuvis ERPX'");
assert($sys->info('smtp_host') === 'smtp.example.com', "info('smtp_host') should return 'smtp.example.com'");

echo "=== TEST 3: Tenant Scoping (active_tenant_id, set_active_tenant) ===\n";
// Default tenant id should be 1
$_SESSION['userdata'] = [];
assert($sys->active_tenant_id() === 1, "Default active_tenant_id should be 1");

// User assigned tenant_id
$sys->set_userdata('tenant_id', 3);
assert($sys->active_tenant_id() === 3, "active_tenant_id should use userdata('tenant_id') when active_tenant_id is not set");

// Override with active_tenant_id
assert($sys->set_active_tenant(5) === true, "set_active_tenant(5) should return true");
assert($sys->active_tenant_id() === 5, "active_tenant_id should return 5");

// Invalid tenant id should be rejected
assert($sys->set_active_tenant(0) === false, "set_active_tenant(0) should return false");
assert($sys->set_active_tenant(-1) === false, "set_active_tenant(-1) should return false");

echo "=== TEST 4: Flashdata Messaging (set_flashdata, chk_flashdata, flashdata) ===\n";
assert($sys->chk_flashdata('success') === false, "chk_flashdata should return false before setting");
assert($sys->flashdata('') === false, "flashdata with empty key should return false");

assert($sys->set_flashdata('success', 'Operation completed successfully.') === true, "set_flashdata should return true");
assert($sys->chk_flashdata('success') === true, "chk_flashdata should return true after setting");

$msg = $sys->flashdata('success');
assert($msg === 'Operation completed successfully.', "flashdata should return stored message");
assert($sys->chk_flashdata('success') === false, "chk_flashdata should return false after flashdata is consumed");

echo "=== TEST 5: Roles and Permissions (get_user_role, can_access) ===\n";
// Super Admin test
$sys->set_userdata('type', 1);
$role = $sys->get_user_role();
assert($role['name'] === 'Super Administrator', "Super Admin role name match");
assert($role['permissions'] === ['*'], "Super Admin permission wildcard match");
assert($sys->can_access('random_permission_key') === true, "Super Admin can access any key");

// Standard Staff test
$sys->set_userdata('type', 2);
$sys->set_userdata('role_id', 0);
$staff_role = $sys->get_user_role();
assert($staff_role['name'] === 'Standard Staff', "Standard Staff role name match");
assert($sys->can_access('invoices_view') === true, "Standard Staff can access invoices_view");
assert($sys->can_access('system_settings_manage') === false, "Standard Staff cannot access system_settings_manage");

echo "=== TEST 6: CSRF Token Generation & Verification (csrf_token, verify_csrf) ===\n";
unset($_SESSION['csrf_token']);
$token = $sys->csrf_token();
assert(!empty($token), "csrf_token should return non-empty token");
assert(strlen($token) === 64, "csrf_token should return 64 hex characters (32 bytes)");
assert($sys->csrf_token() === $token, "csrf_token should return same token when called again");

// GET request verification
$_SERVER['REQUEST_METHOD'] = 'GET';
assert($sys->verify_csrf() === true, "GET request verify_csrf should return true");

// POST request verification with valid token
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf_token'] = $token;
assert($sys->verify_csrf() === true, "POST request verify_csrf with matching token should return true");

// POST request verification with invalid token
$_POST['csrf_token'] = 'invalid_token_value';
assert($sys->verify_csrf() === false, "POST request verify_csrf with invalid token should return false");

echo "=== TEST 7: DB Connection Check (check_connection) ===\n";
assert($sys->check_connection() === $sys->conn, "check_connection should return connection object");

echo "\nALL SYSTEMSETTINGS UNIT TESTS PASSED SUCCESSFULLY!\n";
