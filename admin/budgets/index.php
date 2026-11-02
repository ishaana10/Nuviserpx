<?php
/**
 * Budgets: a monthly plan for every income and expense account, per financial year.
 * Type a yearly figure to spread it evenly, or fill the months one by one.
 * Compared with actual results on Reports > Budget vs Actual.
 */
use App\Services\BudgetService;
use App\Services\LedgerService;

$tid = (int)$_settings->active_tenant_id();
$yem = (int)($_settings->info('fiscal_year_end_month') ?: 12);
$yem = $yem >= 1 && $yem <= 12 ? $yem : 12;
$fy = (isset($_GET['fy']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['fy']) && strtotime((string)$_GET['fy']))
    ? LedgerService::fyStart((string)$_GET['fy'], $yem) : LedgerService::fyStart(date('Y-m-d'), $yem);
$months = BudgetService::fyMonths($fy);
$prevFy = LedgerService::fyStart(date('Y-m-d', strtotime($fy . ' -1 day')), $yem);
$nextFy = date('Y-m-d', strtotime($fy . ' +1 year'));
$canEdit = (bool)$_settings->can_access('accounts_edit');

$accounts = [];
$stmt = $conn->prepare("SELECT id, code, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 ORDER BY code IS NULL, CAST(code AS UNSIGNED), name");
$stmt->bind_param('i', $tid);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
    $c = LedgerService::classify($a['type']);
    if (in_array($c['class'], ['income', 'expense'], true)) {
        $a['class'] = $c['class'];
        $accounts[] = $a;
    }
}
$grid = BudgetService::grid($conn, $tid, $fy);
$fyEnd = date('Y-m-d', strtotime($fy . ' +1 year -1 day'));
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
        <h3 class="card-title font-weight-bold"><i class="fas fa-bullseye text-primary mr-2"></i> Budget — <?= date('j M Y', strtotime($fy)) ?> to <?= date('j M Y', strtotime($fyEnd)) ?></h3>
        <div class="card-tools">
            <a class="btn btn-sm btn-default" href="./?page=budgets&fy=<?= urlencode($prevFy) ?>"><i class="fas fa-chevron-left"></i> Previous year</a>
            <a class="btn btn-sm btn-default" href="./?page=budgets&fy=<?= urlencode($nextFy) ?>">Next year <i class="fas fa-chevron-right"></i></a>
            <a class="btn btn-sm btn-info" href="./?page=reports/budget_vs_actual&from=<?= urlencode($fy) ?>&to=<?= urlencode(min($fyEnd, date('Y-m-d'))) ?>"><i class="fas fa-chart-bar mr-1"></i> Compare with actual</a>
            <?php if ($canEdit): ?><button type="submit" form="budget-form" class="btn btn-sm btn-primary"><i class="fas fa-save mr-1"></i> Save budget</button><?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$accounts): ?>
            <p class="text-muted mb-0">There are no income or expense accounts yet. Add them in the Chart of Accounts first.</p>
        <?php else: ?>
        <p class="text-muted text-sm">Enter amounts as positive numbers: income you expect to earn, and expenses you expect to incur. Leave a row blank for no budget. A yearly amount is spread evenly over the months when the months are left empty.</p>
        <form id="budget-form" autocomplete="off">
            <input type="hidden" name="fy_start" value="<?= htmlspecialchars($fy) ?>">
            <div class="table-responsive">
            <table class="table table-sm table-bordered" style="min-width:1250px">
                <thead class="bg-gradient-navy text-white">
                    <tr>
                        <th style="min-width:210px">Account</th>
                        <th class="text-right" style="width:110px">Yearly</th>
                        <?php foreach ($months as $m): ?><th class="text-right" style="width:84px"><?= date('M y', strtotime($m)) ?></th><?php endforeach; ?>
                        <th class="text-right" style="width:100px">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php $lastClass = ''; foreach ($accounts as $a):
                    $row = $grid[(int)$a['id']] ?? [];
                    if ($a['class'] !== $lastClass): $lastClass = $a['class']; ?>
                    <tr class="bg-light"><td colspan="15" class="font-weight-bold"><?= $a['class'] === 'income' ? 'Income' : 'Expenses' ?></td></tr>
                    <?php endif; ?>
                    <tr data-acc="<?= (int)$a['id'] ?>">
                        <td><?= htmlspecialchars(trim(($a['code'] ? $a['code'] . ' · ' : '') . $a['name'])) ?></td>
                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-right bd-annual" name="annual[<?= (int)$a['id'] ?>]" value="" placeholder="Spread" <?= $canEdit ? '' : 'disabled' ?>></td>
                        <?php foreach ($months as $i => $m): $v = $row[$m] ?? 0.0; ?>
                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-right bd-cell" name="amount[<?= (int)$a['id'] ?>][<?= $i ?>]" value="<?= $v > 0 ? htmlspecialchars(number_format($v, 2, '.', '')) : '' ?>" <?= $canEdit ? '' : 'disabled' ?>></td>
                        <?php endforeach; ?>
                        <td class="text-right font-weight-bold bd-total">0.00</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
$(function () {
    function rowTotal($tr) {
        var t = 0;
        $tr.find('.bd-cell').each(function () { var v = parseFloat(String($(this).val()).replace(/,/g, '')); if (!isNaN(v)) { t += v; } });
        $tr.find('.bd-total').text(t.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }
    $('#budget-form tbody tr[data-acc]').each(function () { rowTotal($(this)); });
    $('#budget-form').on('input', '.bd-cell', function () { rowTotal($(this).closest('tr')); });
    // A yearly figure fills the empty months with an even split (the last month takes the rounding).
    $('#budget-form').on('change', '.bd-annual', function () {
        var $tr = $(this).closest('tr'), cents = Math.round((parseFloat(String($(this).val()).replace(/,/g, '')) || 0) * 100);
        if (cents <= 0) { return; }
        var each = Math.floor(cents / 12);
        $tr.find('.bd-cell').each(function (i) { $(this).val(((i === 11 ? cents - each * 11 : each) / 100).toFixed(2)); });
        rowTotal($tr);
    });
    $('#budget-form').on('submit', function (e) {
        e.preventDefault();
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=save_budget', $(this).serialize(), null, 'json').done(function (resp) {
            if (resp && resp.status === 'success') { location.reload(); return; }
            alert_toast((resp && resp.msg) || 'Could not save the budget.', 'error');
            end_loader();
        }).fail(function () { alert_toast('Could not save the budget.', 'error'); end_loader(); });
    });
});
</script>
