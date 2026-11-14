<?php
// Slice 1 of the UX polish: stacked-card tables on phones + action bar.
$root = dirname(__DIR__);
$js  = file_get_contents($root . '/dist/js/nuvis-ui.js');
$css = file_get_contents($root . '/dist/css/nuvis-ui.css');
$inv = file_get_contents($root . '/admin/invoices/index.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('labelCells is defined and called from enhanceRows', strpos($js, 'function labelCells') !== false && (bool)preg_match('/function enhanceRows\(api\) \{.*labelCells\(api\);/s', $js));
check('labelling is opt-in via data-nv-cards', strpos($js, "[data-nv-cards]") !== false);
check('labels are written as attributes (never HTML)', strpos($js, ".attr('data-label'") !== false);
check('utility/empty headers get no label', (bool)preg_match('/function labelCells.*isUtilityHeader/s', $js));
check('empty-table row is not labelled', (bool)preg_match('/function labelCells.*dataTables_empty/s', $js));
check('card CSS only applies on phones', (bool)preg_match('/@media \(max-width: 767\.98px\) \{[^@]*table\.nv-table\[data-nv-cards\]/s', $css));
check('cells show their label via attr(data-label)', strpos($css, 'content: attr(data-label)') !== false);
check('card-mode hides the header row visually', strpos($css, 'table.nv-table[data-nv-cards] thead') !== false);
check('action bar: card tools right-aligned, full width button on phones', strpos($css, '.nv-card-actions') !== false);
check('phone touch targets are at least 40px', strpos($css, 'min-height: 40px') !== false);
check('invoices list opts in to card mode', strpos($inv, 'data-nv-cards') !== false);
exit($fail ? 1 : 0);
