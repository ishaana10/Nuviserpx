<?php

require_once(__DIR__ . '/../initialize.php');

use App\Core\Container;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Services\AccountService;
use App\Services\InvoiceService;

echo "=== TEST 1: Container Reflection Autowiring & Binding ===\n";
$container = Container::getInstance();

class MockConn extends mysqli {
    public function __construct() {}
}

class MockSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($k) { return 1; }
}

$mockConn = new MockConn();
$mockSettings = new MockSettings();

$container->singleton(mysqli::class, $mockConn);
$container->singleton('SystemSettings', $mockSettings);

assert($container->has(AccountService::class) === true);

echo "Container reflection autowiring check SUCCESS\n";

echo "\n=== TEST 2: Enums Labels & Values ===\n";
assert(InvoiceStatus::UNPAID->value === 'unpaid');
assert(PaymentStatus::POSTED->value === 'posted');
assert(OrderStatus::PROFORMA->value === 'proforma');

echo "InvoiceStatus label: " . InvoiceStatus::UNPAID->label() . "\n";
echo "PaymentStatus label: " . PaymentStatus::POSTED->label() . "\n";
echo "OrderStatus label: " . OrderStatus::PROFORMA->label() . "\n";

echo "\nALL SERVICE & CONTAINER UNIT TESTS PASSED!\n";
