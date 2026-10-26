<?php
/**
 * Cash summary: bank balances at the start and end of a period, and where the
 * money in between came from and went to (by the other side of each journal).
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['this_month'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['this_month'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$ledger = nvr_ledger();
$balances = $ledger->balances($from, $to, true);

$bankIds = [];
$accounts = [];
foreach ($balances as $b) {
    $accounts[$b['id']] = ['code' => $b['code'], 'name' => $b['name'], 'type' => $b['type']];
    if ($b['section'] === 'bank') {
        $bankIds[] = $b['id'];
    }
}
$cash = LedgerService::cashMovements($ledger->linesOfJournalsTouching($bankIds, $from, $to), $bankIds, $accounts);

$banks = [];
$open = $close = 0.0;
foreach ($bankIds as $id) {
    $b = $balances[$id];
    $o = round($b['open_dr'] - $b['open_cr'], 2);
    $c = round($o + $b['dr'] - $b['cr'], 2);
    if ($o == 0.0 && $c == 0.0 && $b['dr'] == 0.0 && $b['cr'] == 0.0) {
        continue;
    }
    $banks[] = ['id' => $id, 'name' => $b['name'], 'code' => $b['code'], 'open' => $o, 'in' => $b['dr'], 'out' => $b['cr'], 'close' => $c];
    $open += $o;
    $close += $c;
}

nvr_header([
    'page' => 'reports/cash_summary',
    'title' => 'Cash Summary',
    'icon' => 'fas fa-money-bill-wave',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => nvr_nice_date($from) . ' – ' . nvr_nice_date($to),
]);

$group = function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $out[LedgerService::CLASS_LABELS[$r['class']]][] = $r;
    }
    return $out;
};
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Cash at start</div><div class="v<?= $open < 0 ? ' neg' : '' ?>"><?= nvr_money($open, false) ?></div><div class="s"><?= nvr_nice_date($from) ?></div></div>
    <div class="nvr-stat"><div class="k">Cash received</div><div class="v"><?= nvr_money($cash['total_in'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Cash spent</div><div class="v"><?= nvr_money($cash['total_out'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Cash at end</div><div class="v<?= $close < 0 ? ' neg' : '' ?>"><?= nvr_money($close, false) ?></div><div class="s">Net <?= $cash['net'] >= 0 ? 'increase' : 'decrease' ?> <?= nvr_money(abs($cash['net']), false) ?></div></div>
</div>

<?php if (!$bankIds): ?>
    <div class="nvr-empty"><i class="fas fa-university"></i><b>No bank accounts yet</b>Give your bank and cash accounts the type “Bank” in the chart of accounts.</div>
<?php elseif (!$banks && !$cash['in'] && !$cash['out']): ?>
    <div class="nvr-empty"><i class="fas fa-money-bill-wave"></i><b>No cash movement in this period</b>Try a longer period.</div>
<?php else: ?>
<?php $diff = round(($close - $open) - $cash['net'], 2); ?>
<?php if (abs($diff) >= 0.01): ?>
    <div class="nvr-checks"><?= nvr_check(false, $diff, '', 'Some journals touching a bank account do not balance') ?></div>
<?php endif; ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th></th><th class="code"></th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach (['Cash received' => [$cash['in'], $cash['total_in'], 'Total cash received'], 'Cash spent' => [$cash['out'], $cash['total_out'], 'Total cash spent']] as $title => [$rows, $total, $totalLabel]): ?>
        <tr class="sec"><td colspan="3"><?= $title ?></td></tr>
        <?php if (!$rows): ?>
            <tr class="acct"><td colspan="3" class="muted">None in this period</td></tr>
        <?php endif; ?>
        <?php foreach ($group($rows) as $cls => $items): ?>
            <?php if (count($group($rows)) > 1): ?><tr class="acct"><td colspan="3" class="muted" style="font-size:.78rem;padding-top:10px"><?= $cls ?></td></tr><?php endif; ?>
            <?php foreach ($items as $r): ?>
            <tr class="acct"><td><?= nvr_gl_link($r['id'], $from, $to, $r['name']) ?></td><td class="code"><?= htmlspecialchars($r['code']) ?></td><td class="num"><?= nvr_money($r['amount']) ?></td></tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <tr class="sub"><td colspan="2"><?= $totalLabel ?></td><td class="num"><?= nvr_money($total, false) ?></td></tr>
    <?php endforeach; ?>
        <tr class="grand"><td colspan="2">Net cash movement</td><td class="num<?= $cash['net'] < 0 ? ' neg' : '' ?>"><?= nvr_money($cash['net'], false) ?></td></tr>
    </tbody>
</table>
</div>

<div class="nvr-scroll" style="margin-top:28px">
<table class="nvr-table">
    <thead><tr><th>Bank account</th><th class="num">Opening</th><th class="num">Money in</th><th class="num">Money out</th><th class="num">Closing</th></tr></thead>
    <tbody>
    <?php foreach ($banks as $b): ?>
        <tr class="acct">
            <td><?= nvr_gl_link($b['id'], $from, $to, trim(($b['code'] ? $b['code'] . ' · ' : '') . $b['name'])) ?></td>
            <td class="num<?= $b['open'] < 0 ? ' neg' : '' ?>"><?= nvr_money($b['open']) ?></td>
            <td class="num"><?= nvr_money($b['in']) ?></td>
            <td class="num"><?= nvr_money($b['out']) ?></td>
            <td class="num<?= $b['close'] < 0 ? ' neg' : '' ?>"><?= nvr_money($b['close']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="grand"><td>Total</td><td class="num"><?= nvr_money($open, false) ?></td><td class="num"><?= nvr_money(array_sum(array_column($banks, 'in')), false) ?></td><td class="num"><?= nvr_money(array_sum(array_column($banks, 'out')), false) ?></td><td class="num"><?= nvr_money($close, false) ?></td></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer([
    'Cash is grouped by the other side of each journal that touches a bank account (type “Bank”). Transfers between your own bank accounts' . ($cash['transfers'] ? ' (' . nvr_money($cash['transfers'], false) . ' this period)' : '') . ' are shown in the bank totals only.',
]);
