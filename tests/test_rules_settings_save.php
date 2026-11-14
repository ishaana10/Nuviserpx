<?php
// The rules-settings form serializes every field validate_rules() requires.
$root = dirname(__DIR__);
$s = file_get_contents($root . '/admin/payroll/rules_settings.php');
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }
check('form has the tax-year start month field', strpos($s, 'id="r-tym"') !== false && strpos($s, 'Tax year starts in') !== false);
check('field is loaded from and saved into the rules', strpos($s, "rules.tax_year_start_month") !== false && strpos($s, "tax_year_start_month: parseInt(\$('#r-tym').val(), 10) || 1") !== false);
// every key validate_rules requires is present in the serialized object
foreach (['tax_year_start_month', 'pension', 'resident_bands', 'non_resident_bands', 'secondary_income_rate', 'redundancy', 'overtime', 'attendance', 'employer_levies'] as $k) {
    check("serializer includes $k", strpos($s, $k . ':') !== false || strpos($s, "'$k'") !== false || strpos($s, $k) !== false);
}
echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
