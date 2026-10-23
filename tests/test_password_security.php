<?php
if (!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if (!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

echo "=== TEST 1: Password Verification Behavior (BCRYPT vs MD5) ===\n";

$plain_pass = 'SecretPassword123!';
$bcrypt_hash = password_hash($plain_pass, PASSWORD_BCRYPT);
$md5_hash = md5($plain_pass);

// Verify BCRYPT works with password_verify
assert(password_verify($plain_pass, $bcrypt_hash) === true, "BCRYPT hash verification failed");
assert(password_verify('WrongPassword', $bcrypt_hash) === false, "BCRYPT invalid password passed");

// Verify password_verify rejects plain MD5 hash string
assert(password_verify($plain_pass, $md5_hash) === false, "MD5 hash should not pass password_verify");

echo "=== TEST 2: Static Code Analysis - Ensure No MD5 Password Verification ===\n";

$login_code = file_get_contents(base_app . 'classes/Login.php');
$users_code = file_get_contents(base_app . 'classes/Users.php');

// Check that Login.php does not perform md5($password) comparison
assert(strpos($login_code, 'md5($password)') === false, "Login.php still contains md5(\$password)");
assert(strpos($login_code, 'md5($password) === $db_pass') === false, "Login.php still contains md5 fallback");

// Check that Users.php does not perform md5($oldpassword) comparison
assert(strpos($users_code, 'md5($oldpassword)') === false, "Users.php still contains md5(\$oldpassword)");

// Ensure password_verify is used
assert(strpos($login_code, 'password_verify($password, $db_pass)') !== false, "Login.php missing password_verify for users");
assert(strpos($login_code, 'password_verify($password, $res[\'password\'])') !== false, "Login.php missing password_verify for clients");

echo "\nALL PASSWORD SECURITY TESTS PASSED SUCCESSFULLY!\n";
?>
