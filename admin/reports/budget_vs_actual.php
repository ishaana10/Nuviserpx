<?php
/**
 * Budget against actual for income and expense accounts over a period.
 * Budgets are monthly (set on the Budgets page); a part month counts as the whole month.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\BudgetService;
use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['fy_to_date'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['fy_to_date'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$tid = (int)$_settings->active_tenant_id();
$budget = BudgetService::forRange($conn, $tid, $from, $to);
$bva = LedgerService::budgetVsActual(nvr_ledger()->balances($from, $to, true), $budget);
$p = $bva['profit'];
$hasBudget = (bool)array_filter($budget);

function nvr_bva_cell(float $v, bool $good): string
{
    return '<td class="num ' . ($v == 0.0 ? '' : ($good ? 'up' : 'down')) . '">' . nvr_money($v) . '</td>';
}

nvr_header([
    'page' => 'reports/budget_vs_actual',
    'title' => 'Budget vs Actual',
    'icon' => 'fas fa-bullseye',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => 'For the period ' . nvr_nice_date($from) . ' to ' . nvr_nice_date($to),
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Actual profit</div><div class="v<?= $p['actual'] < 0 ? ' neg' : '' ?>"><?= nvr_money($p['actual'], false) ?></div><div class="s">From the ledger</div></div>
    <div class="nvr-stat"><div class="k">Budgeted profit</div><div class="v<?= $p['budget'] < 0 ? ' neg' : '' ?>"><?= nvr_money($p['budget'], false) ?></div><div class="s"><?= $hasBudget ? 'From the budget' : 'No budget set' ?></div></div>
    <div class="nvr-stat"><div class="k">Variance</div><div class="v<?= $p['variance'] < 0 ? ' neg' : '' ?>"><?= nvr_money($p['variance'], false) ?></div><div class="s"><?= $p['favourable'] ? 'Favourable' : 'Unfavourable' ?></div></div>
</div>

<?php if (!$hasBudget): ?>
    <div class="nvr-empty"><i class="fas fa-bullseye"></i><b>No budget for this period</b>
        <?php if ($_settings->can_access('accounts_edit')): ?><a href="./?page=budgets&fy=<?= urlencode(LedgerService::fyStart($from, nvr_year_end_month())) ?>">Set a budget</a> to compare it with actual results.<?php else: ?>Ask someone with access to set a budget.<?php endif; ?></div>
<?php endif; ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th></th><th class="num">Actual</th><th class="num">Budget</th><th class="num">Variance</th><th class="num">Variance %</th></tr></thead>
    <tbody>
    <?php foreach (['revenue', 'direct_costs', 'other_income', 'expense'] as $sk): $s = $bva['sections'][$sk]; if (!$s['lines']) { continue; } ?>
        <tr class="sec"><td colspan="5"><?= htmlspecialchars($s['label']) ?></td></tr>
        <?php foreach ($s['lines'] as $l): ?>
        <tr class="acct">
            <td><?= nvr_gl_link($l['id'], $from, $to, $l['name']) ?></td>
            <td class="num"><?= nvr_money($l['actual']) ?></td>
            <td class="num muted"><?= nvr_money($l['budget']) ?></td>
            <?= nvr_bva_cell($l['variance'], $l['favourable']) ?>
            <td class="num <?= $l['variance_pct'] === null ? 'muted' : ($l['favourable'] ? 'up' : 'down') ?>"><?= $l['variance_pct'] === null ? '–' : ($l['variance_pct'] > 0 ? '+' : '') . number_format($l['variance_pct'], 1) . '%' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="sub"><td>Total <?= htmlspecialchars(strtolower($s['label'])) ?></td><td class="num"><?= nvr_money($s['actual'], false) ?></td><td class="num"><?= nvr_money($s['budget'], false) ?></td><td class="num"><?= nvr_money($s['actual'] - $s['budget'], false) ?></td><td></td></tr>
    <?php endforeach; ?>
        <tr class="grand"><td>Net profit</td><td class="num"><?= nvr_money($p['actual'], false) ?></td><td class="num"><?= nvr_money($p['budget'], false) ?></td><td class="num"><?= nvr_money($p['variance'], false) ?></td><td></td></tr>
    </tbody>
</table>
</div>
<?php
nvr_footer(['Variance is actual minus budget. Green is favourable: income above budget or an expense below it. Budgets are monthly; a part month counts as the whole month.']);
