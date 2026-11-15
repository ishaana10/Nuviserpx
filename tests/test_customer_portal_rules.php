<?php
// Customer portal rules that need no database: what customers may see, token hashing, password rules, and that the
// portal pages actually use them (so nobody re-introduces raw "all invoices for this party" queries).
require __DIR__ . '/../vendor/autoload.php';
use App\Support\CustomerPortal as P;
$fail = 0;
function check($n, $ok) { global $fail; echo ($ok ? 'PASS' : 'FAIL') . " - $n\n"; if (!$ok) { $fail++; } }

check('customers see issued invoices only', P::INVOICE_STATUSES === ['unpaid', 'partially_paid', 'paid']);
check('draft and cancelled are not visible', !in_array('draft', P::INVOICE_STATUSES, true) && !in_array('cancelled', P::INVOICE_STATUSES, true));
check('open statuses carry a balance', P::OPEN_STATUSES === ['unpaid', 'partially_paid']);
check('invoice SQL fragment', P::invoiceStatusSql('i`.`status') === "`i`.`status` IN ('unpaid','partially_paid','paid')");
check('payments: posted only', P::PAYMENT_STATUS === 'posted');
check('token hash is sha256 hex', P::tokenHash('abc') === hash('sha256', 'abc') && strlen(P::tokenHash('x')) === 64);
check('short password rejected', count(P::passwordErrors('abc12')) === 1);
check('letters-only rejected', count(P::passwordErrors('abcdefghij')) > 0);
check('digits-only rejected', count(P::passwordErrors('1234567890')) > 0);
check('good password accepted', P::passwordErrors('Passw0rd1') === []);

$idx = file_get_contents(__DIR__ . '/../portal/index.php');
$inv = file_get_contents(__DIR__ . '/../portal/view_invoice.php');
foreach (['portal/index.php' => $idx, 'portal/view_invoice.php' => $inv] as $name => $src) {
    check("$name checks the session against the database", strpos($src, 'CustomerPortal::context($conn)') !== false);
    check("$name stops after redirecting a signed-out visitor", (bool)preg_match("/redirect\('portal\/login\.php'\);\s*exit;/", $src));
    check("$name filters invoices by visible status", strpos($src, 'invoiceStatusSql') !== false);
    check("$name has no hard-coded dollar sign amounts", !preg_match('/\$<\?php echo number_format/', $src));
}
check('payments list and total use posted payments only', substr_count($idx, "`status` = 'posted'") >= 2);
check('balance comes from invoice balances, not invoiced minus paid', strpos($idx, 'SUM(balance)') !== false);
$svc = file_get_contents(__DIR__ . '/../src/Services/CustomerPortalService.php');
check('invitation link points at the activation page', strpos($svc, 'portal/register.php?token=') !== false && strpos($svc, 'portal/login.php?token=') === false);
check('invitation tokens are stored hashed', strpos($svc, 'tokenHash($token)') !== false);
check('login page forwards old invitation links', strpos(file_get_contents(__DIR__ . '/../portal/login.php'), 'register.php?token=') !== false);
exit($fail ? 1 : 0);
