<?php
// Slice 5 of the UX polish: pinned Save bar on the invoice form (phones).
$root = dirname(__DIR__);
$f   = file_get_contents($root . '/admin/invoices/manage_invoice.php');
$css = file_get_contents($root . '/dist/css/nuvis-ui.css');
$cl  = file_get_contents($root . '/CHANGELOG.md');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('invoice form footer uses the save bar', strpos($f, 'class="nv-save-bar"') !== false);
check('save bar still holds the submit button and the back link inside the form', (bool)preg_match('/<div class="nv-save-bar">.*type="submit".*<\/div>\s*<\/form>/s', $f));
check('back button keeps an accessible name when its label is hidden', strpos($f, 'aria-label="Back to Invoices List"') !== false && strpos($f, 'nv-bar-label') !== false);
check('bar is sticky to the bottom on phones only', (bool)preg_match('/@media \(max-width: 767\.98px\) \{\s*\.nv-save-bar \{\s*position: sticky; bottom: 0;/', $css));
check('bar respects the iPhone safe area', strpos($css, 'env(safe-area-inset-bottom') !== false);
check('buttons are 44px touch targets on phones', (bool)preg_match('/\.nv-save-bar \.btn \{ min-height: 44px/', $css));
check('save bar sits above page content but below modals (z-index < 1050)', (bool)preg_match('/z-index: 1020/', $css));
check('only one "### Added" per release section lists each UX slice (no duplicates)', substr_count($cl, 'UX polish, slice 5') === 1 && substr_count($cl, 'UX polish, slice 4') === 1 && substr_count($cl, 'UX polish, slice 1') === 1);
check('changelog has no conflict markers', strpos($cl, '<<<<<<<') === false && strpos($cl, '>>>>>>>') === false);
exit($fail ? 1 : 0);
