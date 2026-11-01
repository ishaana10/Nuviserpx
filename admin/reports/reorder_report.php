<?php
/**
 * Reorder report: products at or below their reorder level, with a suggested
 * top-up quantity and the cost of restocking. Drives purchasing decisions and
 * links straight to a new purchase order.
 *
 * "Suggested order" tops stock back up to twice the reorder level (a simple,
 * transparent rule); negative on-hand is treated as zero for the suggestion.
 */
require_once __DIR__ . '/_kit.php';

$tid = (int)$_settings->active_tenant_id();
$include_out = ($_GET['include_out'] ?? '1') === '1'; // include out-of-stock (default on)

// Candidates: stock at or below reorder level, and reorder level is meaningful (> 0).
$rows = [];
$stmt = $conn->prepare("SELECT id, product_code, name, category, unit, stock_quantity, reorder_level, cost_price
    FROM product_list
    WHERE tenant_id = ? AND delete_flag = 0 AND status = 1
      AND reorder_level > 0 AND stock_quantity <= reorder_level
    ORDER BY (stock_quantity <= 0) DESC, (reorder_level - stock_quantity) DESC, name");
if ($stmt) {
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

$items = [];
$T = ['n' => 0, 'out' => 0, 'order_qty' => 0.0, 'order_val' => 0.0];
foreach ($rows as $r) {
    $qty = (float)$r['stock_quantity'];
    $reorder = (float)$r['reorder_level'];
    $cost = (float)$r['cost_price'];
    $is_out = $qty <= 0;
    if ($is_out && !$include_out) {
        // still counted below only if shown; skip entirely when hidden
    }
    $target = $reorder * 2; // simple top-up target
    $suggest = round(max(0.0, $target - max(0.0, $qty)), 2);
    $order_val = round($suggest * $cost, 2);

    if ($is_out && !$include_out) {
        continue;
    }
    $items[] = $r + ['is_out' => $is_out, 'suggest' => $suggest, 'order_val' => $order_val];
    $T['n']++;
    if ($is_out) { $T['out']++; }
    $T['order_qty'] += $suggest;
    $T['order_val'] += $order_val;
}
$T['order_val'] = round($T['order_val'], 2);
$qfmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');

nvr_header([
    'page' => 'reports/reorder_report',
    'title' => 'Reorder Report',
    'icon' => 'fas fa-dolly-flatbed',
    'mode' => 'live',
    'extra' => '<div class="nvr-field"><label>&nbsp;</label><label class="nvr-toggle" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" name="include_out" value="1"' . ($include_out ? ' checked' : '') . '> Include out-of-stock</label></div>',
    'subtitle' => 'Products at or below their reorder level',
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Needs reordering</div><div class="v"><?= (int)$T['n'] ?></div><div class="s">product<?= $T['n'] === 1 ? '' : 's' ?> at or below reorder level</div></div>
    <div class="nvr-stat"><div class="k">Out of stock</div><div class="v<?= $T['out'] > 0 ? ' neg' : '' ?>"><?= (int)$T['out'] ?></div><div class="s">zero or negative on hand</div></div>
    <div class="nvr-stat"><div class="k">Suggested order qty</div><div class="v"><?= $qfmt($T['order_qty']) ?></div><div class="s">units to top up</div></div>
    <div class="nvr-stat"><div class="k">Cost to restock</div><div class="v"><?= nvr_money($T['order_val'], false) ?></div><div class="s">at current cost price</div></div>
</div>

<?php if (!$items): ?>
    <div class="nvr-empty"><i class="fas fa-check-circle"></i><b>Everything is well stocked</b>No product is at or below its reorder level<?= $include_out ? '' : ' (out-of-stock items are hidden — tick the box to show them)' ?>.</div>
<?php else: ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Reorder at</th><th class="num">Suggested order</th><th class="num">Cost to restock</th><th class="num d-print-none">&nbsp;</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
        <tr class="acct">
            <td>
                <a class="nvr-drill" href="./?page=stock_adjustments&product_id=<?= (int)$it['id'] ?>"><?= htmlspecialchars($it['name']) ?></a>
                <span class="muted" style="font-size:.78rem"> <?= htmlspecialchars((string)$it['product_code']) ?><?= $it['category'] ? ' · ' . htmlspecialchars((string)$it['category']) : '' ?></span>
                <?php if ($it['is_out']): ?> <span class="nvr-pill" style="background:#fef2f2;color:#b91c1c">OUT OF STOCK</span><?php endif; ?>
            </td>
            <td class="num<?= (float)$it['stock_quantity'] <= 0 ? ' neg' : '' ?>"><?= $qfmt($it['stock_quantity']) ?> <span class="muted" style="font-size:.72rem"><?= htmlspecialchars((string)$it['unit']) ?></span></td>
            <td class="num muted"><?= $qfmt($it['reorder_level']) ?></td>
            <td class="num"><b><?= $qfmt($it['suggest']) ?></b></td>
            <td class="num"><?= nvr_money($it['order_val']) ?></td>
            <td class="num d-print-none"><a class="btn btn-xs btn-outline-primary" href="./?page=purchases/manage_po"><i class="fas fa-shopping-bag"></i> PO</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="grand"><td>Total</td><td></td><td></td><td class="num"><?= $qfmt($T['order_qty']) ?></td><td class="num"><?= nvr_money($T['order_val'], false) ?></td><td class="d-print-none"></td></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Suggested order tops each product back up to twice its reorder level, valued at current cost price. Raise a <a href="./?page=purchases/manage_po">purchase order</a> to restock.'], false);
