<?php
/**
 * Working trial balance: opening balance, period debits and credits, and closing
 * balance for every account — the worksheet accountants use before adjustments.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['fy_to_date'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['fy_to_date'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$ws = LedgerService::worksheet(nvr_ledger()->balances($from, $to));

/** Signed balance shown with Dr/Cr suffix. */
function nvr_drcr(float $v): string
{
    if (round($v, 2) == 0.0) {
        return '–';
    }
    return number_format(abs($v), 2) . ' <span class="muted">' . ($v > 0 ? 'Dr' : 'Cr') . '</span>';
}

nvr_header([
    'page' => 'reports/working_trial_balance',
    'title' => 'Working Trial Balance',
    'icon' => 'fas fa-th-list',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => nvr_nice_date($from) . ' – ' . nvr_nice_date($to),
]);
$t = $ws['totals'];
$okOpen = abs($t['open_dr'] - $t['open_cr']) < 0.005;
$okMove = abs($t['dr'] - $t['cr']) < 0.005;
?>
<?php if (!$ws['lines']): ?>
    <div class="nvr-empty"><i class="fas fa-th-list"></i><b>No account activity</b>There are no posted journals up to <?= nvr_nice_date($to) ?>.</div>
<?php else: ?>
    <div class="nvr-checks"><?= nvr_check($okOpen && $okMove, $okMove ? $t['open_dr'] - $t['open_cr'] : $t['dr'] - $t['cr'], 'Opening balances and movements balance', 'Out of balance') ?></div>
    <div class="nvr-scroll">
    <table class="nvr-table">
        <thead>
            <tr>
                <th>Account</th>
                <th>Code</th>
                <th class="num">Opening</th>
                <th class="num">Debits</th>
                <th class="num">Credits</th>
                <th class="num">Net movement</th>
                <th class="num">Closing</th>
            </tr>
        </thead>
        <tbody>
        <?php $cls = null; foreach ($ws['lines'] as $l): ?>
            <?php if ($l['class'] !== $cls): $cls = $l['class']; ?>
                <tr class="sec"><td colspan="7"><?= LedgerService::CLASS_LABELS[$cls] ?></td></tr>
            <?php endif; ?>
            <tr class="acct">
                <td><?= nvr_gl_link($l['id'], $from, $to, $l['name']) ?></td>
                <td class="code"><?= htmlspecialchars($l['code']) ?></td>
                <td class="num"><?= nvr_drcr($l['opening']) ?></td>
                <td class="num"><?= nvr_money($l['dr']) ?></td>
                <td class="num"><?= nvr_money($l['cr']) ?></td>
                <td class="num"><?= nvr_drcr($l['dr'] - $l['cr']) ?></td>
                <td class="num"><?= nvr_drcr($l['closing']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="grand">
                <td colspan="2">Total</td>
                <td class="num"><?= number_format($t['open_dr'], 2) ?> / <?= number_format($t['open_cr'], 2) ?></td>
                <td class="num"><?= nvr_money($t['dr'], false) ?></td>
                <td class="num"><?= nvr_money($t['cr'], false) ?></td>
                <td class="num"></td>
                <td class="num"><?= number_format($t['close_dr'], 2) ?> / <?= number_format($t['close_cr'], 2) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
<?php endif; ?>
<?php
nvr_footer(['Opening and closing totals are shown as debit / credit. Click an account to see the transactions behind its movement.']);
