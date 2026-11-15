<?php
/** A leave request's attachment for HR, the approver or the owner. ?id=<leave request id> */
require_once(__DIR__ . '/../../config.php');
if (!$_settings->userdata('id')) {
    http_response_code(401);
    die('Sign in to view this file.');
}
$id = (int)($_GET['id'] ?? 0);
$t = (int)$_settings->active_tenant_id();
$q = $conn->prepare("SELECT l.attachment_path p, l.attachment_name n, l.employee_id, e.approver_id FROM leave_list l JOIN employee_list e ON e.id = l.employee_id AND e.tenant_id = l.tenant_id
    WHERE l.id = ? AND l.tenant_id = ? AND l.attachment_path IS NOT NULL");
$q->bind_param('ii', $id, $t);
$q->execute();
$r = $q->get_result()->fetch_assoc();
$my = (int)$_settings->own_employee_id();
$allowed = $r && ($_settings->is_super_admin() || $_settings->can_access('leaves_edit') || ($my > 0 && ((int)$r['employee_id'] === $my || (int)$r['approver_id'] === $my)));
if (!$allowed) {
    http_response_code($r ? 403 : 404);
    die($r ? 'You do not have access to this file.' : 'File not found.');
}
\App\Support\LeaveAttachment::stream((string)$r['p'], (string)$r['n']);
