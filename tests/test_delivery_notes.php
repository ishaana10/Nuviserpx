<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\DeliveryService as D;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

check('remaining never goes below zero', D::remaining(10.0, 4.0) === 6.0 && D::remaining(10.0, 12.0) === 0.0);

$lines = [1 => ['ordered' => 10.0, 'delivered' => 4.0, 'name' => 'Widget'], 2 => ['ordered' => 5.0, 'delivered' => 0.0, 'name' => 'Gadget']];
check('a partial delivery is fine', D::deliveryProblems([1 => 3.0, 2 => 0.0], $lines) === []);
check('delivering exactly what is left is fine', D::deliveryProblems([1 => 6.0, 2 => 5.0], $lines) === []);
$p = D::deliveryProblems([1 => 6.5], $lines);
check('more than is left is refused and names the item', count($p) === 1 && str_contains($p[0], 'Widget') && str_contains($p[0], '6'));
check('an empty delivery is refused', count(D::deliveryProblems([1 => 0.0, 2 => 0.0], $lines)) === 1);
check('a line not on the order is refused', count(D::deliveryProblems([9 => 1.0], $lines)) === 1);
check('negative quantities are refused', count(D::deliveryProblems([1 => -1.0], $lines)) >= 1);
check('rounding noise is tolerated', D::deliveryProblems([1 => 6.00004], $lines) === []);

check('nothing delivered', D::fulfilment([['ordered' => 5, 'delivered' => 0], ['ordered' => 2, 'delivered' => 0]]) === 'not_delivered');
check('part delivered', D::fulfilment([['ordered' => 5, 'delivered' => 5], ['ordered' => 2, 'delivered' => 1]]) === 'partially_delivered');
check('all delivered', D::fulfilment([['ordered' => 5, 'delivered' => 5], ['ordered' => 2, 'delivered' => 2]]) === 'delivered');
check('over-delivery does not hide a short line', D::fulfilment([['ordered' => 5, 'delivered' => 9], ['ordered' => 2, 'delivered' => 0]]) === 'partially_delivered');

check('invoiceable is delivered less invoiced', D::invoiceable(6.0, 2.5) === 3.5 && D::invoiceable(2.0, 3.0) === 0.0);

check('discount is shared by value invoiced', D::invoiceDiscount(100.0, 1000.0, 250.0, 0.0, false) === 25.0);
check('the completing invoice takes the exact remainder', D::invoiceDiscount(100.0, 1000.0, 750.0, 25.0, true) === 75.0);
$a = D::invoiceDiscount(10.0, 30.0, 10.0, 0.0, false); $b = D::invoiceDiscount(10.0, 30.0, 10.0, $a, false); $c = D::invoiceDiscount(10.0, 30.0, 10.0, $a + $b, true);
check('three invoices of a third add up exactly to the discount', abs(($a + $b + $c) - 10.0) < 0.001);
check('no order discount means none', D::invoiceDiscount(0.0, 100.0, 50.0, 0.0, false) === 0.0);

check('an invoiced delivery cannot be cancelled', D::cancelProblem('delivered', 1) !== null);
check('an uninvoiced delivery can', D::cancelProblem('delivered', 0) === null);
check('an already cancelled one cannot', D::cancelProblem('cancelled', 0) !== null);

// --- wiring
$svc = file_get_contents(base_app . 'src/Services/DeliveryService.php');
check('delivery claims quantity with a guarded update', str_contains($svc, 'delivered_qty + ? <= qty + 0.00005') && str_contains($svc, 'affected_rows !== 1'));
check('stock is issued before the rows and undone on failure', strpos($svc, '$stock->issue(') < strpos($svc, 'INSERT INTO delivery_notes') && str_contains($svc, '$undo()'));
check('units-only companies still move stock', str_contains($svc, 'issueUnits('));
check('cancelling returns stock via restore', str_contains($svc, '->restore($back'));
check('invoicing marks deliveries and flags no second stock issue', str_contains($svc, "'stock_by_delivery' => 1") && str_contains($svc, 'SET x.invoice_id = ?'));
$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('the invoice does not issue stock for delivered goods', str_contains($inv, "!empty(\$post['stock_by_delivery'])"));
check('cancel and delete release the deliveries', substr_count($inv, 'releaseInvoice($id)') === 2);
check('an invoice raised from deliveries cannot be edited', str_contains($inv, 'linkedToInvoice((int)$id)'));
$m = file_get_contents(base_app . 'classes/Master.php');
check('immediate confirm refuses delivery orders', str_contains($m, "!empty(\$order['delivery_mode'])"));
check('orders with deliveries cannot be edited or deleted', substr_count($m, 'hasDeliveries(') === 2);
$p = file_get_contents(base_app . 'src/Support/Permissions.php');
check('actions are permissioned', str_contains($p, "'save_delivery_note'") && str_contains($p, "'invoice_delivered'     => ['invoices', 'save']"));
$ia = file_get_contents(base_app . 'src/Services/InventoryAccountingService.php');
check('returns can be taken for goods invoiced from deliveries', str_contains($ia, 'delivery_note_items x'));
$db = file_get_contents(base_app . 'classes/DBConnection.php');
check('migration chained', str_contains($db, '$this->check_delivery_migrations();') && str_contains($db, 'delivery_note_items') && str_contains($db, 'delivered_qty'));
check('sql migration exists', is_file(base_app . 'database/migrations/016_delivery_notes.sql'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
