<?php
/**
 * A customer's own invoice as a PDF, built in memory and sent straight to the browser (nothing is stored).
 * ?id=N   (add &download=1 to save instead of view)
 * Only invoices the portal itself shows (issued, not draft/cancelled) and only the signed-in customer's own.
 */
require_once('../config.php');

$portalCtx = \App\Support\CustomerPortal::context($conn);
if (!$portalCtx) {
    \App\Support\CustomerPortal::clearSession();
    http_response_code(401);
    die('Sign in to the customer portal to download this invoice.');
}

$pdf = (new \App\Services\InvoiceService($conn, $_settings))
    ->pdfForPortal((int)($_GET['id'] ?? 0), (int)$portalCtx['party_id'], (int)$portalCtx['tenant_id']);
if ($pdf === null) {
    http_response_code(404);
    die('Invoice not found.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $pdf['name'] . '"');
header('Content-Length: ' . strlen($pdf['data']));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $pdf['data'];
exit;
