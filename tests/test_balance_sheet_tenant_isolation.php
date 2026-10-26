<?php

echo "=== TEST: Balance Sheet Tenant Isolation Static Check ===\n";

// Financial statements read balances through App\Services\LedgerService, which is
// constructed with the active tenant. Check the page uses it and that every ledger
// query is scoped to the tenant.
$bs_code = file_get_contents(__DIR__ . '/../admin/reports/balance_sheet.php');
$kit_code = file_get_contents(__DIR__ . '/../admin/reports/_kit.php');
$ledger_code = file_get_contents(__DIR__ . '/../src/Services/LedgerService.php');

$checks = [
    'balance sheet reads balances via the shared ledger' => str_contains($bs_code, 'nvr_ledger()') && str_contains($bs_code, '->balances('),
    'ledger is built for the active tenant' => str_contains($kit_code, 'new LedgerService($conn, (int)$_settings->active_tenant_id())'),
    'account balances filter accounts by tenant' => str_contains($ledger_code, 'WHERE a.tenant_id = ? AND a.delete_flag = 0'),
    'account balances only join that tenant\'s journals' => str_contains($ledger_code, 'j.tenant_id = a.tenant_id'),
    'account transactions filter journals by tenant' => substr_count($ledger_code, 'j.tenant_id = ?') >= 4,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        echo "FAILED: {$label}\n";
        exit(1);
    }
    echo "  ✓ {$label}\n";
}

echo "BALANCE SHEET TENANT ISOLATION CODE PATTERN VERIFIED!\n";
