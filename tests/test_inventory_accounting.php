<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\InventoryAccountingService as I;
use App\Services\TaxService as T;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }
function balanced(array $lines): bool { $d = $c = 0.0; foreach ($lines as $l) { $d += $l['debit']; $c += $l['credit']; } return abs($d - $c) < 0.005 && $d > 0; }
function balancedDoc(array $lines): bool { $d = $c = 0.0; foreach ($lines as $l) { if ($l[1] === T::DEBIT) $d += $l[2]; else $c += $l[2]; } return abs($d - $c) < 0.005 && $d > 0; }

// --- costs
check('line value rounds to the cent', I::lineValue(3, 3.3333) === 10.0);
check('average cost: new stock blends in', I::weightedAverage(10, 5.0, 10, 7.0) === 6.0);
check('average cost: nothing on hand takes the new cost', I::weightedAverage(0, 5.0, 4, 7.0) === 7.0);
check('average cost: negative on hand takes the new cost', I::weightedAverage(-3, 5.0, 4, 7.0) === 7.0);
check('average cost: adding nothing keeps the cost', I::weightedAverage(10, 5.0, 0, 9.0) === 5.0);

// --- quantities
$need = I::aggregate([['product_id' => 3, 'qty' => 2], ['product_id' => 3, 'qty' => '1.5'], ['product_id' => 0, 'qty' => 9], ['product_id' => 4, 'qty' => 0], ['product_id' => 2, 'qty' => 1]]);
check('same product on two lines is added up', ($need[3] ?? null) === 3.5);
check('lines without product or quantity are ignored', array_keys($need) === [2, 3]);
check('enough stock gives no shortage', I::shortages([3 => 3.5], [3 => 3.5]) === []);
$short = I::shortages([3 => 5.0, 2 => 1.0], [3 => 2.0, 2 => 1.0], [3 => 'Widget']);
check('shortage names the product and quantities', count($short) === 1 && str_contains($short[0], 'Widget') && str_contains($short[0], 'need 5') && str_contains($short[0], 'only 2'));
check('unknown product counts as nothing in stock', count(I::shortages([9 => 1.0], [])) === 1);

// --- journals
$sale = I::issueLines(120.0, 10, 50, 'x');
check('sale: Dr cost of sales, Cr inventory', $sale[0]['account_id'] === 50 && $sale[0]['debit'] === 120.0 && $sale[1]['account_id'] === 10 && $sale[1]['credit'] === 120.0 && balanced($sale));
check('sale at no cost posts nothing', I::issueLines(0.0, 10, 50, 'x') === []);
check('sale without accounts posts nothing', I::issueLines(5.0, 0, 50, 'x') === [] && I::issueLines(5.0, 10, 0, 'x') === []);
$gain = I::valueLines(40.0, 10, 60, 'x');
check('gain: Dr inventory, Cr adjustments', $gain[0]['account_id'] === 10 && $gain[0]['debit'] === 40.0 && $gain[1]['account_id'] === 60 && balanced($gain));
$loss = I::valueLines(-40.0, 10, 60, 'x');
check('loss: Dr adjustments, Cr inventory', $loss[0]['account_id'] === 60 && $loss[0]['debit'] === 40.0 && $loss[1]['account_id'] === 10 && $loss[1]['credit'] === 40.0 && balanced($loss));
check('no value change posts nothing', I::valueLines(0.0, 10, 60, 'x') === []);

// --- purchases
check('stock net counts only product lines', I::stockNet([['product_id' => 1, 'qty' => 2, 'unit_price' => 30], ['product_id' => 0, 'qty' => 1, 'unit_price' => 40], ['product_id' => 5, 'qty' => 1, 'unit_price' => 0]]) === 60.0);
$bill = T::postingLines('purchase', 115.0, 15.0, 20, 30, 40); // Dr 20 net 100, Dr 30 VAT 15, Cr 40 gross 115
$split = I::splitPurchaseLines($bill, 20, 10, 60.0);
check('part stock: inventory 60, purchases 40, VAT and payables unchanged', $split[0] === [10, T::DEBIT, 60.0] && $split[1] === [20, T::DEBIT, 40.0] && $split[2] === [30, T::DEBIT, 15.0] && $split[3] === [40, T::CREDIT, 115.0] && balancedDoc($split));
$all = I::splitPurchaseLines($bill, 20, 10, 100.0);
check('all stock: no purchases line left', $all[0] === [10, T::DEBIT, 100.0] && count($all) === 3 && balancedDoc($all));
$cap = I::splitPurchaseLines($bill, 20, 10, 150.0);
check('stock cannot exceed the purchase debit (discounts)', $cap[0] === [10, T::DEBIT, 100.0] && balancedDoc($cap));
check('no stock lines leaves the bill alone', I::splitPurchaseLines($bill, 20, 10, 0.0) === $bill);
check('no inventory account leaves the bill alone', I::splitPurchaseLines($bill, 20, 0, 60.0) === $bill);
check('inventory same as purchases account leaves the bill alone', I::splitPurchaseLines($bill, 20, 20, 60.0) === $bill);

// --- setup
$acc = [1 => ['name' => 'Inventory', 'type' => 'Inventory'], 2 => ['name' => 'Cost of Sales', 'type' => 'Direct Costs'], 3 => ['name' => 'Stock Adjustments', 'type' => 'Expense'],
    4 => ['name' => 'Opening Balance Equity', 'type' => 'Equity'], 5 => ['name' => 'Sales Revenue', 'type' => 'Revenue'], 6 => ['name' => 'Bank', 'type' => 'Bank']];
$good = ['inventory_account_id' => 1, 'cogs_account_id' => 2, 'adjustment_account_id' => 3, 'offset_account_id' => 4];
check('valid setup has no problems', I::validateSetup($good, $acc) === []);
check('missing accounts are reported', count(I::validateSetup([], $acc)) === 4);
check('revenue is not accepted as cost of sales', count(I::validateSetup(['cogs_account_id' => 5] + $good, $acc)) === 1);
check('expense is not accepted as the inventory account', count(I::validateSetup(['inventory_account_id' => 2] + $good, $acc)) >= 1);
check('equity is required for opening stock', count(I::validateSetup(['offset_account_id' => 3] + $good, $acc)) === 1);
check('an account from another company is refused', count(I::validateSetup(['inventory_account_id' => 99] + $good, $acc)) === 1);
check('inventory account cannot be reused for another role', count(I::validateSetup(['adjustment_account_id' => 1] + $good, $acc)) >= 1);

// --- wiring (the pieces that cannot run without a database)
$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('invoice save issues stock and checks availability first', str_contains($inv, 'checkAvailable($need, $returning)') && str_contains($inv, '->issue($need,') && strpos($inv, 'checkAvailable') < strpos($inv, 'INSERT INTO `journal_entries`'));
check('invoice lines keep the product and the cost', str_contains($inv, '`product_id` = " . ($i_pid > 0') && str_contains($inv, '`unit_cost` = "'));
check('invoice delete takes the stock back before deleting', strpos($inv, '->restore($held[\'items\']') < strpos($inv, 'DELETE FROM `invoice_list`'));
$master = (file_get_contents(base_app . 'classes/Master.php') . file_get_contents(base_app . 'src/Services/SalesOrderService.php'));
check('sales order confirmation uses the service and guards the period', str_contains($master, 'InventoryAccountingService::class') && str_contains($master, 'PeriodLockService::guard($this->conn, (int)$tenant_id, (string)$order[\'order_date\'])'));
check('invoiced orders cannot be deleted out from under their cost of sales', str_contains($master, 'This order has been invoiced and its stock issued'));
check('settings action is permissioned', str_contains(file_get_contents(base_app . 'src/Support/Permissions.php'), "'save_inventory_accounting' => ['settings_manage']"));
check('cost-of-sales journals are protected from manual editing', str_contains(file_get_contents(base_app . 'src/Services/JournalService.php'), "'cogs_journal_id'"));
check('migration file exists', is_file(base_app . 'database/migrations/010_inventory_accounting.sql'));

echo $fail ? "\n$fail FAILED\n" : "\nAll inventory accounting tests passed\n";
exit($fail ? 1 : 0);
