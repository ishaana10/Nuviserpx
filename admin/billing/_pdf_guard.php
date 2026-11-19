<?php
// Shared by the billing PDF endpoints: signed in, and either the developer or a company administrator.
require_once(dirname(__DIR__, 2) . '/config.php');
if (!$_settings->userdata('id')) {
    http_response_code(401);
    die('Sign in to view this document.');
}
if (!$_settings->can_access('settings_manage')) {
    http_response_code(403);
    die('You do not have permission to view billing documents.');
}
function nv_pb_send_pdf(?array $pdf): void
{
    if ($pdf === null) {
        http_response_code(404);
        die('Document not found, or the PDF could not be built.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $pdf['name'] . '"');
    header('Content-Length: ' . strlen($pdf['data']));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $pdf['data'];
    exit;
}
