<?php
/**
 * Tests for document numbering and per-company chart provisioning (no database).
 *
 * Run: php tests/test_tenant_hardening.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\ChartProvisioner as C;
use App\Services\LedgerService;
use App\Services\SequenceService as S;

$passed = 0;
function check(bool $cond, string $label): void
{
    global $passed;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $passed++;
    echo "  ✓ {$label}\n";
}

echo "=== Document numbers ===\n";
check(S::format('202611-', 42) === '202611-00042', 'codes are the month, a dash and five digits');
check(S::format('202611-', 123456) === '202611-123456', 'numbers past five digits still work');
check(S::parse('202611-', '202611-00042') === 42, 'the number is read back out of a code');
check(S::parse('202611-', '202610-00042') === 0, 'a code from another month is not counted');
check(S::parse('202611-', '202611-ABC') === 0 && S::parse('202611-', '202611-') === 0, 'hand-typed codes are ignored when seeding');
check(S::parse('202611-', S::format('202611-', 7)) === 7, 'format and parse round-trip');

echo "\n=== Standard chart ===\n";
check(C::chartIsValid(), 'every account type is one the chart offers; codes and names are unique');
$byName = [];
foreach (C::STANDARD_CHART as [$code, $name, $type]) {
    $byName[strtolower($name)] = [$code, $type];
}
foreach (['accounts receivable', 'accounts payable', 'sales revenue', 'purchases', 'vat output', 'vat input', 'opening balance equity', 'retained earnings', 'depreciation'] as $need) {
    check(isset($byName[$need]), "the chart includes “{$need}”, which automatic postings look for");
}
check(LedgerService::classify($byName['vat output'][1])['class'] === 'liability', 'VAT Output is a liability');
check(LedgerService::classify($byName['vat input'][1])['class'] === 'asset', 'VAT Input is an asset');
check(LedgerService::classify($byName['opening balance equity'][1])['class'] === 'equity' && $byName['opening balance equity'][0] === '3999', 'Opening Balance Equity is equity with the reserved code 3999');
$classes = [];
foreach (C::STANDARD_CHART as [$code, , $type]) {
    $cl = LedgerService::classify($type)['class'];
    $classes[$cl] = true;
    $band = LedgerService::CODE_BANDS[$cl];
    if ((int)$code < $band || (int)$code > $band + 999) {
        check(false, "code {$code} sits in the {$cl} band");
    }
}
check(count($classes) === 5, 'all five account classes are present and each code sits in its band');
check(strpos(strtolower(implode('|', array_column(C::STANDARD_CHART, 1))), 'fiji') === false, 'no country-specific naming in the default chart');

echo "\n=== No hand-rolled numbering left ===\n";
$root = __DIR__ . '/../';
$offenders = [];
foreach (['src/Services', 'classes'] as $dir) {
    foreach (glob($root . $dir . '/*.php') as $f) {
        if (preg_match('/while \(true\) \{\s*\$check = \$this->conn->query\("SELECT[^;]*journal_entries/s', file_get_contents($f))) {
            $offenders[] = basename($f);
        }
    }
}
check(!$offenders, 'no service still scans for a free journal number' . ($offenders ? ': ' . implode(', ', $offenders) : ''));

echo "\nAll {$passed} tenant-hardening checks passed.\n";
