<?php
// Items that are not stock (services such as fumigation): saved as such, never given stock, never in stock checks,
// alerts, reports or adjustments. Stock items behave exactly as before.
require __DIR__ . '/_harness.php';
class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return $k === 'id' ? 1 : null; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
    public function currency_tag($t = null) { return ' (USD)'; }
}
use App\Services\{InventoryService, InventoryAccountingService, WarehouseStockService};
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
$s = new SystemSettings();
$inv = new InventoryService($c, $s); $acc = new InventoryAccountingService($c, $s);
$j = fn($x) => json_decode($x, true);

$svc = $j($inv->saveProduct(['name' => 'Fumigation test', 'is_tracked' => '0', 'is_sold' => '1', 'is_purchased' => '0', 'selling_price' => '165', 'stock_quantity' => '25']));
$stk = $j($inv->saveProduct(['name' => 'Cement', 'is_tracked' => '1', 'selling_price' => '26', 'stock_quantity' => '10', 'cost_price' => '18']));
$old = $j($inv->saveProduct(['name' => 'Imported item', 'selling_price' => '5']));   // a form that sends no flags (CSV import, older forms)
$code = fn($id) => $c->query("SELECT product_code FROM product_list WHERE id=$id")->fetch_row()[0];
$row = fn($id) => $c->query("SELECT is_tracked,is_sold,is_purchased,stock_quantity FROM product_list WHERE id=$id")->fetch_assoc();
check('a service is saved as not tracked, sold, not purchased', ($r = $row($svc['id'])) && (int)$r['is_tracked'] === 0 && (int)$r['is_sold'] === 1 && (int)$r['is_purchased'] === 0);
check('a service never gets opening stock, even if a quantity is sent', (float)$row($svc['id'])['stock_quantity'] === 0.0 && (int)$c->query("SELECT COUNT(*) FROM inventory_logs WHERE product_id={$svc['id']}")->fetch_row()[0] === 0);
check('a stock item keeps its opening stock', (int)$row($stk['id'])['is_tracked'] === 1 && (float)$row($stk['id'])['stock_quantity'] === 10.0);
check('a form that sends no flags makes a stock item as before', (int)$row($old['id'])['is_tracked'] === 1 && (int)$row($old['id'])['is_sold'] === 1 && (int)$row($old['id'])['is_purchased'] === 1);
$j($inv->saveProduct(['id' => $old['id'], 'name' => 'Imported item', 'product_code' => $code($old['id']), 'selling_price' => '6']));
check('editing without flags leaves them alone', (int)$row($old['id'])['is_tracked'] === 1);

check('untrackedIds finds only the service', $acc->untrackedIds([$svc['id'], $stk['id'], $old['id']]) === [(int)$svc['id']]);
check('stockOnly drops the service from a sale', array_keys($acc->stockOnly([$svc['id'] => 3.0, $stk['id'] => 2.0])) === [(int)$stk['id']]);
check('a service never causes "not enough stock"', $acc->shortageMessage([$svc['id'] => 5.0]) === null);
check('a stock item still does', $acc->shortageMessage([$stk['id'] => 50.0]) !== null);
check('issuing a service moves nothing', ($acc->issueUnits([$svc['id'] => 5.0], 'T', 0, 'test')['ok'] ?? false) && (float)$row($svc['id'])['stock_quantity'] === 0.0);
$wh = new WarehouseStockService($c, $s);
check('stock movements skip a service', $wh->adjustLocation((int)$svc['id'], 0, 5.0) === true && (float)$row($svc['id'])['stock_quantity'] === 0.0 && (int)$c->query("SELECT COUNT(*) FROM product_stock WHERE product_id={$svc['id']}")->fetch_row()[0] === 0);
check('movements still work for stock', $wh->adjustLocation((int)$stk['id'], 0, 5.0) === true && (float)$row($stk['id'])['stock_quantity'] === 15.0);
$adj = $j($inv->saveStockAdjustment(['product_id' => $svc['id'], 'type' => 'in', 'qty' => '4']));
check('a service cannot be stock-adjusted', ($adj['status'] ?? '') === 'failed' && (float)$row($svc['id'])['stock_quantity'] === 0.0);

$bad = $j($inv->saveProduct(['id' => $stk['id'], 'name' => 'Cement', 'product_code' => $code($stk['id']), 'is_tracked' => '0']));
check('stock on hand blocks turning an item into a service', ($bad['status'] ?? '') === 'failed' && (int)$row($stk['id'])['is_tracked'] === 1);
$j($inv->saveProduct(['id' => $svc['id'], 'name' => 'Fumigation test', 'product_code' => $code($svc['id']), 'is_tracked' => '1']));
check('a service can become a stock item', (int)$row($svc['id'])['is_tracked'] === 1);
nv_done();
