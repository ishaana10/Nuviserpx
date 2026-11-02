<?php
/**
 * Statement of changes in equity: for each equity account and retained earnings, the opening
 * balance, profit for the period, capital introduced or drawn, and the closing balance.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['fy_to_date'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['fy_to_date'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$eq = LedgerService::equityStatement(nvr_ledger()->balances($from, $to));
$t = $eq['totals'];

nvr_header([
    'page' => 'reports/equity_changes',
    'title' => 'Statement of Changes in Equity',
    'icon' => 'fas fa-layer-group',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => 'For the period ' . nvr_nice_date($from) . ' to ' . nvr_nice_date($to),
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Opening equity</div><div class="v"><?= nvr_money($t['opening'], false) ?></div><div class="s"><?= nvr_nice_date($from) ?></div></div>
    <div class="nvr-stat"><div class="k">Profit for the period</div><div class="v<?= $t['profit'] < 0 ? ' neg' : '' ?>"><?= nvr_money($t['profit'], false) ?></div><div class="s">From the profit and loss</div></div>
    <div class="nvr-stat"><div class="k">Capital introduced / (drawn)</div><div class="v<?= $t['movement'] < 0 ? ' neg' : '' ?>"><?= nvr_money($t['movement'], false) ?></div><div class="s">Owner and shareholder movements</div></div>
    <div class="nvr-stat"><div class="k">Closing equity</div><div class="v<?= $t['closing'] < 0 ? ' neg' : '' ?>"><?= nvr_money($t['closing'], false) ?></div><div class="s"><?= nvr_nice_date($to) ?></div></div>
</div>

<?php if (!$eq['rows']): ?>
    <div class="nvr-empty"><i class="fas fa-layer-group"></i><b>No equity balances or profit in this period</b>Check the dates, or make sure capital accounts have the Equity account type.</div>
<?php else: ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th></th><th class="num">Opening</th><th class="num">Profit for period</th><th class="num">Introduced / (drawn)</th><th class="num">Closing</th></tr></thead>
    <tbody>
    <?php foreach ($eq['rows'] as $r): ?>
        <tr class="acct">
            <td><?= nvr_gl_link($r['id'], $from, $to, $r['name']) ?></td>
            <td class="num"><?= nvr_money($r['opening']) ?></td>
            <td class="num"><?= nvr_money($r['profit']) ?></td>
            <td class="num"><?= nvr_money($r['movement']) ?></td>
            <td class="num"><?= nvr_money($r['closing']) ?></td>
        </tr>
    <?php endforeach; ?>
        <tr class="grand">
            <td>Total equity</td>
            <td class="num"><?= nvr_money($t['opening'], false) ?></td>
            <td class="num"><?= nvr_money($t['profit'], false) ?></td>
            <td class="num"><?= nvr_money($t['movement'], false) ?></td>
            <td class="num"><?= nvr_money($t['closing'], false) ?></td>
        </tr>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Retained earnings is calculated: it is the profit of all earlier periods, which is not held in any account. Closing equity equals total equity on the balance sheet at the end date.']);
