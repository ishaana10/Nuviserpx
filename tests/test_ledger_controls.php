<?php
/**
 * Tests for the ledger controls: period lock, immutable posted journals, reversal,
 * audit descriptions and the permission map for the actions that expose them.
 * Pure functions and source checks only; no database required.
 *
 * Run: php tests/test_ledger_controls.php
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use App\Services\JournalService as J;
use App\Services\PeriodLockService as P;
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

echo "=== Period lock ===\n";
check(P::isLocked('2026-09-30', '2026-09-30'), 'the lock date itself is locked');
check(P::isLocked('2026-09-30', '2026-01-15'), 'earlier dates are locked');
check(!P::isLocked('2026-09-30', '2026-10-01'), 'the day after the lock is open');
check(!P::isLocked(null, '2020-01-01'), 'no lock means nothing is locked');
check(!P::isLocked('2026-09-30', null) && !P::isLocked('2026-09-30', ''), 'a missing date is never reported as locked');
check(P::isLocked('2026-09-30 00:00:00', '2026-09-30 13:45:00'), 'datetime values compare on their date part');
check(P::message(null, '2026-01-01') === null, 'no message when nothing is locked');
check(P::message('2026-09-30', '2026-10-05', null) === null, 'open and null dates give no message');
$m = P::message('2026-09-30', '2026-10-05', '2026-08-14');
check($m !== null && str_contains($m, '30 Sep 2026') && str_contains($m, '14 Aug 2026'), 'message names the lock date and the offending date');
check(P::message('2026-09-30', '2026-10-05', '2026-08-14', '2026-07-01') === $m, 'the first locked date is the one reported');
$threw = false;
try { P::guardDocument(new class extends mysqli { public function __construct() {} }, 1, null, 'users; DROP TABLE x', 1); } catch (\InvalidArgumentException $e) { $threw = true; }
check($threw, 'guardDocument refuses a table name that does not own journals');

echo "\n=== Journal status rules ===\n";
foreach (['posted' => 'posted', '' => 'posted', null => 'posted', 'POSTED' => 'posted', 'draft' => 'draft', ' Draft ' => 'draft',
          'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'void' => 'cancelled', '2' => 'cancelled', '3' => 'cancelled'] as $in => $kind) {
    check(J::statusKind($in) === $kind, '“' . var_export($in, true) . "” is {$kind}");
}
check(J::lockedByStatus('draft') === null, 'a draft may be changed');
check(J::lockedByStatus('draft', 'deleted') === null, 'a draft may be deleted');
$posted = J::lockedByStatus('posted', 'deleted');
check($posted !== null && str_contains($posted, 'posted journal cannot be deleted') && str_contains($posted, 'Reverse'), 'a posted journal cannot be deleted and the message points to reversal');
check(str_contains((string)J::lockedByStatus('', 'changed'), 'cannot be changed'), 'a legacy row with no status counts as posted');
check(str_contains((string)J::lockedByStatus('cancelled', 'cancelled'), 'cancelled journal'), 'a cancelled journal is kept on record');

echo "\n=== Reversal ===\n";
$orig = [
    ['account_id' => 1, 'side' => 1, 'amount' => '1150.0000', 'description' => 'Cash sale', 'tax_rate' => 'No Tax (0%)'],
    ['account_id' => 6, 'side' => 2, 'amount' => '1000.0000', 'description' => 'Sales', 'tax_rate' => 'VAT on Income (15%)'],
    ['account_id' => 7, 'side' => 2, 'amount' => '150.0000', 'description' => 'VAT', 'tax_rate' => 'No Tax (0%)'],
];
$rev = J::reverseLines($orig);
check(count($rev) === 3 && $rev[0]['side'] === 2 && $rev[1]['side'] === 1 && $rev[2]['side'] === 1, 'every line moves to the opposite side');
check($rev[0]['amount'] === '1150.0000' && $rev[1]['amount'] === '1000.0000', 'amounts pass through as exact strings');
check($rev[1]['tax_rate'] === 'VAT on Income (15%)' && $rev[0]['description'] === 'Cash sale', 'descriptions and tax labels are kept');
$dr = $cr = 0.0;
foreach (array_merge($orig, $rev) as $l) { $l['side'] === 1 ? $dr += (float)$l['amount'] : $cr += (float)$l['amount']; }
check(abs($dr - $cr) < 0.005, 'original plus reversal balances');
$net = [];
foreach (array_merge($orig, $rev) as $l) { $net[$l['account_id']] = ($net[$l['account_id']] ?? 0) + ($l['side'] === 1 ? 1 : -1) * (float)$l['amount']; }
check(count(array_filter($net, fn ($v) => abs($v) > 0.005)) === 0, 'every account nets to zero after the reversal');
check(J::reverseLines([['account_id' => 0, 'side' => 1, 'amount' => 5], ['account_id' => 3, 'side' => 1, 'amount' => 0]]) === [], 'blank and zero lines are not reversed');
check(J::reverseLines(J::reverseLines($orig)) === $orig, 'reversing twice restores the original sides');

echo "\n=== Audit descriptions ===\n";
check(J::describeLines([['account_id' => 12, 'debit' => 100, 'credit' => 0], ['account_id' => 40, 'debit' => 0, 'credit' => 100]]) === 'Dr #12 100.00; Cr #40 100.00', 'form lines are described debit/credit');
check(J::describeLines([['account_id' => 12, 'side' => 1, 'amount' => '2500.5000'], ['account_id' => 40, 'side' => 2, 'amount' => '2500.5000']]) === 'Dr #12 2500.50; Cr #40 2500.50', 'stored lines are described the same way');
check(J::describeLines([['account_id' => 0, 'debit' => 0, 'credit' => 0]]) === 'no lines' && J::describeLines([]) === 'no lines', 'blank lines are left out');

echo "\n=== Permissions ===\n";
check(Permissions::forAction('reverse_journal') === 'journals_create', 'reversing needs the right to post journals');
check(Permissions::forAction('post_opening_balances') === 'journals_create', 'opening balances need the right to post journals');
check(Permissions::forAction('set_period_lock') === 'settings_manage', 'closing the books is a tenant-admin right');
check(Permissions::forAction('delete_journal') === 'journals_delete' && Permissions::forAction('cancel_journal') === 'journals_edit', 'existing journal rights are unchanged');

echo "\n=== Every write action in Master.php is gated ===\n";
// An action missing from the map needs nothing beyond a login, so a new write action that is
// forgotten there is open to every user. Reads and public portal actions are listed explicitly.
$src = file_get_contents(__DIR__ . '/../classes/Master.php');
preg_match_all("/^    case '([a-z0-9_]+)':/m", $src, $mm);
$readOnlyOrOther = [];
$unmapped = [];
foreach (array_unique($mm[1]) as $a) {
    if (in_array($a, Permissions::PUBLIC_ACTIONS, true) || Permissions::forAction($a) !== null) {
        continue;
    }
    $unmapped[] = $a;
}
$writeLike = array_values(array_filter($unmapped, fn ($a) => preg_match('/^(save|delete|post|cancel|reverse|set|update|create|approve|void|import|run|upload|send|reset|toggle|process|confirm|convert|record|add|remove|issue|lock|close|reopen)_/', $a)));
check($writeLike === [], 'no write-style action is missing from the permission map' . ($writeLike ? ' — missing: ' . implode(', ', $writeLike) : ''));

echo "\nAll {$passed} ledger-control checks passed.\n";
