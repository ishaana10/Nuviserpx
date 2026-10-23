<?php
// Test audit logs query structure and optimization

$auditLogsCode = file_get_contents(__DIR__ . '/../admin/settings/audit_logs.php');

$hasLeftJoin = strpos($auditLogsCode, 'LEFT JOIN `tenants` t ON al.tenant_id = t.id') !== false &&
               strpos($auditLogsCode, 't.name as tenant_name') !== false;

$hasNPlusOneQuery = strpos($auditLogsCode, "SELECT name FROM `tenants` WHERE id =") !== false ||
                    strpos($auditLogsCode, "SELECT name FROM tenants WHERE id =") !== false;

$usesJoinedTenantName = strpos($auditLogsCode, "\$row['tenant_name']") !== false;

if ($hasLeftJoin && !$hasNPlusOneQuery && $usesJoinedTenantName) {
    echo "=== TEST: Audit Logs Optimization Static Check ===\n";
    echo "AUDIT LOGS LEFT JOIN TENANTS OPTIMIZATION & N+1 QUERY REMOVAL VERIFIED!\n";
    exit(0);
} else {
    echo "ERROR: Audit logs optimization check failed!\n";
    echo "hasLeftJoin: " . ($hasLeftJoin ? 'YES' : 'NO') . "\n";
    echo "hasNPlusOneQuery: " . ($hasNPlusOneQuery ? 'YES' : 'NO') . "\n";
    echo "usesJoinedTenantName: " . ($usesJoinedTenantName ? 'YES' : 'NO') . "\n";
    exit(1);
}
