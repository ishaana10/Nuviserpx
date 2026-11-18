<?php
// Platform billing against a real database: invoice numbering and totals, payments, status, void rules, super-admin gate.
require __DIR__ . '/_harness.php';

class SystemSettings {
    public static int $type = 1;
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return $k === 'id' ? 1 : ($k === 'type' ? self::$type : null); }
    public function log_audit(...$a) {}
    public function set_flashdata(...$a) {}
}
use App\Services\PlatformBillingService;

[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Second Co')");
$svc = new PlatformBillingService($c, new SystemSettings());
$res = fn($j) => json_decode($j, true);
$post = ['tenant_id' => 2, 'issue_date' => '2026-11-19', 'tax_rate' => '12.5', 'description' => ['ERPX subscription', 'Setup', ''], 'qty' => ['2', '1', '1'], 'unit_price' => ['100', '50', '9']];

$r = $res($svc->createInvoice($post));
check('an invoice is created and numbered', ($r['status'] ?? '') === 'success' && $r['invoice_no'] === 'NUV-' . date('Y') . '-0001');
$inv = $c->query("SELECT * FROM platform_invoices WHERE id = {$r['id']}")->fetch_assoc();
check('totals are lines + tax (250 + 12.5% = 281.25), blank line skipped', (float)$inv['subtotal'] === 250.0 && (float)$inv['total'] === 281.25 && $inv['status'] === 'unpaid'
    && (int)$c->query("SELECT COUNT(*) FROM platform_invoice_items WHERE invoice_id = {$r['id']}")->fetch_row()[0] === 2);
$id = (int)$r['id'];
$r2 = $res($svc->createInvoice($post));
check('numbers keep counting', $r2['invoice_no'] === 'NUV-' . date('Y') . '-0002');
check('an unknown tenant is refused', ($res($svc->createInvoice(['tenant_id' => 99] + $post))['status'] ?? '') === 'failed');
check('an invoice with no lines is refused', ($res($svc->createInvoice(['description' => [''], 'qty' => [1], 'unit_price' => [1]] + $post))['status'] ?? '') === 'failed');

$p = $res($svc->recordPayment(['invoice_id' => $id, 'amount' => '100', 'pay_date' => '2026-11-20', 'method' => 'Cash']));
check('a part payment makes it partial', ($p['invoice_status'] ?? '') === 'partial');
check('over-paying the balance is refused', ($res($svc->recordPayment(['invoice_id' => $id, 'amount' => '200']))['status'] ?? '') === 'failed');
check('a partly paid invoice cannot be voided', ($res($svc->voidInvoice($id))['status'] ?? '') === 'failed');
$p = $res($svc->recordPayment(['invoice_id' => $id, 'amount' => '181.25']));
check('paying the rest makes it paid', ($p['invoice_status'] ?? '') === 'paid'
    && (float)$c->query("SELECT paid_amount FROM platform_invoices WHERE id = $id")->fetch_row()[0] === 281.25);

$id2 = (int)$r2['id'];
check('an unpaid invoice can be voided', ($res($svc->voidInvoice($id2))['status'] ?? '') === 'success');
check('a void invoice takes no payment', ($res($svc->recordPayment(['invoice_id' => $id2, 'amount' => '10']))['status'] ?? '') === 'failed');
check('overdue shows for unpaid past due only', PlatformBillingService::isOverdue(['status' => 'unpaid', 'due_date' => '2000-01-01'])
    && !PlatformBillingService::isOverdue(['status' => 'paid', 'due_date' => '2000-01-01']) && !PlatformBillingService::isOverdue(['status' => 'void', 'due_date' => '2000-01-01']));

SystemSettings::$type = 2;
check('a non-developer cannot create, pay or void', ($res($svc->createInvoice($post))['status'] ?? '') === 'failed'
    && ($res($svc->recordPayment(['invoice_id' => $id, 'amount' => '1']))['status'] ?? '') === 'failed' && ($res($svc->voidInvoice($id))['status'] ?? '') === 'failed');
nv_done();
