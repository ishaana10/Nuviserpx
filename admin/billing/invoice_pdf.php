<?php
/** A tenant invoice as the same PDF the owner company prints from Sales. ?id=<billing link id> (&download=1 to save). */
require_once __DIR__ . '/_pdf_guard.php';
nv_pb_send_pdf((new \App\Services\PlatformBillingService($conn, $_settings))->invoicePdf((int)($_GET['id'] ?? 0)));
