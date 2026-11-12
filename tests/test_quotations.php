<?php
define('base_app', dirname(__DIR__) . '/');
spl_autoload_register(function ($c) { $f = base_app . 'src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require_once $f; });
use App\Services\QuotationService as Q;

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) $fail++; }

check('proforma and quote are quotations, sales orders are not', Q::isQuote('proforma') && Q::isQuote('quote') && !Q::isQuote('sales_order'));
check('valid on the last day, expired the day after', !Q::isExpired('2026-11-30', '2026-11-30') && Q::isExpired('2026-11-30', '2026-12-01'));
check('no validity date never expires', !Q::isExpired(null, '2030-01-01') && !Q::isExpired('', '2030-01-01'));
check('default validity is 30 days', Q::defaultValidUntil('2026-11-04') === '2026-12-04');
check('labels show the version from v2', Q::label('Q-1', 1) === 'Q-1' && Q::label('Q-1', 3) === 'Q-1 v3');

$today = '2026-11-04';
check('an open unexpired quote can be accepted', Q::acceptProblem(['status' => 'proforma', 'valid_until' => '2026-11-10'], $today) === null);
check('a quote with no expiry can be accepted', Q::acceptProblem(['status' => 'proforma', 'valid_until' => null], $today) === null);
check('an expired quote cannot, and the message says when', str_contains((string)Q::acceptProblem(['status' => 'proforma', 'valid_until' => '2026-11-03'], $today), '2026-11-03'));
check('a superseded quote points to the newer version', str_contains((string)Q::acceptProblem(['status' => 'superseded', 'valid_until' => null], $today), 'newer version'));
check('declined and cancelled cannot be accepted', Q::acceptProblem(['status' => 'declined'], $today) !== null && Q::acceptProblem(['status' => 'cancelled'], $today) !== null);
check('an already-accepted quote is not treated as expired', Q::acceptProblem(['status' => 'confirmed', 'valid_until' => '2020-01-01'], $today) === null);

check('an open quote can be revised', Q::reviseProblem(['type' => 'quote', 'status' => 'proforma']) === null);
check('an expired or declined quote can be revised (re-offered)', Q::reviseProblem(['type' => 'proforma', 'status' => 'declined']) === null);
check('a superseded, accepted or cancelled quote cannot', Q::reviseProblem(['type' => 'quote', 'status' => 'superseded']) !== null && Q::reviseProblem(['type' => 'quote', 'status' => 'confirmed']) !== null && Q::reviseProblem(['type' => 'quote', 'status' => 'cancelled']) !== null);
check('a sales order has no versions', Q::reviseProblem(['type' => 'sales_order', 'status' => 'proforma']) !== null);
check('only open quotes can be declined or extended', Q::openProblem(['type' => 'quote', 'status' => 'proforma']) === null && Q::openProblem(['type' => 'quote', 'status' => 'declined']) !== null && Q::openProblem(['type' => 'sales_order', 'status' => 'proforma']) !== null);

$svc = file_get_contents(base_app . 'src/Services/QuotationService.php');
check('revise supersedes with a guarded update inside a transaction', str_contains($svc, "begin_transaction") && str_contains($svc, "status NOT IN ('superseded'") && str_contains($svc, 'affected_rows !== 1'));
check('revise copies the lines', str_contains($svc, 'INSERT INTO sales_order_items'));
$m = (file_get_contents(base_app . 'classes/Master.php') . file_get_contents(base_app . 'src/Services/SalesOrderService.php') . file_get_contents(base_app . 'src/Services/ContractService.php'));
check('confirming runs the accept check', str_contains($m, 'QuotationService::acceptProblem($order'));
check('saving a confirmed quote runs the accept check', str_contains($m, "acceptProblem(['status' => 'proforma'"));
check('superseded/declined orders are not edited in place', str_contains($m, "['superseded', 'declined', 'converted_to_invoice']"));
check('portal signing takes the company from the quote, not the request', str_contains($m, '$t_id = (int)$quoteRow[\'tenant_id\']'));
check('portal signing refuses expired/superseded quotes', substr_count($m, 'QuotationService::acceptProblem') >= 3);
$p = file_get_contents(base_app . 'src/Support/Permissions.php');
check('revise, decline, extend need sales order edit', substr_count($p, "['sales_orders', 'edit']") >= 4 && str_contains($p, "'revise_quote'"));
$db = file_get_contents(base_app . 'src/Database/LegacySchemaCatchup.php');
check('migration chained and widens type/status', str_contains($db, '$this->check_quotation_migrations();') && str_contains($db, "'superseded','declined'") && str_contains($db, "'proforma','quote','sales_order'"));
check('sql migration exists', is_file(base_app . 'database/migrations/015_quotation_versions_expiry.sql'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
