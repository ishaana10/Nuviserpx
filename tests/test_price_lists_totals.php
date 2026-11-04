<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\PriceListService as P;
use App\Services\InvoiceService as I;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

// --- quantity breaks
$tiers = [['min_qty' => 1.0, 'price' => 10.0], ['min_qty' => 10.0, 'price' => 9.0], ['min_qty' => 100.0, 'price' => 7.5]];
check('below the first break uses the first tier', P::tierPrice($tiers, 1.0) === 10.0 && P::tierPrice($tiers, 9.0) === 10.0);
check('reaching a break takes its price', P::tierPrice($tiers, 10.0) === 9.0 && P::tierPrice($tiers, 99.0) === 9.0);
check('the highest break wins', P::tierPrice($tiers, 100.0) === 7.5 && P::tierPrice($tiers, 5000.0) === 7.5);
check('quantity under every minimum has no list price', P::tierPrice([['min_qty' => 5.0, 'price' => 3.0]], 2.0) === null);
check('no tiers means no list price', P::tierPrice([], 3.0) === null);
check('tiers need not be in order', P::tierPrice(array_reverse($tiers), 50.0) === 9.0);

// --- price entries
check('a good entry is cleaned', P::cleanEntry('', '12.5') === ['min_qty' => 1.0, 'price' => 12.5]);
check('a zero minimum is refused', isset(P::cleanEntry('0', '5')['error']));
check('a negative price is refused', isset(P::cleanEntry('1', '-5')['error']));
check('a non-numeric price is refused', isset(P::cleanEntry('1', 'abc')['error']));
check('a free item (zero price) is allowed', P::cleanEntry('1', '0')['price'] === 0.0);

// --- invoice totals match the form
$rows = [['qty' => 2, 'price' => 100, 'discount_pct' => 10, 'tax_rate' => 15], ['qty' => 1, 'price' => 50, 'discount_pct' => 0, 'tax_rate' => 0]];
$t = I::computeTotals($rows, 'exclusive', 0.0);
check('line discount comes off before tax', $t['subtotal'] === 230.0 && $t['tax'] === 27.0 && $t['total'] === 257.0);
$t = I::computeTotals($rows, 'exclusive', 7.0);
check('the overall discount comes off the total', $t['total'] === 250.0 && $t['subtotal'] === 230.0);
$t = I::computeTotals([['qty' => 1, 'price' => 115, 'discount_pct' => 0, 'tax_rate' => 15]], 'inclusive', 0.0);
check('inclusive tax is inside the price', $t['subtotal'] === 115.0 && $t['tax'] === 15.0 && $t['total'] === 115.0);
$t = I::computeTotals([['qty' => 1, 'price' => 115, 'discount_pct' => 0, 'tax_rate' => 15]], 'none', 0.0);
check('no-tax mode ignores the rate', $t['tax'] === 0.0 && $t['total'] === 115.0);
check('a discount over 100% is refused', isset(I::computeTotals([['qty' => 1, 'price' => 5, 'discount_pct' => 120, 'tax_rate' => 0]], 'exclusive', 0.0)['error']));
check('negative quantity or price is refused', isset(I::computeTotals([['qty' => -1, 'price' => 5, 'discount_pct' => 0, 'tax_rate' => 0]], 'exclusive', 0.0)['error']));
check('a discount bigger than the invoice is refused', isset(I::computeTotals([['qty' => 1, 'price' => 5, 'discount_pct' => 0, 'tax_rate' => 0]], 'exclusive', 50.0)['error']));
$l = I::lineAmounts(3, 10, 0, 15, 'exclusive');
check('a plain line is qty x price plus tax', abs($l['amount'] - 34.5) < 1e-9 && abs($l['tax'] - 4.5) < 1e-9);

// --- wiring
$inv = file_get_contents(base_app . 'src/Services/InvoiceService.php');
check('saving checks the posted totals against the lines', str_contains($inv, 'computeTotals($rows') && str_contains($inv, 'do not match its lines'));
check('the line discount is stored', str_contains($inv, '`discount_pct` ='));
$db = file_get_contents(base_app . 'classes/DBConnection.php');
check('pricing migration chained', str_contains($db, '$this->check_pricing_migrations();') && str_contains($db, 'price_list_items') && str_contains($db, 'discount_pct'));
$perm = file_get_contents(base_app . 'src/Support/Permissions.php');
check('managing price lists needs settings_manage', str_contains($perm, "'save_price_list' => ['settings_manage']") && str_contains($perm, "\$page === 'price_lists'"));
check('Master exposes the actions', substr_count(file_get_contents(base_app . 'classes/Master.php'), "case 'lookup_prices'") === 1);
check('the invoice form looks up list prices', str_contains(file_get_contents(base_app . 'admin/invoices/manage_invoice.php'), 'lookup_prices'));
check('sql migration exists', is_file(base_app . 'database/migrations/013_price_lists_line_discount.sql'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
