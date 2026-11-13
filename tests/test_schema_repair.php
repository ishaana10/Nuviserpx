<?php
// Live-error fixes: sequence table self-heal, 024 repair migration, CSV
// exports no longer corrupted by the layout.
$root = dirname(__DIR__);
$m36 = file_get_contents($root . '/database/migrations/036_repair_runtime_schema.sql');
$m24 = file_get_contents($root . '/database/migrations/024_runtime_schema_catchup.sql');
$seq = file_get_contents($root . '/src/Services/SequenceService.php');
$idx = file_get_contents($root . '/admin/index.php');
$ems = file_get_contents($root . '/admin/payroll/ems_export.php');
$fnpf = file_get_contents($root . '/admin/payroll/fnpf_schedule.php');

$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

check('repair migration replays every statement of 024', str_contains($m36, $m24));
check('repair migration explains itself', str_contains($m36, 'recorded 024_runtime_schema_catchup as'));
check('sequence service creates its table before preparing', str_contains($seq, 'CREATE TABLE IF NOT EXISTS `document_sequences`') && str_contains($seq, 'self::ensureTable($conn);'));
check('sequence failure throws a readable error with the errno, not a fatal', str_contains($seq, 'The document_sequences table is unavailable ([{$conn->errno}]'));
check('admin layout output is buffered from the first byte', strpos($idx, 'ob_start();') !== false && strpos($idx, 'ob_start();') < strpos($idx, '<!DOCTYPE html'));
check('EMS export clears the buffered layout before its headers', strpos($ems, 'ob_end_clean') !== false && strpos($ems, 'ob_end_clean') < strpos($ems, "header('Content-Type: text/csv')"));
check('FNPF export clears the buffered layout before its headers', strpos($fnpf, 'ob_end_clean') !== false && strpos($fnpf, 'ob_end_clean') < strpos($fnpf, "header('Content-Type: text/csv')"));

echo $fail ? "$fail FAILED\n" : "All passed\n";
exit($fail ? 1 : 0);
