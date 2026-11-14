<?php
/**
 * Invoice as a PDF, built in memory and sent straight to the browser. Nothing is written to the server.
 * ?id=N   (add &download=1 to save instead of view)
 */
require_once(__DIR__ . '/../../config.php');

if (!$_settings->userdata('id')) {
    http_response_code(401);
    die('Sign in to view this invoice.');
}
if (!$_settings->can_access('invoices_view')) {
    http_response_code(403);
    die('You do not have permission to view invoices.');
}

$pdf = (new \App\Services\InvoiceService($conn, $_settings))->pdf((int)($_GET['id'] ?? 0));
if ($pdf === null) {
    http_response_code(404);
    die('Invoice not found, or the PDF could not be built.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $pdf['name'] . '"');
header('Content-Length: ' . strlen($pdf['data']));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $pdf['data'];
exit;
