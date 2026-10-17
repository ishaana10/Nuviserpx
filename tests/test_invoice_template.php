<?php

echo "=== TEST: Invoice Template Code Structure Check ===\n";

$tmpl_page = file_get_contents(__DIR__ . '/../admin/templates/manage_template.php');
if (!str_contains($tmpl_page, 'save_invoice_template') || !str_contains($tmpl_page, 'input_template_name')) {
    echo "FAILED: admin/templates/manage_template.php missing key form structures\n";
    exit(1);
}

$master_code = file_get_contents(__DIR__ . '/../classes/Master.php');
if (!str_contains($master_code, 'function save_invoice_template') || !str_contains($master_code, 'invoice_templates')) {
    echo "FAILED: classes/Master.php missing save_invoice_template implementation\n";
    exit(1);
}

$inv_view = file_get_contents(__DIR__ . '/../admin/invoices/view.php');
if (!str_contains($inv_view, 'invoice_templates') || !str_contains($inv_view, 'template_name')) {
    echo "FAILED: admin/invoices/view.php missing invoice_templates rendering\n";
    exit(1);
}

echo "INVOICE TEMPLATE CODE STRUCTURE TEST PASSED!\n";
