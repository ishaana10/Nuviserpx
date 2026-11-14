<?php
// Statement PDF (in memory only), email attachments, and the "last emailed" log.
$root = dirname(__DIR__);
require_once "$root/vendor/autoload.php";
require_once "$root/src/Services/LedgerService.php";
require_once "$root/classes/Emailer.php";
use App\Support\StatementPdf as P;
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$inv = [];
for ($i = 1; $i <= 60; $i++) {
    $inv[] = ['id' => $i, 'invoice_no' => 'INV-' . $i, 'invoice_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total' => 100.0 * $i, 'balance' => 50.0 * $i, 'bucket' => $i % 2 ? 'd30' : 'current', 'days' => 10];
}
$c = ['id' => 1, 'name' => "Müller & Söhne – Fiji Ltd", 'code' => 'C-1', 'email' => 'a@b.com', 'total' => 91500.0, 'invoices' => $inv, 'current' => 45750.0, 'd30' => 45750.0, 'd60' => 0, 'd90' => 0, 'older' => 0];
$brand = ['name' => 'Acme Fiji', 'address' => "1 Main St\nLautoka", 'email' => 'i@acme.fj', 'phone' => '123', 'logo' => 'uploads/does-not-exist.png'];
$pdf = P::render($c, '2026-11-14', $brand, 'FJD ', 'Pay soon');
check('renders a real PDF string', substr($pdf, 0, 5) === '%PDF-' && strpos(substr($pdf, -16), '%%EOF') !== false);
check('60 invoices flow onto more than one page', preg_match_all('~/Type /Page\b~', $pdf) >= 2);
check('empty invoice list and missing logo still render', substr(P::render(['name' => 'X', 'code' => '', 'total' => 0, 'invoices' => []] , '2026-11-14', [], ''), 0, 5) === '%PDF-');
check('filename is safe and dated', P::filename("../../etc/passwd & Co", '2026-11-14') === 'Statement-etc-passwd-Co-2026-11-14.pdf' && P::filename('', '2026-11-14') === 'Statement-customer-2026-11-14.pdf');

// email attachments (in memory)
class EmailerProbe extends Emailer {
    static function clean($a) { return self::clean_attachments($a); }
    static function mime($h, $a) { return self::mime_body($h, $a); }
}
[$ct, $body] = EmailerProbe::mime('<p>hi</p>', []);
check('no attachment: plain HTML as before', $ct === 'Content-Type: text/html; charset=UTF-8' && $body === '<p>hi</p>');
$att = EmailerProbe::clean([['name' => 'a b/../c.pdf', 'mime' => 'application/pdf', 'data' => "%PDF-test"]]);
check('attachment name is sanitised', $att[0]['name'] === 'a b_.._c.pdf');
[$ct, $body] = EmailerProbe::mime('<p>hi</p>', $att);
check('multipart/mixed with a boundary', preg_match('~^Content-Type: multipart/mixed; boundary="(=_nv_[0-9a-f]{24})"$~', $ct, $m) === 1);
check('body has the html part, a base64 pdf part and the closing boundary', strpos($body, 'text/html') !== false && strpos($body, 'Content-Type: application/pdf; name="a b_.._c.pdf"') !== false && strpos($body, base64_encode('%PDF-test')) !== false && substr($body, -strlen($m[1]) - 4) === '--' . $m[1] . '--');
check('bad mime type is refused', EmailerProbe::clean([['name' => 'x', 'mime' => "text/html\r\nBcc: a@b.c", 'data' => 'x']]) === false);
check('empty data is refused', EmailerProbe::clean([['name' => 'x', 'mime' => 'application/pdf', 'data' => '']]) === false);
check('attachments over 5 MB are refused', EmailerProbe::clean([['name' => 'x', 'mime' => 'application/pdf', 'data' => str_repeat('a', 5242881)]]) === false);
check('no attachments cleans to an empty list', EmailerProbe::clean([]) === []);

// wiring
$sv = file_get_contents("$root/src/Services/CustomerStatementService.php");
$pg = file_get_contents("$root/admin/reports/customer_statements.php");
$ep = file_get_contents("$root/admin/reports/customer_statement_pdf.php");
$mg = file_get_contents("$root/database/migrations/043_customer_statement_log.sql");
check('a successful send is logged with the tenant, customer and sender', strpos($sv, 'INSERT INTO customer_statement_log (tenant_id, party_id, as_of, sent_to, total, sent_by)') !== false && strpos($sv, 'if ($ok) {') !== false);
check('log read is tenant-scoped and tolerates the table not existing yet', strpos($sv, 'FROM customer_statement_log WHERE tenant_id = ?') !== false && strpos($sv, '} catch (\\Throwable $e) {') !== false);
check('migration creates the log table with a tenant index', strpos($mg, 'CREATE TABLE IF NOT EXISTS `customer_statement_log`') !== false && strpos($mg, 'KEY `tenant_party` (`tenant_id`,`party_id`,`sent_at`)') !== false);
check('page shows Last emailed and warns about recent sends', strpos($pg, 'Last emailed') !== false && strpos($pg, 'already emailed a statement in the last 7 days') !== false);
check('PDF endpoint needs sign-in and invoice view permission', strpos($ep, "userdata('id')") !== false && strpos($ep, "can_access('invoices_view')") !== false);
check('PDF endpoint streams from memory with no-store and writes nothing', strpos($ep, 'no-store') !== false && strpos($ep, 'echo $pdf[\'data\']') !== false && !preg_match('/file_put_contents|fopen|fwrite|touch\(/', $ep));
check('email falls back to no attachment if the PDF cannot be built', strpos($sv, 'return null;') !== false && strpos($sv, 'cannot be built the email still goes out') !== false);
exit($fail ? 1 : 0);
