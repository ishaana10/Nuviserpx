<?php
// Delivery-note proof of delivery (typed receiver name + drawn signature) and the customer-portal invoice PDF,
// both against a real database built by the installer (so migration 049 is exercised too).
require __DIR__ . '/_harness.php';
define('base_url', 'http://dn.test/');
class SystemSettings {
    public function active_tenant_id() { return 1; }
    public function userdata($k = '') { return 1; }
    public function info($f = '') { return $f === 'name' ? 'Acme Ltd' : ''; }
    public function tenant_branding() { return ['name' => 'Acme Ltd', 'email' => '', 'phone' => '', 'address' => '', 'logo' => '']; }
    public function currency_tag($t = null) { return ' (USD)'; }
}
use App\Services\DeliveryService as D;
use App\Services\InvoiceService as I;
[$c, $db] = nv_fresh_db();
$settings = new SystemSettings();

$c->query("INSERT INTO users (id, firstname, lastname, username, password) VALUES (1,'A','B','u','x')");
$c->query("INSERT INTO party_list (id,tenant_id,party_code,name,email,type) VALUES (1,1,'C1','Alpha Ltd','a@example.com','customer'),(2,1,'C2','Bravo Ltd','b@example.com','customer')");
$c->query("INSERT INTO sales_order_list (id,tenant_id,order_no,party_id,order_date,type,status) VALUES (1,1,'SO-1',1,CURDATE(),'sales','confirmed')");
$c->query("INSERT INTO delivery_notes (id,tenant_id,dn_no,sales_order_id,party_id,delivery_date,status) VALUES (1,1,'DN-1',1,1,CURDATE(),'delivered'),(2,1,'DN-2',1,1,CURDATE(),'cancelled')");

check('migration 049 added the proof-of-delivery columns', $c->query("SHOW COLUMNS FROM delivery_notes LIKE 'receiver_signature'")->num_rows === 1
    && $c->query("SHOW COLUMNS FROM delivery_notes LIKE 'received_by'")->num_rows === 1);

$png = 'data:image/png;base64,' . base64_encode(random_bytes(300));
$svc = new D($c, $settings);
$sign = fn(int $id, string $name, string $sig) => json_decode($svc->signNote($id, $name, $sig, 1), true);

check('name too short is refused', $sign(1, ' a ', $png)['status'] === 'failed');
check('missing signature is refused', $sign(1, 'Jane Receiver', '')['status'] === 'failed');
check('non-PNG signature (markup) is refused', $sign(1, 'Jane Receiver', 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=')['status'] === 'failed');
check('cancelled note cannot be signed', $sign(2, 'Jane Receiver', $png)['status'] === 'failed');
check('unknown note is refused', $sign(99, 'Jane Receiver', $png)['status'] === 'failed');
check('nothing was stored by the refusals', (int)$c->query("SELECT COUNT(*) FROM delivery_notes WHERE received_at IS NOT NULL")->fetch_row()[0] === 0);

$r = $sign(1, "  <b>Jane</b>   Receiver ", $png);
$row = $c->query("SELECT * FROM delivery_notes WHERE id=1")->fetch_assoc();
check('a valid signature is saved', $r['status'] === 'success' && $row['receiver_signature'] === $png && $row['received_at'] !== null && (int)$row['signed_by_user_id'] === 1);
check('typed name is cleaned (markup stripped, spaces collapsed)', $row['received_by'] === 'Jane Receiver');
check('a signed note is locked against a second signature', $sign(1, 'Someone Else', $png)['status'] === 'failed'
    && $c->query("SELECT received_by FROM delivery_notes WHERE id=1")->fetch_row()[0] === 'Jane Receiver');
check('the note is returned with its signature for the printout', ($svc->note(1)['received_by'] ?? '') === 'Jane Receiver');
check('the list query does not carry signature images', !array_key_exists('receiver_signature', $svc->all()[0]) && !array_key_exists('receiver_signature', $svc->notesFor(1)[0]));

// --- signed delivery note PDF + email rules -------------------------------------------------------------------------
$realPng = function () { $im = imagecreatetruecolor(120, 40); imagesavealpha($im, true); imagealphablending($im, false); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true); imageline($im, 5, 5, 110, 35, imagecolorallocate($im, 10, 20, 40)); ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode(ob_get_clean()); };
$c->query("INSERT INTO delivery_note_items (delivery_note_id,item_name,qty) VALUES (1,'Widget',3),(1,'Gadget',2.5)");
$c->query("INSERT INTO delivery_notes (id,tenant_id,dn_no,sales_order_id,party_id,delivery_date,status) VALUES (3,1,'DN-3',1,1,CURDATE(),'delivered')");
check('an unsigned note cannot be emailed', $svc->emailNote(3) !== '' && json_decode($svc->emailNote(3), true)['status'] === 'failed');
check('a cancelled note cannot be emailed', json_decode($svc->emailNote(2), true)['status'] === 'failed');
$c->query("UPDATE party_list SET email='' WHERE id=1");
check('customer with no email on file: asked for an address', json_decode($svc->emailNote(1), true)['status'] === 'failed' && strpos(json_decode($svc->emailNote(1), true)['msg'], 'No valid email') !== false);
check('an invalid typed address is refused', strpos(json_decode($svc->emailNote(1, 'not-an-email'), true)['msg'], 'not valid') !== false);
$c->query("UPDATE party_list SET email='a@example.com' WHERE id=1");
check('nothing is recorded as emailed after refusals', $c->query("SELECT last_emailed_at FROM delivery_notes WHERE id=1")->fetch_row()[0] === null);
check('migration 050 added the emailed columns', $c->query("SHOW COLUMNS FROM delivery_notes LIKE 'last_emailed_to'")->num_rows === 1);
$c->query("UPDATE delivery_notes SET receiver_signature='" . $c->real_escape_string($realPng()) . "' WHERE id=1");
$pdf = $svc->pdf(1);
check('signed delivery note builds a real PDF with the right name', is_array($pdf) && strncmp($pdf['data'], '%PDF-', 5) === 0 && $pdf['name'] === 'DeliveryNote-DN-1.pdf');
check('the PDF carries the signature image (transparent PNG flattened)', is_array($pdf) && strpos($pdf['data'], '/Subtype /Image') !== false);
check('the PDF is longer with a signature than without', strlen($pdf['data']) > strlen($svc->pdf(3)['data']));
check('unknown note gives no PDF', $svc->pdf(99) === null);

// --- customer portal PDF ---------------------------------------------------------------------------------------------
$c->query("INSERT INTO invoice_list (id,tenant_id,invoice_no,party_id,invoice_date,subtotal,total,balance,status) VALUES
  (1,1,'INV-1',1,CURDATE(),100,100,100,'unpaid'),(2,1,'INV-2',1,CURDATE(),50,50,50,'draft'),(3,1,'INV-3',1,CURDATE(),40,40,40,'cancelled'),(4,1,'INV-4',2,CURDATE(),70,70,70,'unpaid')");
$c->query("INSERT INTO invoice_items (invoice_id,item_name,qty,unit_price,amount) VALUES (1,'Widget',1,100,100)");
$inv = new I($c, $settings);
$pdf = $inv->pdfForPortal(1, 1, 1);
check('customer gets a real PDF of their own issued invoice', is_array($pdf) && strncmp($pdf['data'], '%PDF-', 5) === 0 && $pdf['name'] === 'Invoice-INV-1.pdf');
check('a draft invoice is not available to the customer', $inv->pdfForPortal(2, 1, 1) === null);
check('a cancelled invoice is not available to the customer', $inv->pdfForPortal(3, 1, 1) === null);
check("another customer's invoice is refused", $inv->pdfForPortal(4, 1, 1) === null);
check('wrong company is refused', $inv->pdfForPortal(1, 1, 2) === null);
check('unknown invoice is refused', $inv->pdfForPortal(99, 1, 1) === null);
nv_done();
