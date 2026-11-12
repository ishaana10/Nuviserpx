<?php
// The Login Security Log was the audit trail filtered to sign-ins, so the two
// pages showed the same data. It now lives in the audit trail: this stub keeps
// old links working by opening Audit Logs filtered to Authentication.
$_GET['module'] = 'Authentication';
include __DIR__ . '/audit_logs.php';
