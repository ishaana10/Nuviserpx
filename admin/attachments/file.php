<?php
/** Expense receipts and asset files for signed-in staff of the owning company. ?type=expense|asset&id=<record id> */
require_once(__DIR__ . '/../../config.php');
if (!$_settings->userdata('id') || $_settings->userdata('login_type') != 1) {
    http_response_code(401);
    die('Sign in to view this file.');
}
$id = (int)($_GET['id'] ?? 0);
$t = (int)$_settings->active_tenant_id();
$type = (string)($_GET['type'] ?? '');
$map = [
    'expense' => ['expense_reports', 'expenses_view', 'expenses'],
    'asset'   => ['fixed_assets', 'assets_view', 'assets'],
];
if (!isset($map[$type])) {
    http_response_code(404);
    die('File not found.');
}
[$table, $perm, $folder] = $map[$type];
if (!$_settings->is_super_admin() && !$_settings->can_access($perm)) {
    http_response_code(403);
    die('You do not have access to this file.');
}
$q = $conn->prepare("SELECT attachment_path FROM `{$table}` WHERE id = ? AND tenant_id = ? AND attachment_path IS NOT NULL");
$q->bind_param('ii', $id, $t);
$q->execute();
$r = $q->get_result()->fetch_assoc();
if (!$r) {
    http_response_code(404);
    die('File not found.');
}
\App\Support\PrivateFile::stream((string)$r['attachment_path'], [$folder]);
