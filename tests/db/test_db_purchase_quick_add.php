<?php
// Purchase forms: quick-add vendor / item (auto codes, ids returned, per-company) and tenant-safe line items.
require __DIR__ . '/_harness.php';
class SystemSettings {
    public static int $tenant = 1;
    public function active_tenant_id() { return self::$tenant; }
    public function userdata($k = '') { return $k === 'id' ? 1 : null; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
    public function currency_tag($t = null) { return ' (USD)'; }
}
use App\Services\{PartyService, InventoryService, PurchaseService};
[$c, $db] = nv_fresh_db();
$c->query("DELETE FROM account_list WHERE tenant_id = 1"); \App\Services\ChartProvisioner::provision($c, 1);   // a real chart: bills post to its payable and expense accounts
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Second Co')");
$s = new SystemSettings();
$party = new PartyService($c, $s); $inv = new InventoryService($c, $s); $pur = new PurchaseService($c, $s);
$j = fn($x) => json_decode($x, true);

// --- quick-add vendor
$a = $j($party->saveParty(['name' => 'Fiji Supplies', 'type' => 'vendor', 'phone' => '123']));
check('quick vendor saves and returns its id, code, name and type', ($a['status'] ?? '') === 'success' && $a['id'] > 0 && $a['name'] === 'Fiji Supplies' && $a['type'] === 'vendor' && strpos($a['party_code'], 'VEN-') === 0);
$b = $j($party->saveParty(['name' => 'Pacific Traders', 'type' => 'vendor']));
check('a second quick vendor gets the next code', $b['party_code'] !== $a['party_code'] && $b['id'] !== $a['id']);
check('the vendor belongs to the signed-in company', (int)$c->query("SELECT tenant_id FROM party_list WHERE id = {$a['id']}")->fetch_row()[0] === 1);
check('a typed code is kept', ($j($party->saveParty(['name' => 'X', 'type' => 'vendor', 'party_code' => 'MINE-1']))['party_code'] ?? '') === 'MINE-1');
check('a duplicate typed code is refused', ($j($party->saveParty(['name' => 'Y', 'type' => 'vendor', 'party_code' => 'MINE-1']))['status'] ?? '') === 'failed');
SystemSettings::$tenant = 2;
$o = $j($party->saveParty(['name' => 'Other Co Vendor', 'type' => 'vendor']));
check('another company numbers its own vendors from the start', ($o['party_code'] ?? '') === 'VEN-0001' && (int)$c->query("SELECT tenant_id FROM party_list WHERE id = {$o['id']}")->fetch_row()[0] === 2);
$p2 = $j($inv->saveProduct(['name' => 'T2 Widget', 'cost_price' => 5]));
SystemSettings::$tenant = 1;

// --- quick-add item
$i = $j($inv->saveProduct(['name' => 'Copper Pipe', 'cost_price' => '12.5', 'selling_price' => '20', 'unit' => 'm']));
check('quick item saves and returns its id, code and name', ($i['status'] ?? '') === 'success' && $i['id'] > 0 && $i['name'] === 'Copper Pipe' && strpos($i['product_code'], 'ITEM-') === 0);
$row = $c->query("SELECT tenant_id, cost_price, status, stock_quantity FROM product_list WHERE id = {$i['id']}")->fetch_assoc();
check('the item is in this company, active, with its unit cost and no stock', (int)$row['tenant_id'] === 1 && (float)$row['cost_price'] === 12.5 && (int)$row['status'] === 1 && (float)$row['stock_quantity'] === 0.0);

// --- purchase order lines
$po = fn(array $lines) => $j($pur->savePurchaseOrder([
    'vendor_id' => $a['id'], 'order_date' => '2026-11-16', 'status' => 'draft', 'subtotal' => 10, 'total' => 10,
    'product_id' => array_column($lines, 0), 'item_name' => array_column($lines, 1), 'item_qty' => array_fill(0, count($lines), 1),
    'item_price' => array_fill(0, count($lines), 10), 'item_tax_rate' => array_fill(0, count($lines), 0), 'item_desc' => array_fill(0, count($lines), ''),
]));
$r = $po([[(string)$i['id'], 'Copper Pipe'], ['custom:Delivery fee', 'Delivery fee'], [(string)$p2['id'], 'Sneaky other-company item'], ['', 'Blank']]);
check('the purchase order saves', ($r['status'] ?? '') === 'success');
$lines = $c->query("SELECT item_name, product_id FROM purchase_order_items WHERE purchase_order_id = {$r['id']} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
check('a catalogue item keeps its product', (int)$lines[0]['product_id'] === (int)$i['id']);
check('a typed one-off item is stored without a product', $lines[1]['item_name'] === 'Delivery fee' && $lines[1]['product_id'] === null);
check("another company's product id is dropped, never linked", $lines[2]['product_id'] === null);
check('a blank selection is stored without a product', $lines[3]['product_id'] === null);

SystemSettings::$tenant = 2;
$v2 = $j($pur->savePurchaseOrder(['vendor_id' => $a['id'], 'order_date' => '2026-11-16', 'item_name' => ['x'], 'item_qty' => [1], 'item_price' => [1]]));
check("another company cannot use this company's vendor", ($v2['status'] ?? '') === 'failed');
SystemSettings::$tenant = 1;

// --- vendor bill lines and PO link
$bill = fn(array $over) => $j($pur->saveVendorBill($over + [
    'vendor_id' => $a['id'], 'bill_date' => '2026-11-16', 'due_date' => '2026-12-16', 'status' => 'draft', 'subtotal' => 10, 'total' => 10,
    'product_id' => [(string)$i['id'], 'custom:Freight', (string)$p2['id']], 'item_name' => ['Copper Pipe', 'Freight', 'Other'],
    'item_qty' => [1, 1, 1], 'item_price' => [3, 3, 4], 'item_tax_rate' => [0, 0, 0], 'item_desc' => ['', '', ''],
]));
$bl = $bill([]);
check('the bill saves', ($bl['status'] ?? '') === 'success');
$bid = (int)$c->query("SELECT id FROM vendor_bill_list ORDER BY id DESC LIMIT 1")->fetch_row()[0];
$bi = $c->query("SELECT product_id FROM vendor_bill_items WHERE vendor_bill_id = $bid ORDER BY id")->fetch_all(MYSQLI_ASSOC);
check('bill lines: catalogue kept, one-off and other-company products dropped', (int)$bi[0]['product_id'] === (int)$i['id'] && $bi[1]['product_id'] === null && $bi[2]['product_id'] === null);
// A bill may not link to another company's purchase order.
$c->query("INSERT INTO purchase_order_list (id, tenant_id, po_no, vendor_id, order_date, status) VALUES (900, 2, 'PO-T2', {$o['id']}, CURDATE(), 'confirmed')");
$bill(['purchase_order_id' => 900, 'bill_no' => 'B-LINK']);
check("a bill cannot be linked to another company's purchase order", $c->query("SELECT purchase_order_id FROM vendor_bill_list WHERE bill_no = 'B-LINK'")->fetch_row()[0] === null
    && $c->query("SELECT status FROM purchase_order_list WHERE id = 900")->fetch_row()[0] === 'confirmed');

// --- the pages wire the shared combo
$root = __DIR__ . '/../../admin/purchases';
foreach (['manage_po.php', 'manage_bill.php'] as $f) {
    $t = file_get_contents("$root/$f");
    check("$f uses the shared vendor and item combos", strpos($t, '_quick_add.php') !== false && strpos($t, 'NvQuick.vendor') !== false && strpos($t, 'NvQuick.item') !== false && strpos($t, 'nv-item-select') !== false);
    check("$f has no separate item-name box left visible", strpos($t, "placeholder=\"Item Name\" required") === false);
}
check('supplier payments uses the vendor combo', strpos(file_get_contents("$root/bill_payments.php"), 'NvQuick.vendor') !== false);
$q = file_get_contents("$root/_quick_add.php");
check('the add options only appear for users who may add', strpos($q, "can_access('parties_save')") !== false && strpos($q, "can_access('products_save')") !== false);
check('the item list in the combo is this company only', strpos($q, 'tenant_id = {$qa_tenant}') !== false);
nv_done();
