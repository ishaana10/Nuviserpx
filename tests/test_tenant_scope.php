<?php
// Tenant isolation ratchet: every query against a core business table should
// carry tenant_id. Queries that fetch by an id already verified against the
// tenant are tolerated at today's count per file (the baseline below), so the
// number can only go DOWN. A new unscoped query fails this test - scope it,
// or consciously lower/adjust the baseline in review.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$tables = ['invoice_list','journal_entries','sales_order_list','payroll_list','account_list','product_list','expense_reports','employee_list','payment_list','party_list','parties'];
$baseline = [
    'api/v1/invoices.php' => 5,
    'api/v1/journals.php' => 2,
    'api/v1/payments.php' => 1,
    'api/v1/payroll.php' => 1,
    'api/v1/products.php' => 1,
    'classes/Master.php' => 1,
    'src/Services/BillPaymentService.php' => 2,
    'src/Services/ContractBillingService.php' => 1,
    'src/Services/ContractService.php' => 1,
    'src/Services/CreditNoteService.php' => 5,
    'src/Services/CurrencyService.php' => 1,
    'src/Services/ExpenseClaimService.php' => 1,
    'src/Services/InvoiceService.php' => 4,
    'src/Services/JournalTrail.php' => 1,
    'src/Services/PaymentReminderService.php' => 1,
    'src/Services/PaymentService.php' => 7,
    'src/Services/PayrollApprovalService.php' => 2,
    'src/Services/PayrollPaymentService.php' => 1,
    'src/Services/PayrollReconciliationService.php' => 1,
    'src/Services/PayrollService.php' => 2,
    'src/Services/PurchaseService.php' => 1,
    'src/Services/SalesOrderService.php' => 1,
];

$files = array_merge(glob($root.'/src/Services/*.php'), glob($root.'/classes/*.php'), glob($root.'/api/v1/*.php'));
$over = [];
foreach ($files as $f) {
    $rel = ltrim(str_replace($root, '', $f), '/');
    $s = file_get_contents($f);
    $n = 0;
    if (preg_match_all('/(?:->query|->prepare)\(\s*"((?:[^"\\\\]|\\\\.)*)"/s', $s, $mm)) {
        foreach ($mm[1] as $sql) {
            if (!preg_match('/^\s*(SELECT|UPDATE|DELETE|INSERT)/i', $sql)) continue;
            foreach ($tables as $t) {
                if (preg_match('/(?:FROM|JOIN|UPDATE|INTO)\s+`?'.$t.'`?\b/i', $sql) && stripos($sql, 'tenant_id') === false) { $n++; break; }
            }
        }
    }
    if ($n > ($baseline[$rel] ?? 0)) { $over[] = "$rel: $n (baseline " . ($baseline[$rel] ?? 0) . ")"; }
}
check('no file gained an unscoped query on a core tenant table', $over === [] || print_r($over, true) === '');

// API surface: the tenant always comes from the key, never from the client
$apiBad = [];
foreach (glob($root.'/api/v1/*.php') as $f) {
    $s = file_get_contents($f);
    if (preg_match("/(?:\\\$_POST|\\\$_GET|\\\$_REQUEST|\\\$input)\\[['\\\"]tenant_id['\\\"]\\]/", $s)) { $apiBad[] = basename($f); }
}
check('api/v1 never reads a client-supplied tenant_id', $apiBad === []);
$pm = file_get_contents($root.'/api/v1/payments.php');
check('API payment allocation verifies the invoice belongs to the key tenant', strpos($pm, "WHERE id = '{\$inv_id}' AND tenant_id = '{\$api_tenant_id}'") !== false);

// Fail closed + audited super-admin movement
$ss = file_get_contents($root.'/classes/SystemSettings.php');
check('a signed-in user with no home tenant is denied, not dropped into tenant 1', strpos($ss, "deny('Your account is not linked to a company") !== false);
check('super-admin company switches are audited', strpos($ss, "log_audit('Switched Company'") !== false);
$lg = file_get_contents($root.'/classes/Login.php');
check('logins are audited with IP; super-tier flagged', strpos($lg, '[SUPER ADMIN]') !== false && strpos($lg, 'REMOTE_ADDR') !== false);

// The public signature endpoint takes its tenant from the quote row only
$cs = file_get_contents($root.'/src/Services/ContractService.php');
check('proposal signatures never read a client tenant_id', strpos($cs, "\$_POST['tenant_id']") === false && strpos($cs, "(int)\$quoteRow['tenant_id']") !== false);

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
