<?php
// Bulk CSV import: parsing, header matching, row validation and wiring.
$root = dirname(__DIR__);
require_once "$root/src/Services/AccountService.php";
require_once "$root/src/Services/ImportService.php";
use App\Services\ImportService as I;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

// ---- CSV parsing
check('plain csv', I::parseCsv("a,b\n1,2\n") === [['a', 'b'], ['1', '2']]);
check('BOM and CRLF', I::parseCsv("\xEF\xBB\xBFa,b\r\n1,2\r\n") === [['a', 'b'], ['1', '2']]);
check('semicolon separator', I::parseCsv("a;b\n1;2") === [['a', 'b'], ['1', '2']]);
check('tab separator', I::parseCsv("a\tb\n1\t2") === [['a', 'b'], ['1', '2']]);
check('quoted comma and quote', I::parseCsv("a,b\n\"x, y\",\"say \"\"hi\"\"\"") === [['a', 'b'], ['x, y', 'say "hi"']]);
check('quoted newline stays in the cell', I::parseCsv("a,b\n\"l1\nl2\",z")[1][0] === "l1\nl2");
check('blank lines are skipped', count(I::parseCsv("a,b\n\n,\n1,2\n\n")) === 2);
check('cells are trimmed', I::parseCsv("a , b\n 1 , 2 ") === [['a', 'b'], ['1', '2']]);
check('Windows-1252 text is converted', I::parseCsv("name\ncaf\xE9")[1][0] === "caf\u{e9}");

// ---- headers
$m = I::mapHeaders(['Customer Name', 'E-mail', 'Phone Number', 'Code', 'Credit Limit', 'Nonsense'], 'parties');
check('aliases are matched', $m['map'] === [0 => 'name', 1 => 'email', 2 => 'phone', 3 => 'party_code', 4 => 'credit_limit'] && $m['unknown'] === ['Nonsense'] && $m['missing'] === []);
check('missing required column is reported', I::mapHeaders(['email'], 'parties')['missing'] === ['name']);
check('products need code and name', I::mapHeaders(['SKU', 'Price'], 'products')['missing'] === ['name']);
check('sku and price map to product fields', array_values(I::mapHeaders(['SKU', 'Item', 'Price', 'Qty'], 'products')['map']) === ['product_code', 'name', 'selling_price', 'stock_quantity']);
check('each field is used once', count(I::mapHeaders(['name', 'Name', 'company'], 'parties')['map']) === 1);
check('headers ignore case and punctuation', array_values(I::mapHeaders(['PARTY_CODE', 'N.A.M.E'], 'parties')['map']) === ['party_code', 'name']);

// ---- numbers, status, types
check('numbers with symbols', I::number('$1,250.50') === 1250.5 && I::number(' 12 ') === 12.0 && I::number('-3.5') === -3.5);
check('bad numbers are null', I::number('abc') === null && I::number('') === null && I::number('1.2.3') === null);
check('status words', I::status('Active') === 1 && I::status('no') === 0 && I::status('') === null && I::status('maybe') === false);
check('account type is canonical', I::accountType('current ASSET') === 'Current Asset' && I::accountType('Bank') === 'Bank' && I::accountType('nonsense') === null);

// ---- party rows
[$c, $e] = I::cleanRow('parties', ['name' => 'Acme', 'type' => 'Supplier', 'email' => 'a@b.co', 'credit_limit' => '$5,000', 'payment_terms_days' => '30', 'status' => 'active']);
check('good party row', !$e && $c['type'] === 'vendor' && $c['credit_limit'] === 5000.0 && $c['payment_terms_days'] === 30.0 && $c['status'] === 1);
check('party needs a name', I::cleanRow('parties', ['name' => ''])[1] !== []);
check('bad type is reported', I::cleanRow('parties', ['name' => 'x', 'type' => 'alien'])[1] !== []);
check('bad email is reported', I::cleanRow('parties', ['name' => 'x', 'email' => 'nope'])[1] !== []);
check('negative credit limit is reported', I::cleanRow('parties', ['name' => 'x', 'credit_limit' => '-5'])[1] !== []);
check('terms must be whole days and at most 365', I::cleanRow('parties', ['name' => 'x', 'payment_terms_days' => '12.5'])[1] !== [] && I::cleanRow('parties', ['name' => 'x', 'payment_terms_days' => '400'])[1] !== []);
check('party code rejects odd characters', I::cleanRow('parties', ['name' => 'x', 'party_code' => "A B'; DROP"])[1] !== []);
check('long name is reported', I::cleanRow('parties', ['name' => str_repeat('x', 251)])[1] !== []);
check('blank cells are left out so existing values stay', array_keys(I::cleanRow('parties', ['name' => 'x', 'email' => '', 'phone' => ''])[0]) === ['name']);

// ---- product rows
[$c, $e] = I::cleanRow('products', ['product_code' => 'SKU1', 'name' => 'Paper', 'cost_price' => '6.5', 'selling_price' => '9.9', 'stock_quantity' => '10']);
check('good product row', !$e && $c['cost_price'] === 6.5 && $c['stock_quantity'] === 10.0);
check('product needs code and name', count(I::cleanRow('products', [])[1]) === 2);
check('negative price is reported', I::cleanRow('products', ['product_code' => 'a', 'name' => 'b', 'selling_price' => '-1'])[1] !== []);
check('non-numeric stock is reported', I::cleanRow('products', ['product_code' => 'a', 'name' => 'b', 'stock_quantity' => 'lots'])[1] !== []);

// ---- account rows
[$c, $e] = I::cleanRow('accounts', ['code' => '6100', 'name' => 'Office supplies', 'type' => 'expense', 'currency' => 'fjd', 'tax_rate' => '15']);
check('good account row', !$e && $c['type'] === 'Expense' && $c['currency'] === 'FJD' && $c['tax_rate'] === 15.0);
check('unknown account type is reported', I::cleanRow('accounts', ['name' => 'x', 'type' => 'Magic'])[1] !== []);
check('tax rate above 100 is reported', I::cleanRow('accounts', ['name' => 'x', 'tax_rate' => '150'])[1] !== []);
check('account code rejects spaces', I::cleanRow('accounts', ['name' => 'x', 'code' => '61 00'])[1] !== []);

// ---- templates are valid against their own rules
foreach (array_keys(I::ENTITIES) as $k) {
    [$head, $sample] = I::template($k);
    check("$k template has a value for every heading", count($head) === count($sample));
    $m = I::mapHeaders($head, $k);
    check("$k template headings are all recognised", !$m['unknown'] && !$m['missing'] && count($m['map']) === count($head));
    $row = [];
    foreach ($m['map'] as $i => $f) { $row[$f] = $sample[$i]; }
    check("$k template sample row passes validation", I::cleanRow($k, $row)[1] === []);
}

// ---- wiring
$master = file_get_contents("$root/classes/Master.php");
$perm = file_get_contents("$root/src/Support/Permissions.php");
$svc = file_get_contents("$root/src/Services/ImportService.php");
foreach (['import_parties', 'import_products', 'import_accounts'] as $f) {
    check("Master has $f", strpos($master, "function $f(") !== false && strpos($master, "case '$f':") !== false);
    check("Permissions maps $f", strpos($perm, "'$f'") !== false);
}
check('upload is limited by extension and size', strpos($master, "['csv', 'txt']") !== false && strpos($master, 'ImportService::MAX_BYTES') !== false);
check('rows are saved through the normal services', strpos($svc, 'saveParty(') !== false && strpos($svc, 'saveProduct(') !== false && strpos($svc, 'saveAccount(') !== false);
check('updates never change stock', strpos($svc, "array_diff_key(\$clean, ['stock_quantity' => 1])") !== false);
check('import is audited', strpos($svc, "log_audit('Imported '") !== false);
check('nav link and page exist', strpos(file_get_contents("$root/admin/inc/navigation.php"), 'settings/data_import') !== false && is_file("$root/admin/settings/data_import.php"));
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
