<?php

echo "=== TEST: Chart of Accounts Structure & Bulk Handlers Check ===\n";

$index_code = file_get_contents(__DIR__ . '/../admin/accounts/index.php');
if (!str_contains($index_code, 'chart of accounts') || !str_contains($index_code, 'btn_bulk_delete') || !str_contains($index_code, 'btn_bulk_archive')) {
    echo "FAILED: admin/accounts/index.php missing Chart of Accounts toolbar or bulk action structures\n";
    exit(1);
}

$manage_code = file_get_contents(__DIR__ . '/../admin/accounts/manage_account.php');
if (!str_contains($manage_code, 'name="code"') || !str_contains($manage_code, 'name="tax_rate"')) {
    echo "FAILED: admin/accounts/manage_account.php missing account code or tax rate fields\n";
    exit(1);
}

$master_code = file_get_contents(__DIR__ . '/../classes/Master.php');
if (!str_contains($master_code, 'delete_accounts_bulk') || !str_contains($master_code, 'archive_accounts_bulk') || !str_contains($master_code, 'change_tax_rate_bulk')) {
    echo "FAILED: classes/Master.php missing bulk account action methods\n";
    exit(1);
}

echo "CHART OF ACCOUNTS CODE STRUCTURE TEST PASSED!\n";
