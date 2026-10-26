<?php
/**
 * Profit and loss: trading income, cost of sales, gross profit, other income,
 * operating expenses and net profit, with an optional comparison period.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['fy_to_date'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['fy_to_date'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$compare = in_array($_GET['compare'] ?? '', ['previous_period', 'previous_year'], true) ? $_GET['compare'] : 'none';

$ledger = nvr_ledger();
$pl = LedgerService::incomeStatement($ledger->balances($from, $to));

$cmp = null;
$cmpLabel = '';
if ($compare !== 'none') {
    if ($compare === 'previous_year') {
        $cFrom = date('Y-m-d', strtotime($from . ' -1 year'));
        $cTo = date('Y-m-d', strtotime($to . ' -1 year'));
    } else {
        $days = (int)((strtotime($to) - strtotime($from)) / 86400);
        $cTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $cFrom = date('Y-m-d', strtotime($cTo . " -{$days} days"));
    }
    $cmp = LedgerService::incomeStatement($ledger->balances($cFrom, $cTo));
    $cmpLabel = date('j M y', strtotime($cFrom)) . ' – ' . date('j M y', strtotime($cTo));
}

/** Account amounts from the comparison statement, keyed by section+account. */
$cmpMap = [];
if ($cmp) {
    foreach ($cmp['sections'] as $sk => $s) {
        foreach ($s['lines'] as $l) {
            $cmpMap[$sk][$l['id']] = $l;
        }
    }
}

$cols = $cmp ? 4 : 2;
function nvr_pl_row(string $class, string $label, float $cur, ?float $prev, bool $hasCmp, bool $isCost = false): string
{
    $h = '<tr class="' . $class . '"><td>' . $label . '</td><td class="num' . ($cur < 0 ? ' neg' : '') . '">' . nvr_money($cur, $class !== 'acct') . '</td>';
    if ($hasCmp) {
        $prev = (float)$prev;
        $ch = nvr_change($cur, $prev);
        $good = $ch === null ? '' : (($isCost ? $ch <= 0 : $ch >= 0) ? 'up' : 'down');
        $h .= '<td class="num muted">' . nvr_money($prev) . '</td><td class="num ' . $good . '">' . ($ch === null ? '–' : ($ch > 0 ? '+' : '') . number_format($ch, 1) . '%') . '</td>';
    }
    return $h . '</tr>';
}

nvr_header([
    'page' => 'reports/income_statement',
    'title' => 'Profit and Loss',
    'icon' => 'fas fa-chart-line',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'compare' => ['none' => 'No comparison', 'previous_period' => 'Previous period', 'previous_year' => 'Same period last year'],
    'compareValue' => $compare,
    'subtitle' => 'For the period ' . nvr_nice_date($from) . ' to ' . nvr_nice_date($to),
]);
$hasData = $pl['total_income'] != 0.0 || $pl['total_expenses'] != 0.0 || ($cmp && ($cmp['total_income'] != 0.0 || $cmp['total_expenses'] != 0.0));
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Income</div><div class="v"><?= nvr_money($pl['total_income'], false) ?></div><div class="s">Trading + other income</div></div>
    <div class="nvr-stat"><div class="k">Gross profit</div><div class="v<?= $pl['gross_profit'] < 0 ? ' neg' : '' ?>"><?= nvr_money($pl['gross_profit'], false) ?></div><div class="s">Margin <?= nvr_pct($pl['gross_margin']) ?></div></div>
    <div class="nvr-stat"><div class="k">Expenses</div><div class="v"><?= nvr_money($pl['total_expenses'], false) ?></div><div class="s">Cost of sales + operating</div></div>
    <div class="nvr-stat"><div class="k">Net profit</div><div class="v<?= $pl['net_profit'] < 0 ? ' neg' : '' ?>"><?= nvr_money($pl['net_profit'], false) ?></div><div class="s">Margin <?= nvr_pct($pl['net_margin']) ?><?php if ($cmp): $c = nvr_change($pl['net_profit'], $cmp['net_profit']); ?> · <?= $c === null ? 'no prior figure' : (($c > 0 ? '+' : '') . number_format($c, 1) . '% vs comparison') ?><?php endif; ?></div></div>
</div>

<?php if (!$hasData): ?>
    <div class="nvr-empty"><i class="fas fa-chart-line"></i><b>No income or expenses in this period</b>Check the dates, or make sure revenue and expense accounts have the right account type.</div>
<?php else: ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead>
        <tr>
            <th></th>
            <th class="num"><?= date('j M y', strtotime($from)) ?> – <?= date('j M y', strtotime($to)) ?></th>
            <?php if ($cmp): ?><th class="num"><?= htmlspecialchars($cmpLabel) ?></th><th class="num">Change</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $render = function (string $sk, bool $isCost) use ($pl, $cmp, $cmpMap, $cols, $from, $to) {
        $s = $pl['sections'][$sk];
        $ids = [];
        foreach ($s['lines'] as $l) { $ids[$l['id']] = $l; }
        foreach ($cmpMap[$sk] ?? [] as $id => $l) { if (!isset($ids[$id])) { $ids[$id] = ['id' => $id, 'name' => $l['name'], 'code' => $l['code'], 'amount' => 0.0]; } }
        if (!$ids) { return; }
        echo '<tr class="sec"><td colspan="' . $cols . '">' . htmlspecialchars($s['label']) . '</td></tr>';
        foreach ($ids as $id => $l) {
            echo nvr_pl_row('acct', nvr_gl_link($id, $from, $to, $l['name']), (float)$l['amount'], $cmp ? (float)($cmpMap[$sk][$id]['amount'] ?? 0) : null, (bool)$cmp, $isCost);
        }
        echo nvr_pl_row('sub', 'Total ' . htmlspecialchars(strtolower($s['label'])), $s['total'], $cmp ? $cmp['sections'][$sk]['total'] : null, (bool)$cmp, $isCost);
    };
    $render('revenue', false);
    $render('direct_costs', true);
    echo nvr_pl_row('key', 'Gross profit', $pl['gross_profit'], $cmp ? $cmp['gross_profit'] : null, (bool)$cmp);
    $render('other_income', false);
    $render('expense', true);
    echo nvr_pl_row('grand', 'Net profit', $pl['net_profit'], $cmp ? $cmp['net_profit'] : null, (bool)$cmp);
    ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Accounts are grouped by their account type (Revenue, Other Income, Direct Costs, Expense). Click any account to see its transactions.']);
