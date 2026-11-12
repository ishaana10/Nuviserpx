<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\InventoryAccountingService as I;
use App\Services\CreditNoteService as C;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

// --- journal lines
$l = I::returnLines(60.0, 3, 9, 'x');
check('return debits Inventory and credits Cost of Sales', count($l) === 2 && $l[0]['account_id'] === 3 && $l[0]['debit'] === 60.0 && $l[1]['account_id'] === 9 && $l[1]['credit'] === 60.0);
$iss = I::issueLines(60.0, 3, 9, 'x');
check('it is the exact mirror of the issue', $l[0]['debit'] === $iss[1]['credit'] && $l[1]['credit'] === $iss[0]['debit']);
check('nothing is posted for zero value or missing accounts', I::returnLines(0.0, 3, 9, 'x') === [] && I::returnLines(5.0, 0, 9, 'x') === [] && I::returnLines(5.0, 3, 0, 'x') === []);

// --- how much may come back
$sold = [1 => ['qty' => 10.0, 'cost' => 4.0, 'name' => 'Widget'], 2 => ['qty' => 2.0, 'cost' => 9.0, 'name' => 'Gadget']];
check('a partial return is fine', I::returnProblems([1 => 4.0], $sold, []) === []);
check('returning everything sold is fine', I::returnProblems([1 => 10.0, 2 => 2.0], $sold, []) === []);
check('more than sold is refused', count(I::returnProblems([1 => 10.5], $sold, [])) === 1);
check('earlier returns count against the room', count(I::returnProblems([1 => 5.0], $sold, [1 => 6.0])) === 1 && I::returnProblems([1 => 4.0], $sold, [1 => 6.0]) === []);
check('a product that was not on the invoice is refused', count(I::returnProblems([7 => 1.0], $sold, [])) === 1);
check('zero quantities are ignored', I::returnProblems([1 => 0.0], $sold, []) === []);
check('the message names the product', str_contains(I::returnProblems([1 => 11.0], $sold, [])[0], 'Widget'));

// --- note lines carry the return
$r = C::lineTotals([['description' => 'Returned widgets', 'qty' => '3', 'price' => '10', 'tax_rate' => '15', 'product_id' => '1', 'return_qty' => '3']]);
check('a line keeps product and return quantity', $r['lines'][0]['product_id'] === 1 && $r['lines'][0]['return_qty'] === 3.0);
$r = C::lineTotals([['description' => 'Price error', 'qty' => '1', 'price' => '10', 'tax_rate' => '0']]);
check('a money-only line returns nothing to stock', $r['lines'][0]['return_qty'] === 0.0 && $r['lines'][0]['product_id'] === 0);
check('a return quantity without a product is refused', isset(C::lineTotals([['description' => 'X', 'qty' => '1', 'price' => '5', 'tax_rate' => '0', 'return_qty' => '1']])['error']));
check('a product with no quantity is just a money line', !isset(C::lineTotals([['description' => 'X', 'qty' => '1', 'price' => '5', 'tax_rate' => '0', 'product_id' => '4', 'return_qty' => '']])['error']));
check('a non-numeric return quantity is refused', isset(C::lineTotals([['description' => 'X', 'qty' => '1', 'price' => '5', 'tax_rate' => '0', 'product_id' => '4', 'return_qty' => 'abc']])['error']));

// --- wiring
$cn = file_get_contents(base_app . 'src/Services/CreditNoteService.php');
check('issue restocks before recording the note', strpos($cn, 'returnToStock(') !== false && strpos($cn, 'returnToStock(') < strpos($cn, 'INSERT INTO credit_notes'));
check('issue undoes the stock if the note fails', substr_count($cn, '$undoStock()') >= 2);
check('only credit notes restock', str_contains($cn, "\$kind === self::CREDIT ? (\$post['line_product']"));
check('void checks stock is still there then takes it back', str_contains($cn, 'shortageMessage($need)') && str_contains($cn, 'takeBack('));
check('lines store product, quantity and cost', str_contains($cn, 'product_id, return_qty, unit_cost'));
$ia = file_get_contents(base_app . 'src/Services/InventoryAccountingService.php');
check('returns come back at the cost they left at', str_contains($ia, "'cost' => \$cur['qty'] > 0") || str_contains($ia, '$cur[\'cost\'] = $cur[\'qty\'] > 0'));
check('already-returned counts only issued notes', str_contains($ia, "n.status = 'issued'") && str_contains($ia, "n.note_type = 'credit'"));
$db = file_get_contents(base_app . 'src/Database/LegacySchemaCatchup.php');
check('migration chained and creates the columns', str_contains($db, '$this->check_sales_return_migrations();') && str_contains($db, 'stock_journal_id') && str_contains($db, "'return_qty'"));
check('manual edits of the stock journal are blocked', str_contains(file_get_contents(base_app . 'src/Services/JournalService.php'), "'credit_note_stock'"));
check('sql migration exists', is_file(base_app . 'database/migrations/012_sales_returns_restock.sql'));
$ui = file_get_contents(base_app . 'admin/credit_notes/index.php');
check('the modal posts product and return quantity', str_contains($ui, 'line_product[]') && str_contains($ui, 'line_return[]'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
