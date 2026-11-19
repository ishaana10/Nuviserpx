<?php
// Platform billing against a real database: tenant bills become ordinary invoices and receipts in the owner company's books.
require __DIR__ . '/_harness.php';

class SystemSettings {
    public static int $type = 1;
    public static int $tenant = 2;
    public function active_tenant_id() { return (int)($_SESSION['userdata']['active_tenant_id'] ?? self::$tenant); }   // the developer is "in" tenant 2
    public function userdata($k = '') { return $k === 'id' ? 1 : ($k === 'type' ? self::$type : null); }
    public function is_super_admin() { return self::$type === 1; }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
    public function base_currency($t = null) { return 'FJD'; }
    public function currency_tag() { return 'FJD'; }
    public function tenant_branding() { return ['name' => 'Owner Co', 'email' => '', 'phone' => '', 'address' => '', 'logo' => '']; }
}
$_SESSION['userdata'] = ['id' => 1, 'type' => 1];
use App\Services\PlatformBillingService;

[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1, 'Test', 'User', 'tester', 'x')");
$c->query("INSERT INTO tenants (id, tenant_code, name, email, address) VALUES (2, 'T2', 'Second Co', 't2@x.fj', '1 Main St')");
$c->query("DELETE FROM account_list WHERE tenant_id = 1");   // the bare test chart has no real account types
\App\Services\ChartProvisioner::provision($c, 1);
$svc = new PlatformBillingService($c, new SystemSettings());
$res = fn($j) => json_decode($j, true);
$post = ['tenant_id' => 2, 'product_code' => 'medcarex', 'issue_date' => '2026-11-19', 'tax_rate' => '12.5', 'description' => ['Subscription', 'Setup', ''], 'qty' => ['2', '1', '1'], 'unit_price' => ['100', '50', '9']];

check('tenant 1 is the platform owner after migration', $svc->ownerId() === 1);
$r = $res($svc->createInvoice($post));
check('an invoice is created', ($r['status'] ?? '') === 'success');
$inv = $c->query("SELECT * FROM invoice_list WHERE tenant_id = 1")->fetch_assoc();
check('it is a real sales invoice in the OWNER company, tagged with its product', $inv && (float)$inv['total'] === 281.25 && $inv['product_code'] === 'medcarex' && $inv['status'] === 'unpaid');
check('it has a journal (receivable and sales posted in the owner books)', (int)$inv['journal_id'] > 0 && (int)$c->query("SELECT COUNT(*) FROM journal_items WHERE journal_id = {$inv['journal_id']}")->fetch_row()[0] >= 2);
check('nothing was posted in the customer tenant', (int)$c->query("SELECT COUNT(*) FROM invoice_list WHERE tenant_id = 2")->fetch_row()[0] === 0);
check('the customer tenant became a linked customer of the owner', (int)$c->query("SELECT COUNT(*) FROM party_list WHERE tenant_id = 1 AND linked_tenant_id = 2 AND name = 'Second Co'")->fetch_row()[0] === 1);
check('the developer session is back on its own tenant afterwards', !isset($_SESSION['userdata']['active_tenant_id']));
$r2 = $res($svc->createInvoice($post));
check('a second invoice reuses the customer and takes the next number', ($r2['status'] ?? '') === 'success' && (int)$c->query("SELECT COUNT(*) FROM party_list WHERE tenant_id = 1 AND linked_tenant_id = 2")->fetch_row()[0] === 1);
check('billing the owner itself is refused', ($res($svc->createInvoice(['tenant_id' => 1] + $post))['status'] ?? '') === 'failed');
check('no lines is refused', ($res($svc->createInvoice(['description' => [''], 'qty' => [1], 'unit_price' => [1]] + $post))['status'] ?? '') === 'failed');

$rows = $svc->rows(2);
check('the tenant reads its invoices live from the owner books', count($rows) === 2 && (float)$rows[0]['total'] === 281.25);
$bank = (int)$c->query("SELECT id FROM account_list WHERE tenant_id = 1 AND delete_flag = 0 AND (type = 'Bank' OR name LIKE '%bank%' OR name LIKE '%cash%') LIMIT 1")->fetch_row()[0];
$lid = (int)$rows[1]['id'];  // older of the two
$p = $res($svc->recordPayment(['invoice_id' => $lid, 'amount' => '100', 'bank_account_id' => $bank, 'pay_date' => '2026-11-20', 'method' => 'Cash']));
check('a part payment is a receipt in the owner books and the invoice shows partial', ($p['status'] ?? '') === 'success' && $svc->rows(2)[1]['status'] === 'partial' && (float)$svc->rows(2)[1]['paid_amount'] === 100.0);
check('the receipt is listed for the tenant', count($svc->receipts((int)$rows[1]['owner_invoice_id'])) === 1);
check('over-paying is refused', ($res($svc->recordPayment(['invoice_id' => $lid, 'amount' => '500', 'bank_account_id' => $bank]))['status'] ?? '') === 'failed');
check('a payment needs a bank account', ($res($svc->recordPayment(['invoice_id' => $lid, 'amount' => '10']))['status'] ?? '') === 'failed');
check('a partly paid invoice cannot be voided', ($res($svc->voidInvoice($lid))['status'] ?? '') === 'failed');
$p = $res($svc->recordPayment(['invoice_id' => $lid, 'amount' => '181.25', 'bank_account_id' => $bank]));
check('paying the rest makes it paid', ($p['status'] ?? '') === 'success' && $svc->rows(2)[1]['status'] === 'paid');

$lid2 = (int)$rows[0]['id'];
check('an unpaid invoice can be voided (cancelled in the owner books)', ($res($svc->voidInvoice($lid2))['status'] ?? '') === 'success' && $svc->rows(2)[0]['status'] === 'void');
check('a void invoice takes no payment', ($res($svc->recordPayment(['invoice_id' => $lid2, 'amount' => '10', 'bank_account_id' => $bank]))['status'] ?? '') === 'failed');
$rev = $svc->revenueByProduct(1);
check('revenue by product counts live invoices only', count($rev) === 1 && $rev[0]['p'] === 'medcarex' && (float)$rev[0]['s'] === 250.0);
check('overdue shows for unpaid past due only', PlatformBillingService::isOverdue(['status' => 'unpaid', 'due_date' => '2000-01-01'])
    && !PlatformBillingService::isOverdue(['status' => 'paid', 'due_date' => '2000-01-01']));

$pdf = $svc->invoicePdf($lid);
check('the developer gets the invoice as the same PDF Sales prints', $pdf && strpos($pdf['data'], '%PDF') === 0 && strpos($pdf['name'], '.pdf') !== false);
$rc = $svc->receipts((int)$rows[1]['owner_invoice_id']);
$rpdf = $svc->receiptPdf($lid, (int)$rc[0]['payment_id']);
check('a receipt PDF is built for a payment on the invoice', $rpdf && strpos($rpdf['data'], '%PDF') === 0 && strpos($rpdf['name'], 'Receipt-') === 0);
check('a receipt for a payment that is not on this invoice is refused', $svc->receiptPdf($lid, 999999) === null);
SystemSettings::$type = 2;
$tp = $svc->invoicePdf($lid);
check('the customer tenant can get its own invoice and receipt PDFs', $tp && strpos($tp['data'], '%PDF') === 0 && $svc->receiptPdf($lid, (int)$rc[0]['payment_id']) !== null);
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (3, 'T3', 'Third Co')");
SystemSettings::$tenant = 3;
check('another tenant cannot get it', $svc->invoicePdf($lid) === null && $svc->receiptPdf($lid, (int)$rc[0]['payment_id']) === null);
SystemSettings::$tenant = 2;
check('a non-developer cannot create, pay or void', ($res($svc->createInvoice($post))['status'] ?? '') === 'failed'
    && ($res($svc->recordPayment(['invoice_id' => $lid, 'amount' => '1', 'bank_account_id' => $bank]))['status'] ?? '') === 'failed' && ($res($svc->voidInvoice($lid))['status'] ?? '') === 'failed');
nv_done();
