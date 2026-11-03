<?php
/**
 * Tests for auto-reversing journals (rules and wiring; no database).
 *
 * Run: php tests/test_auto_reversal.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\AutoReversalService as R;
use App\Services\JournalService as J;
use App\Support\Permissions;

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

echo "=== When a reversal is due ===\n";
check(R::isDue('2026-12-01', '2026-12-01'), 'due on the day itself');
check(R::isDue('2026-12-01', '2026-12-15'), 'still due on a later day (a missed run catches up)');
check(!R::isDue('2026-12-01', '2026-11-30'), 'not due the day before');
check(!R::isDue(null, '2026-12-01') && !R::isDue('', '2026-12-01') && !R::isDue('soon', '2026-12-01'), 'no date, or a bad one, is never due');

echo "\n=== The reversal itself ===\n";
$orig = [
    ['account_id' => 5, 'side' => 1, 'amount' => '1200.0000', 'description' => 'Accrued rent', 'tax_rate' => 'No Tax (0%)'],
    ['account_id' => 9, 'side' => 2, 'amount' => '1200.0000', 'description' => 'Accrued liabilities', 'tax_rate' => 'No Tax (0%)'],
];
$rev = J::reverseLines($orig);
check(count($rev) === 2 && $rev[0]['side'] === 2 && $rev[1]['side'] === 1, 'every line swaps sides');
check($rev[0]['account_id'] === 5 && $rev[0]['amount'] === '1200.0000', 'same account and exact amount');
check(J::statusKind('posted') === 'posted' && J::statusKind('pending_approval') !== 'posted', 'only posted journals are eligible (not drafts, pending or cancelled)');

echo "\n=== Wiring ===\n";
check(Permissions::forAction('run_auto_reversals') === 'journals_create', 'running reversals needs permission to create journals');
$src = file_get_contents(__DIR__ . '/../src/Services/AutoReversalService.php');
check(str_contains($src, 'reverseJournal('), 'reversals reuse the manual reversal (period lock, audit and link to the original)');
check(str_contains($src, 'NOT EXISTS') && str_contains($src, 'reverses_id'), 'a journal already reversed is skipped');
check(str_contains($src, 'POSTED_SQL'), 'only posted journals are picked up');
$cron = file_get_contents(__DIR__ . '/../cron/auto_reverse_journals.php');
check(str_contains($cron, "PHP_SAPI !== 'cli'"), 'the cron script refuses to run from the web');
check(str_contains($cron, 'delete_flag = 0'), 'the cron script skips deleted companies');

echo "\nAll {$passed} auto-reversal checks passed.\n";
