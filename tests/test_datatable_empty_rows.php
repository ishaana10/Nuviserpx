<?php
// Empty-state rows (one td with a colspan) crash DataTables; the UI layer
// strips them at init and reuses their text as the emptyTable message.
$root = dirname(__DIR__);
$js = file_get_contents($root . '/dist/js/nuvis-ui.js');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('both DataTables entry points are wrapped', (bool)preg_match("/\\\$\.each\(\['dataTable', 'DataTable'\]/", $js));
check('wrapper installs at load time, not on ready', strpos($js, "\$.fn[name] = wrapped;") !== false && strpos($js, "\$.fn[name] = wrapped;") < strpos($js, "\$(function () {"));
check('placeholder rows are removed before init', strpos($js, "parseInt(\$tds.attr('colspan') || '1', 10) > 1") !== false);
check('their wording becomes the emptyTable message', strpos($js, "opts.language = \$.extend({ emptyTable: msg }, opts.language);") !== false);
check('statics and prototype are preserved on the wrapper', strpos($js, '$.extend(wrapped, orig);') !== false && strpos($js, 'wrapped.prototype = orig.prototype;') !== false);

// Every page that pairs a DataTable init with a colspan empty-state row relies
// on the wrapper; make sure the pairing is at least known (regression list).
$known = ['attendance/shift_roster', 'settings/audit_logs', 'tickets/index', 'contracts/index', 'settings/currencies'];
foreach ($known as $p) {
    $s = file_get_contents($root . "/admin/{$p}.php");
    check("$p keeps its server-rendered empty row (handled by the wrapper)", strpos($s, 'colspan=') !== false);
}

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
