<?php
// Invoice PDF: renders in memory, wiring to service/email/endpoint/UI.
$root = dirname(__DIR__);
require_once "$root/vendor/autoload.php";
use App\Support\InvoicePdf as P;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$items = [];
for ($i = 1; $i <= 70; $i++) {
    $items[] = ['item_name' => "Café item $i – Müller", 'description' => $i % 5 ? '' : str_repeat('long description ', 12), 'qty' => 2.5, 'unit_price' => 10.0, 'tax_rate' => 12.5, 'amount' => 25.0];
}
$inv = ['id' => 1, 'invoice_no' => 'INV-0001', 'invoice_date' => '2026-11-01', 'due_date' => '2026-11-30', 'subtotal' => 1750.0, 'discount' => 50.0, 'tax_amount' => 210.0, 'total' => 1910.0, 'paid_amount' => 500.0, 'balance' => 1410.0, 'status' => 'partial', 'notes' => 'Thanks'];
$cust = ['name' => "Müller & Söhne", 'party_code' => 'C-1', 'address' => "1 Main St\nLautoka", 'tin' => '123', 'email' => 'a@b.com'];
$brand = ['name' => 'Acme Fiji', 'address' => '1 Road', 'email' => 'i@acme.fj', 'phone' => '1', 'logo' => 'nope.png'];
$pdf = P::render($inv, $items, $cust, $brand, ['label_color' => '#123456', 'footer_notes' => 'Bank: XYZ'], 'FJD ');
check('real PDF string', substr($pdf, 0, 5) === '%PDF-' && strpos(substr($pdf, -16), '%%EOF') !== false);
check('70 items span several pages', preg_match_all('~/Type /Page\b~', $pdf) >= 2);
$min = P::render(['invoice_no' => 'X', 'invoice_date' => '2026-01-01', 'due_date' => null, 'subtotal' => 0, 'tax_amount' => 0, 'total' => 0, 'balance' => 0, 'status' => 'paid'], [], ['name' => 'N'], [], [], '');
check('empty items, no brand, paid renders', substr($min, 0, 5) === '%PDF-');
check('filename safe', P::filename('../a b/INV#1') === 'Invoice-a-b-INV-1.pdf' && P::filename('') === 'Invoice-document.pdf');

$svc = file_get_contents("$root/src/Services/InvoiceService.php");
check('service pdf() is tenant-scoped', (bool)preg_match('~function pdf\(.*?i\.tenant_id = \?~s', $svc));
check('email attaches the PDF', strpos($svc, 'send_invoice_email($email_to, $invoice, $items, $sys_info, $this->pdf($id))') !== false);
$em = file_get_contents("$root/classes/Emailer.php");
check('invoice email accepts pdf', strpos($em, 'send_invoice_email($to, $invoice, $items, $sys_info = [], $pdf = null)') !== false && strpos($em, 'application/pdf') !== false);
$ep = file_get_contents("$root/admin/invoices/invoice_pdf.php");
check('endpoint guarded, no-store', strpos($ep, "userdata('id')") !== false && strpos($ep, "can_access('invoices_view')") !== false && strpos($ep, 'no-store') !== false);
check('view + list link to the PDF', strpos(file_get_contents("$root/admin/invoices/view.php"), 'invoice_pdf.php?id=') !== false && strpos(file_get_contents("$root/admin/invoices/index.php"), 'invoice_pdf.php?id=') !== false);
exit($fail ? 1 : 0);
