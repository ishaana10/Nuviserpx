<?php
/**
 * Balance sheet: assets, liabilities and equity at a date. Profit for the current
 * financial year and earlier years is rolled into equity, so the sheet balances.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$as_of = nvr_date($_GET['as_of'] ?? null, date('Y-m-d'));
$compare = in_array($_GET['compare'] ?? '', ['last_month', 'last_year'], true) ? $_GET['compare'] : 'none';
$yem = nvr_year_end_month();
$ledger = nvr_ledger();

$build = function (string $date) use ($ledger, $yem): array {
    return LedgerService::balanceSheet($ledger->balances(LedgerService::fyStart($date, $yem), $date));
};
$bs = $build($as_of);

$cmp = null;
$cmpDate = null;
if ($compare !== 'none') {
    $cmpDate = $compare === 'last_year'
        ? date('Y-m-d', strtotime($as_of . ' -1 year'))
        : date('Y-m-d', strtotime('last day of previous month', strtotime($as_of)));
    $cmp = $build($cmpDate);
}

$cmpMap = [];
if ($cmp) {
    foreach ($cmp['sections'] as $sk => $s) {
        foreach ($s['lines'] as $l) {
            $cmpMap[$sk][$l['id'] ?? $l['name']] = $l['amount'];
        }
    }
}
$cols = $cmp ? 3 : 2;
$fy_start = LedgerService::fyStart($as_of, $yem);

nvr_header([
    'page' => 'reports/balance_sheet',
    'title' => 'Balance Sheet',
    'icon' => 'fas fa-landmark',
    'mode' => 'asat',
    'asat' => $as_of,
    'compare' => ['none' => 'No comparison', 'last_month' => 'End of previous month', 'last_year' => 'Same date last year'],
    'compareValue' => $compare,
    'subtitle' => 'As at ' . nvr_nice_date($as_of),
]);

$row = function (string $class, string $label, float $cur, ?float $prev) use ($cmp): string {
    return '<tr class="' . $class . '"><td>' . $label . '</td><td class="num' . ($cur < 0 ? ' neg' : '') . '">' . nvr_money($cur, $class !== 'acct' && $class !== 'computed acct') . '</td>'
        . ($cmp ? '<td class="num muted">' . nvr_money((float)$prev) . '</td>' : '') . '</tr>';
};
$render = function (array $keys, bool $subtotals = true) use ($bs, $cmp, $cmpMap, $cols, $row, $fy_start, $as_of): void {
    foreach ($keys as $sk) {
        $s = $bs['sections'][$sk];
        $lines = [];
        foreach ($s['lines'] as $l) { $lines[$l['id'] ?? $l['name']] = $l; }
        foreach ($cmpMap[$sk] ?? [] as $k => $amt) {
            if (!isset($lines[$k])) {
                foreach ($cmp['sections'][$sk]['lines'] as $cl) {
                    if (($cl['id'] ?? $cl['name']) === $k) { $lines[$k] = array_merge($cl, ['amount' => 0.0]); }
                }
            }
        }
        if (!$lines) { continue; }
        echo '<tr class="sec"><td colspan="' . $cols . '">' . htmlspecialchars($s['label']) . '</td></tr>';
        foreach ($lines as $k => $l) {
            $label = !empty($l['computed']) ? htmlspecialchars($l['name']) : nvr_gl_link($l['id'], $fy_start, $as_of, $l['name']);
            echo $row(!empty($l['computed']) ? 'computed acct' : 'acct', $label, (float)$l['amount'], $cmp ? (float)($cmpMap[$sk][$k] ?? 0) : null);
        }
        if ($subtotals) {
            echo $row('sub', 'Total ' . htmlspecialchars(strtolower($s['label'])), $s['total'], $cmp ? $cmp['sections'][$sk]['total'] : null);
        }
    }
};
$empty = $bs['total_assets'] == 0.0 && $bs['total_liabilities'] == 0.0 && $bs['total_equity'] == 0.0;
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Total assets</div><div class="v"><?= nvr_money($bs['total_assets'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Total liabilities</div><div class="v"><?= nvr_money($bs['total_liabilities'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Net assets</div><div class="v<?= $bs['net_assets'] < 0 ? ' neg' : '' ?>"><?= nvr_money($bs['net_assets'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Current year earnings</div><div class="v<?= $bs['current_year_earnings'] < 0 ? ' neg' : '' ?>"><?= nvr_money($bs['current_year_earnings'], false) ?></div><div class="s">Since <?= nvr_nice_date($fy_start) ?></div></div>
</div>

<?php if ($empty): ?>
    <div class="nvr-empty"><i class="fas fa-landmark"></i><b>Nothing to report yet</b>No posted balances up to <?= nvr_nice_date($as_of) ?>.</div>
<?php else: ?>
<div class="nvr-checks"><?= nvr_check($bs['balanced'], $bs['difference'], 'Net assets equal equity', 'Does not balance') ?></div>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead>
        <tr>
            <th></th>
            <th class="num"><?= date('j M Y', strtotime($as_of)) ?></th>
            <?php if ($cmp): ?><th class="num"><?= date('j M Y', strtotime($cmpDate)) ?></th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <tr class="sec"><td colspan="<?= $cols ?>" style="font-size:.9rem">Assets</td></tr>
        <?php $render(['bank', 'current_asset', 'fixed_asset', 'non_current_asset']); ?>
        <?= $row('key', 'Total assets', $bs['total_assets'], $cmp ? $cmp['total_assets'] : null) ?>

        <tr class="sec"><td colspan="<?= $cols ?>" style="font-size:.9rem">Liabilities</td></tr>
        <?php $render(['current_liability', 'non_current_liability']); ?>
        <?= $row('key', 'Total liabilities', $bs['total_liabilities'], $cmp ? $cmp['total_liabilities'] : null) ?>

        <?= $row('key', 'Net assets', $bs['net_assets'], $cmp ? $cmp['net_assets'] : null) ?>

        <?php $render(['equity'], false); ?>
        <?= $row('grand', 'Total equity', $bs['total_equity'], $cmp ? $cmp['total_equity'] : null) ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer([
    'Financial year starts ' . nvr_nice_date($fy_start) . ' (year end month is set in Financial Settings). Profit before that date is shown as retained earnings.',
]);
