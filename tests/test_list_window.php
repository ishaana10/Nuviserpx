<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Support\ListWindow;
$f = 0; function check($n, $c) { global $f; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $f++; }

check('default cap is 2000 and shows as LIMIT 2000', ListWindow::cap([]) === 2000 && ListWindow::limitSql([]) === ' LIMIT 2000');
check('?all=1 removes the cap', ListWindow::cap(['all' => '1']) === null && ListWindow::limitSql(['all' => '1']) === '');
check('?all=0 / empty keeps the cap', ListWindow::cap(['all' => '0']) === 2000 && ListWindow::cap(['all' => '']) === 2000);
check('the limit can never carry user input', ListWindow::limitSql(['all' => '0; DROP TABLE x', 'limit' => '1']) === ' LIMIT 2000' || ListWindow::limitSql(['all' => '0; DROP TABLE x']) === '');
check('no banner when everything is shown', ListWindow::notice(150, 150, './?page=x&all=1') === '');
$n = ListWindow::notice(2000, 20000, './?page=invoices&all=1');
check('banner says how many are shown and links to all', strpos($n, '2,000 of 20,000') !== false && strpos($n, 'page=invoices&amp;all=1') !== false);
check('banner escapes the link', strpos(ListWindow::notice(1, 2, '"><script>'), '<script>') === false);
try { ListWindow::total(new class extends mysqli { public function __construct() {} }, 'users; DROP', 1); check('only known tables can be counted', false); } catch (InvalidArgumentException $e) { check('only known tables can be counted', true); }
foreach (['admin/invoices/index.php', 'admin/sales_orders/index.php', 'admin/payments/index.php'] as $p) {
    $s = file_get_contents(__DIR__ . '/../' . $p);
    check("$p applies the cap and shows the banner", strpos($s, 'ListWindow::limitSql()') !== false && strpos($s, 'ListWindow::banner(') !== false);
}
exit($f ? 1 : 0);
