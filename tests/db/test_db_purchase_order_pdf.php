<?php
// Purchase order PDF: built in memory for the signed-in company only, with the company logo in the header.
require __DIR__ . '/_harness.php';
class SystemSettings {
    public static int $tenant = 1;
    public static string $logo = 'dist/img/no-image-available.png';  // a real image already in the app
    public function active_tenant_id() { return self::$tenant; }
    public function userdata($k = '') { return $k === 'id' ? 1 : null; }
    public function base_currency($t = null) { return 'FJD'; }
    public function tenant_branding() { return ['name' => 'Acme Ltd', 'email' => 'a@acme.test', 'phone' => '123', 'address' => "1 Main St\nSuva", 'logo' => self::$logo]; }
}
use App\Services\PurchaseService;
[$c, $db] = nv_fresh_db();
$c->query("INSERT INTO tenants (id, tenant_code, name) VALUES (2, 'T2', 'Second Co')");
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (1,1,'V1','Fiji Supplies','v@example.com','vendor'),(2,2,'V2','Other Vendor','o@example.com','vendor')");
$c->query("INSERT INTO purchase_order_list (id,tenant_id,po_no,vendor_id,order_date,status,subtotal,tax_amount,discount,total,notes) VALUES (1,1,'PO/0001',1,'2026-11-16','confirmed',100,15,5,110,'Deliver to dock 2'),(2,2,'PO-T2',2,'2026-11-16','confirmed',9,0,0,9,'')");
$c->query("INSERT INTO purchase_order_items (purchase_order_id,item_name,description,qty,unit_price,tax_rate,tax_amount,amount) VALUES (1,'Copper Pipe','15mm',10,10,15,15,115),(2,'Hidden',  '',1,9,0,0,9)");
$svc = new PurchaseService($c, new SystemSettings());

$r = $svc->pdf(1);
check('a purchase order builds as a PDF', is_array($r) && strncmp($r['data'], '%PDF-', 5) === 0);
check('the file name is safe', $r['name'] === 'PurchaseOrder-PO-0001.pdf');
check('the logo is embedded in the header', strpos($r['data'], '/Subtype /Image') !== false);
check("another company's purchase order is refused", $svc->pdf(2) === null);
check('unknown purchase order is refused', $svc->pdf(99) === null);
SystemSettings::$tenant = 2;
check("the other company can build only its own", $svc->pdf(1) === null && is_array($svc->pdf(2)));
SystemSettings::$tenant = 1;
SystemSettings::$logo = '';
$nologo = $svc->pdf(1);
check('without a logo it still builds, with no image', is_array($nologo) && strpos($nologo['data'], '/Subtype /Image') === false);
SystemSettings::$logo = '../../etc/passwd';
check('a logo path outside the app is not embedded', strpos((string)($svc->pdf(1)['data'] ?? ''), '/Subtype /Image') === false);
$root = __DIR__ . '/../../admin/purchases';
check('the endpoint needs sign-in and purchases view', strpos(file_get_contents("$root/po_pdf.php"), "purchases_view") !== false && strpos(file_get_contents("$root/po_pdf.php"), '401') !== false);
check('the view page and the list link to the download', strpos(file_get_contents("$root/view.php"), 'po_pdf.php?id=') !== false && strpos(file_get_contents("$root/index.php"), 'po_pdf.php?id=') !== false);
nv_done();
