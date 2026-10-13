<?php
require_once(__DIR__ . '/initialize.php');

use App\Services\InventoryService;
use App\Services\InvoiceService;

$_settings = new SystemSettings();
$conn = (new DBConnection())->conn;

echo "--- STARTING INLINE ITEM CREATION VERIFICATION ---\n";

// Instantiating InventoryService
$inventoryService = new InventoryService($conn, $_settings);

// Test payload for creating item via quick create modal
$test_item_code = 'TEST-INLINE-' . rand(1000, 9999);
$post_data = [
    'product_code' => $test_item_code,
    'name' => 'Quick Test Product',
    'selling_price' => '45.00',
    'tax_rate' => '15.00',
    'description' => 'Inline item test description',
    'track_inventory' => '1',
    'unit' => 'pcs',
    'cost_price' => '20.00',
    'stock_quantity' => '50'
];

$res_json = $inventoryService->saveProduct($post_data);
$res = json_decode($res_json, true);

if (isset($res['status']) && $res['status'] === 'success') {
    echo "SUCCESS: Product created via service!\n";
    echo "Product Code: " . $res['product']['product_code'] . "\n";
    echo "Product ID: " . $res['product']['id'] . "\n";
    echo "Selling Price: " . $res['product']['selling_price'] . "\n";
} else {
    echo "FAILED: Product creation failed. Response: " . $res_json . "\n";
    exit(1);
}

// Clean up created product
$conn->query("DELETE FROM `product_list` WHERE product_code = '{$test_item_code}'");
echo "Cleaned up test product.\n";

echo "--- VERIFICATION COMPLETE ---\n";
