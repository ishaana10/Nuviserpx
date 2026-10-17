<?php

echo "=== TEST: Balance Sheet Tenant Isolation Static Check ===\n";

$bs_code = file_get_contents(__DIR__ . '/../admin/reports/balance_sheet.php');

if (!str_contains($bs_code, 'a.tenant_id = \'{$tenant_id}\'') || !str_contains($bs_code, 'j.tenant_id = \'{$tenant_id}\'')) {
    echo "FAILED: Balance sheet PHP script is missing explicit tenant_id checks on account_list (a) or journal_entries (j)\n";
    exit(1);
}

echo "BALANCE SHEET TENANT ISOLATION CODE PATTERN VERIFIED!\n";
