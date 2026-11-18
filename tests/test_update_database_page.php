<?php
// The developer's "Database updates" page: developer-only, CSRF-protected, and the only page the schema gate lets through.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
$pg = file_get_contents($root . '/admin/update_database.php');
$gate = file_get_contents($root . '/classes/DBConnection.php');
check('page refuses anyone but a super administrator', strpos($pg, "userdata('type') !== 1") !== false && strpos($pg, 'http_response_code(403)') !== false);
check('running it requires a valid CSRF token', strpos($pg, "require_csrf('html')") !== false && strpos($pg, "REQUEST_METHOD'] === 'POST'") !== false);
check('a failed update is reported, not recorded', strpos($pg, 'MigrationFailed') !== false);
check('the schema gate lets only this page through, only for the super administrator',
    strpos($gate, "=== 'update_database.php'") !== false && strpos($gate, "['type'] ?? 0) === 1") !== false);
check('the Developer menu links to it', strpos(file_get_contents($root . '/admin/inc/menus/11_developer.php'), 'update_database.php') !== false);
check('billing schema is created by an idempotent migration 054 (053 is left as applied)', is_file($root . '/database/migrations/054_platform_billing_owner_books.sql')
    && strpos(file_get_contents($root . '/database/migrations/053_platform_billing.sql'), 'owner_invoice_id') === false);
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
