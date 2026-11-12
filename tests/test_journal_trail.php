<?php
/** Tests for the journal revision trail (no database). Run: php tests/test_journal_trail.php */
require_once(__DIR__ . '/../vendor/autoload.php');
use App\Services\JournalTrail as J;

$passed = 0;
function check(bool $c, string $l): void { global $passed; if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); } $passed++; echo "  ✓ $l\n"; }

$a = ['date' => '2026-10-01', 'lines' => [[5, 1, 100.0], [9, 2, 100.0]]];
check(!J::changed($a, $a), 'identical journals are not a change');
check(!J::changed($a, ['date' => '2026-10-01', 'lines' => [[9, 2, 100.0], [5, 1, 100.0]]]), 'line order does not matter');
check(!J::changed($a, ['date' => '2026-10-01', 'lines' => [[5, 1, 100.004], [9, 2, 99.996]]]), 'differences below a cent are not a change');
check(J::changed($a, ['date' => '2026-10-01', 'lines' => [[5, 1, 120.0], [9, 2, 120.0]]]), 'a different amount is a change');
check(J::changed($a, ['date' => '2026-10-02', 'lines' => $a['lines']]), 'a different date is a change');
check(J::changed($a, ['date' => '2026-10-01', 'lines' => [[6, 1, 100.0], [9, 2, 100.0]]]), 'a different account is a change');
check(J::changed($a, ['date' => '2026-10-01', 'lines' => [[5, 2, 100.0], [9, 1, 100.0]]]), 'swapped sides are a change');
check(J::changed($a, ['date' => '2026-10-01', 'lines' => [[5, 1, 100.0], [9, 2, 60.0], [8, 2, 40.0]]]), 'a split line is a change');
foreach (['InvoiceService', 'PurchaseService', 'PaymentService', 'PayrollService'] as $svc) {
    $s = file_get_contents(__DIR__ . "/../src/Services/$svc.php");
    check(strpos($s, 'JournalTrail') !== false && strpos($s, '->record(') !== false, "$svc records journal rewrites");
}
check(strpos(file_get_contents(__DIR__ . '/../src/Database/LegacySchemaCatchup.php'), 'check_journal_trail_migrations();') !== false, 'the migration is in the chain');
echo "\nAll {$passed} journal trail checks passed.\n";
