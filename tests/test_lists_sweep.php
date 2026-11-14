<?php
// Slice 6 of the UX polish: remaining list pages opt in to phone card mode (see test_ui_cards.php).
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$pages = ['api_keys/index', 'attendance/index', 'bank_reconciliation/index', 'leaves/index', 'payments/index', 'purchases/index', 'purchases/vendor_bills', 'roles/index', 'stock_adjustments/index', 'tenants/index', 'webhooks/index'];
foreach ($pages as $p) {
    $f = file_get_contents("$root/admin/$p.php");
    check("$p opts in to card mode on its main table", (bool)preg_match('/<table[^>]*id="list"[^>]*data-nv-cards/', $f));
    check("$p keeps a single id=list table with a DataTable init", substr_count($f, 'id="list"') === 1 && strpos($f, 'dataTable(') !== false);
}
// Workflows has a data column headed "Actions" (a count) which the label logic would treat as the action menu,
// so it stays out of card mode until that column is renamed.
check('workflows list is intentionally not in card mode', strpos(file_get_contents("$root/admin/workflows/index.php"), 'data-nv-cards') === false);
exit($fail ? 1 : 0);
