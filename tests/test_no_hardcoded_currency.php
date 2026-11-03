<?php
/** Guards against a hardcoded currency creeping back into screens and emails. Run: php tests/test_no_hardcoded_currency.php */
$root = dirname(__DIR__);
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || preg_match('#/(vendor|tests|plugins|node_modules|\.git)/#', $p)) { continue; }
    $rel = substr($p, strlen($root) + 1);
    if (preg_match('#^(classes/PayrollEngine\.php|admin/payroll/tax_rules\.php)$#', $rel)) { continue; } // statutory payroll rules
    if (preg_match('/\bFJD\b/', file_get_contents($p))) { $bad[] = $rel; }
}
if ($bad) { fwrite(STDERR, "FAIL: hardcoded FJD in: " . implode(', ', $bad) . "\n"); exit(1); }
$ss = file_get_contents($root . '/classes/SystemSettings.php');
if (strpos($ss, 'function base_currency') === false || strpos($ss, 'function currency_tag') === false) { fwrite(STDERR, "FAIL: helpers missing\n"); exit(1); }
echo "  ✓ no hardcoded FJD in screens, services or emails\n  ✓ base_currency and currency_tag helpers exist\n";
