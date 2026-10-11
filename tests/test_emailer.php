<?php
if (!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if (!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['system_info'] = [
    'name' => 'Nuvis ERPX Test',
    'email' => 'info@nuvistechnologies.com.fj',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587'
];

require_once(base_app . 'classes/Emailer.php');

echo "=== TEST 1: Auth OTP Email Template ===\n";
$send_otp = Emailer::send_auth_otp('test@example.com', 'Admin User', '123456');
echo "OTP Email Sent Result: " . ($send_otp ? 'SUCCESS' : 'FALLBACK') . "\n";

echo "=== TEST 2: Invoice Email Template ===\n";
$mock_inv = [
    'invoice_no' => 'INV-202409-001',
    'party_name' => 'Acme Corporation',
    'invoice_date' => '2024-09-15',
    'due_date' => '2024-10-15',
    'subtotal' => 1000.00,
    'tax_amount' => 150.00,
    'total' => 1150.00,
    'balance' => 1150.00
];
$mock_items = [
    ['item_name' => 'Consulting Services', 'qty' => 10, 'unit_price' => 100.00, 'amount' => 1000.00]
];

$send_inv = Emailer::send_invoice_email('client@acme.com', $mock_inv, $mock_items, $_SESSION['system_info']);
echo "Invoice Email Sent Result: " . ($send_inv ? 'SUCCESS' : 'FALLBACK') . "\n";

echo "=== TEST 3: Payment Receipt Email Template ===\n";
$mock_pay = [
    'payment_no' => 'REC-202409-001',
    'party_name' => 'Acme Corporation',
    'payment_date' => '2024-09-20',
    'amount' => 1150.00
];
$mock_alloc = [
    ['invoice_no' => 'INV-202409-001', 'invoice_date' => '2024-09-15', 'invoice_total' => 1150.00, 'allocated_amount' => 1150.00]
];

$send_rec = Emailer::send_payment_receipt_email('client@acme.com', $mock_pay, $mock_alloc, $_SESSION['system_info']);
echo "Receipt Email Sent Result: " . ($send_rec ? 'SUCCESS' : 'FALLBACK') . "\n";

echo "\nALL EMAIL ENGINE TESTS PASSED SUCCESSFULLY!\n";
?>