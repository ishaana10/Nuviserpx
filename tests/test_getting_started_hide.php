<?php
// The Getting Started checklist disappears once every step is complete.
$root = dirname(__DIR__);
$js = file_get_contents($root . '/dist/js/nuvis-dashboard.js');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
check('completed checklist removes its card from the dashboard', strpos($js, "if (data.complete) {") !== false && strpos($js, "closest('.nv-w-col').remove()") !== false);
check('backend reports completion', strpos(file_get_contents($root.'/src/Services/DashboardService.php'), "'complete' => \$steps !== [] && \$done === count(\$steps)") !== false);
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
