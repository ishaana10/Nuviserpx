<?php
/** Receipt for one payment on a tenant invoice. ?id=<billing link id>&payment=<payment id> (&download=1 to save). */
require_once __DIR__ . '/_pdf_guard.php';
nv_pb_send_pdf((new \App\Services\PlatformBillingService($conn, $_settings))->receiptPdf((int)($_GET['id'] ?? 0), (int)($_GET['payment'] ?? 0)));
