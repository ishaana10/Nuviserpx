<?php
require_once __DIR__ . '/../config.php';

echo "--- Starting Xero Accounting Features Test Suite ---\n\n";

$tenant_id = $_settings->active_tenant_id();
echo "Active Tenant ID: {$tenant_id}\n";

// 1. Test Fixed Assets creation and listing
echo "[1] Testing Fixed Assets...\n";
$_POST = [
    'asset_number' => 'FA-TEST-99',
    'asset_name' => 'Test Laptop Dell XPS',
    'purchase_date' => '2026-10-20',
    'purchase_price' => '2500.00',
    'asset_type' => 'Computer Equipment',
    'dep_start_date' => '2026-10-20',
    'dep_method' => 'Straight Line',
    'averaging_method' => 'Full Month',
    'dep_calc_by' => 'rate',
    'dep_rate' => '20.00',
    'cost_limit' => '2500.00',
    'residual_value' => '100.00',
    'status' => 'draft'
];

$res_json = $Master->save_asset();
$res = json_decode($res_json, true);

if ($res['status'] === 'success') {
    echo "✔ Asset saved successfully with ID: {$res['id']}\n";
    $asset_id = $res['id'];

    // Verify database record
    $q = $conn->query("SELECT * FROM fixed_assets WHERE id = '{$asset_id}' AND tenant_id = '{$tenant_id}'");
    if ($q && $q->num_rows > 0) {
        $row = $q->fetch_assoc();
        echo "✔ Verified database record for Asset #: {$row['asset_number']} ({$row['asset_name']})\n";
    } else {
        echo "❌ Database record verification failed!\n";
    }

    // Clean up test asset
    $_POST = ['id' => $asset_id];
    $del_json = $Master->delete_asset();
    echo "✔ Cleaned up test asset.\n";
} else {
    echo "❌ Failed to save fixed asset: " . ($res['msg'] ?? '') . "\n";
}

// 2. Test Add Bank Account
echo "\n[2] Testing Bank Account Creation...\n";
$_POST = [
    'bank_name' => 'ANZ Fiji',
    'account_name' => 'Test Operating Account',
    'account_type' => 'Checking',
    'account_number' => '12345678',
    'currency' => 'FJD'
];

$bank_json = $Master->save_bank_account();
$bank_res = json_decode($bank_json, true);

if ($bank_res['status'] === 'success') {
    echo "✔ Bank account added successfully with ID: {$bank_res['id']}\n";
    $bank_id = $bank_res['id'];

    $q_bank = $conn->query("SELECT * FROM account_list WHERE id = '{$bank_id}' AND tenant_id = '{$tenant_id}'");
    if ($q_bank && $q_bank->num_rows > 0) {
        $b_row = $q_bank->fetch_assoc();
        echo "✔ Verified Chart of Accounts entry: {$b_row['name']} | Code: {$b_row['code']} | Bank: {$b_row['bank_name']}\n";
    } else {
        echo "❌ Bank Account DB verification failed!\n";
    }

    // Clean up
    $conn->query("DELETE FROM account_list WHERE id = '{$bank_id}' AND tenant_id = '{$tenant_id}'");
    echo "✔ Cleaned up test bank account.\n";
} else {
    echo "❌ Failed to save bank account: " . ($bank_res['msg'] ?? '') . "\n";
}

// 3. Test PHPUnit suite if available
echo "\n[3] Running PHPUnit checks...\n";
exec("vendor/bin/phpunit 2>&1", $output, $return_var);
echo implode("\n", array_slice($output, -10)) . "\n";

echo "\n--- Test Suite Complete ---\n";
