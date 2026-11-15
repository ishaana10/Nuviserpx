<?php
/** The signed-in employee's own leave attachment (e.g. a medical certificate). ?id=<leave request id> */
require_once(__DIR__ . '/_employee_layout.php');
$doc = $leave->attachment((int)($_GET['id'] ?? 0));
if ($doc === null) {
    http_response_code(404);
    die('File not found.');
}
\App\Support\LeaveAttachment::stream($doc[0], $doc[1]);
