<?php
/**
 * Inventory valuation: stock on hand valued at cost, per product and in total —
 * the figure that should tie to the inventory asset account on the balance sheet.
 *
 * Reflects current on-hand quantity (product_list.stock_quantity) at current
 * weighted-average cost (product_list.cost_price). Products with no stock are
 * hidden unless "show zero-stock" is ticked.
 */
require_once __DIR__ . '/_kit.php';

$tid = (int)$_settings->active_tenant_id();
$show_zero = ($_GET['show_zero'] ?? '') === '1';
$group_cat = ($_GET['group'] ?? '1') === '1';

$rows = [];
$stmt = $conn->prepare("SELECT id, product_code, name, category, unit, stock_quantity, cost_price, selling_price
    FROM product_list
    WHERE tenant_id = ? AND delete_flag = 0" . ($show_zero ? '' : ' AND stock_quantity <> 0') . "
    ORDER BY category IS NULL, category, name");
if ($stmt) {
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// Build totals and category groups.
$groups = [];
$T = ['qty' => 0.0, 'cost_val' => 0.0, 'retail_val' => 0.0, 'lines' => 0, 'skus' => 0];
foreach ($rows as $r) {
    $qty = (float)$r['stock_quantity'];
    $cost = (float)$r['cost_price'];
    $sell = (float)$r['selling_price'];
    $cost_val = round($qty * $cost, 2);
    $retail_val = round($qty * $sell, 2);
    $cat = trim((string)($r['category'] ?? '')) !== '' ? (string)$r['category'] : 'Uncategorised';

    $groups[$cat] ??= ['name' => $cat, 'items' => [], 'cost_val' => 0.0, 'retail_val' => 0.0, 'qty' => 0.0];
    $groups[$cat]['items'][] = $r + ['cost_val' => $cost_val, 'retail_val' => $retail_val];
    $groups[$cat]['cost_val'] += $cost_val;
    $groups[$cat]['retail_val'] += $retail_val;
    $groups[$cat]['qty'] += $qty;

    $T['qty'] += $qty;
    $T['cost_val'] += $cost_val;
    $T['retail_val'] += $retail_val;
    $T['skus']++;
}
$T['cost_val'] = round($T['cost_val'], 2);
$T['retail_val'] = round($T['retail_val'], 2);
$potential_margin = round($T['retail_val'] - $T['cost_val'], 2);
// Sort categories by value, biggest first.
uasort($groups, fn ($a, $b) => $b['cost_val'] <=> $a['cost_val']);
$top = null;
foreach ($groups as $g) { if ($top === null || $g['cost_val'] > $top['cost_val']) { $top = $g; } }

$qfmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');

nvr_header([
    'page' => 'reports/inventory_valuation',
    'title' => 'Inventory Valuation',
    'icon' => 'fas fa-warehouse',
    'mode' => 'live',
    'extra' =>'<div class="nvr-field"><label>&nbsp;</label><label class="nvr-toggle" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" name="show_zero" value="1"' . ($show_zero ? ' checked' : '') . '> Include zero-stock</label></div>'
             . '<div class="nvr-field"><label>&nbsp;</label><label class="nvr-toggle" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" name="group" value="1"' . ($group_cat ? ' checked' : '') . '> Group by category</label></div>',
    'subtitle' => 'Stock on hand at cost, as it stands today',
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Stock value (at cost)</div><div class="v"><?= nvr_money($T['cost_val'], false) ?></div><div class="s"><?= $T['skus'] ?> product<?= $T['skus'] === 1 ? '' : 's' ?> · <?= $qfmt($T['qty']) ?> units</div></div>
    <div class="nvr-stat"><div class="k">Retail value</div><div class="v"><?= nvr_money($T['retail_val'], false) ?></div><div class="s">At current selling prices</div></div>
    <div class="nvr-stat"><div class="k">Potential margin</div><div class="v<?= $potential_margin < 0 ? ' neg' : '' ?>"><?= nvr_money($potential_margin, false) ?></div><div class="s"><?= $T['retail_val'] > 0 ? number_format($potential_margin / $T['retail_val'] * 100, 0) . '% of retail' : '' ?></div></div>
    <div class="nvr-stat"><div class="k">Largest category</div><div class="v" style="font-size:1rem;line-height:1.5"><?= $top ? htmlspecialchars($top['name']) : '–' ?></div><div class="s"><?= $top && $T['cost_val'] > 0 ? number_format($top['cost_val'] / $T['cost_val'] * 100, 0) . '% of value' : '' ?></div></div>
</div>

<?php if (!$rows): ?>
    <div class="nvr-empty"><i class="fas fa-box-open"></i><b>No stock to value</b><?= $show_zero ? 'No products found.' : 'No product has stock on hand. Tick “Include zero-stock” to list them anyway.' ?></div>
<?php else: ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Unit cost</th><th class="num">Cost value</th><th class="num">Unit price</th><th class="num">Retail value</th></tr></thead>
    <tbody>
    <?php
    $render_item = function (array $it) use ($qfmt) {
        $qty = (float)$it['stock_quantity'];
        ?>
        <tr class="acct">
            <td><a class="nvr-drill" href="./?page=stock_adjustments&product_id=<?= (int)$it['id'] ?>"><?= htmlspecialchars($it['name']) ?></a>
                <span class="muted" style="font-size:.78rem"> <?= htmlspecialchars((string)$it['product_code']) ?><?= $it['unit'] ? ' · ' . htmlspecialchars((string)$it['unit']) : '' ?></span></td>
            <td class="num<?= $qty < 0 ? ' neg' : '' ?>"><?= $qfmt($qty) ?></td>
            <td class="num"><?= nvr_money($it['cost_price']) ?></td>
            <td class="num"><b><?= nvr_money($it['cost_val']) ?></b></td>
            <td class="num muted"><?= nvr_money($it['selling_price']) ?></td>
            <td class="num muted"><?= nvr_money($it['retail_val']) ?></td>
        </tr>
        <?php
    };

    if ($group_cat):
        foreach ($groups as $g): ?>
        <tr class="sub"><td colspan="3"><b><?= htmlspecialchars($g['name']) ?></b> <span class="muted" style="font-size:.78rem"><?= count($g['items']) ?> item<?= count($g['items']) === 1 ? '' : 's' ?></span></td>
            <td class="num"><b><?= nvr_money($g['cost_val']) ?></b></td><td></td><td class="num muted"><?= nvr_money($g['retail_val']) ?></td></tr>
        <?php foreach ($g['items'] as $it) { $render_item($it); } ?>
        <?php endforeach;
    else:
        foreach ($rows as $r) {
            $render_item($r + ['cost_val' => round((float)$r['stock_quantity'] * (float)$r['cost_price'], 2), 'retail_val' => round((float)$r['stock_quantity'] * (float)$r['selling_price'], 2)]);
        }
    endif;
    ?>
    </tbody>
    <tfoot><tr class="grand"><td>Total</td><td class="num"><?= $qfmt($T['qty']) ?></td><td></td><td class="num"><?= nvr_money($T['cost_val'], false) ?></td><td></td><td class="num"><?= nvr_money($T['retail_val'], false) ?></td></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Valued at weighted-average cost (product cost price) on current quantity on hand. This is the live figure — it reflects stock as it stands today, not a past date. It should agree with your inventory asset account in the <a href="./?page=reports/balance_sheet">balance sheet</a>.'], false);
