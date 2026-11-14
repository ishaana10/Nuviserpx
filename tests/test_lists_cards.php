<?php
// Slice 4 of the UX polish: main list pages opt in to phone card mode (see test_ui_cards.php).
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

foreach (['sales_orders', 'parties', 'products', 'employees', 'payroll'] as $p) {
    $f = file_get_contents("$root/admin/$p/index.php");
    check("$p list opts in to card mode on its main table", (bool)preg_match('/<table[^>]*id="list"[^>]*data-nv-cards/', $f));
    check("$p keeps its single main table (id=list) and DataTable init", substr_count($f, 'id="list"') === 1 && strpos($f, "\$('#list').dataTable(") !== false);
    check("$p has a utility/index header so those cells stay unlabelled", strpos($f, '<th>#</th>') !== false && (strpos($f, '<th>Action</th>') !== false));
}
// the card CSS/JS they rely on
check('card mode CSS exists', strpos(file_get_contents("$root/dist/css/nuvis-ui.css"), 'table.nv-table[data-nv-cards]') !== false);
exit($fail ? 1 : 0);
