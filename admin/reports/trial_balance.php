<?php
/**
 * Trial balance: every account's closing balance at a date, in debit or credit.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$as_of = nvr_date($_GET['as_of'] ?? null, date('Y-m-d'));
$fy_start = LedgerService::fyStart($as_of, nvr_year_end_month());

// Income and expenses run for the financial year; earlier years' profit is one retained-earnings line.
$tb = LedgerService::trialBalance(nvr_ledger()->balances($fy_start, $as_of), true);

nvr_header([
    'page' => 'reports/trial_balance',
    'title' => 'Trial Balance',
    'icon' => 'fas fa-balance-scale',
    'mode' => 'asat',
    'asat' => $as_of,
    'subtitle' => 'As at ' . nvr_nice_date($as_of),
]);
?>
<?php if (!$tb['lines']): ?>
    <div class="nvr-empty"><i class="fas fa-book"></i><b>No posted journals up to this date</b>Post journals, invoices or payments and they'll appear here.</div>
<?php else: ?>
    <div class="nvr-checks"><?= nvr_check($tb['balanced'], $tb['difference'], 'Debits equal credits', 'Out of balance') ?></div>
    <div class="nvr-scroll">
    <table class="nvr-table">
        <thead>
            <tr>
                <th>Account</th>
                <th>Code</th>
                <th>Type</th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
            </tr>
        </thead>
        <tbody>
        <?php $cls = null; foreach ($tb['lines'] as $l): ?>
            <?php if ($l['class'] !== $cls): $cls = $l['class']; ?>
                <tr class="sec"><td colspan="5"><?= LedgerService::CLASS_LABELS[$cls] ?></td></tr>
            <?php endif; ?>
            <tr class="acct<?= !empty($l['computed']) ? ' computed' : '' ?>">
                <td><?= nvr_gl_link($l['id'], $fy_start <= $as_of ? $fy_start : $as_of, $as_of, $l['name']) ?></td>
                <td class="code"><?= htmlspecialchars($l['code']) ?></td>
                <td><span class="nvr-pill"><?= htmlspecialchars($l['type'] ?: 'Unclassified') ?></span></td>
                <td class="num"><?= $l['debit'] ? nvr_money($l['debit']) : '' ?></td>
                <td class="num"><?= $l['credit'] ? nvr_money($l['credit']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="grand">
                <td colspan="3">Total</td>
                <td class="num"><?= nvr_money($tb['debit'], false) ?></td>
                <td class="num"><?= nvr_money($tb['credit'], false) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
<?php endif; ?>
<?php
nvr_footer([
    'Income and expense accounts show the financial year from ' . nvr_nice_date($fy_start) . '; profit from earlier years is shown as retained earnings.',
    'Click an account to see its transactions for the financial year to date. For opening balances and period movement, use the <a href="./?page=reports/working_trial_balance&from=' . $fy_start . '&to=' . $as_of . '">working trial balance</a>.',
]);
