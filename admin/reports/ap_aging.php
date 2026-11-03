<?php
/**
 * Aged payables: what the business owes each vendor, split by how long it has been due.
 * Draft and cancelled bills are not payables and are excluded.
 * Payables mirror of ar_aging.php, sourced from vendor_bill_list.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$as_of = nvr_date($_GET['as_of'] ?? null, date('Y-m-d'));
$detail = ($_GET['detail'] ?? '') === '1';
$tid = (int)$_settings->active_tenant_id();

$rows = [];
$stmt = $conn->prepare("SELECT b.id, b.bill_no, b.bill_date, b.due_date, b.total, b.balance,
        p.id vendor_id, p.name vendor_name, p.party_code
    FROM vendor_bill_list b INNER JOIN party_list p ON p.id = b.vendor_id
    WHERE b.tenant_id = ? AND b.status NOT IN ('draft','pending_approval','cancelled') AND b.balance > 0 AND b.bill_date <= ?
    ORDER BY p.name, b.bill_date");
if ($stmt) {
    $stmt->bind_param('is', $tid, $as_of);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
$ap = LedgerService::agedPayables($rows, $as_of);
$t = $ap['totals'];
$buckets = LedgerService::AGING_BUCKETS;
$overdue = $t['total'] - $t['current'];

nvr_header([
    'page' => 'reports/ap_aging',
    'title' => 'Aged Payables',
    'icon' => 'fas fa-hourglass-half',
    'mode' => 'asat',
    'asat' => $as_of,
    'extra' => '<div class="nvr-field"><label>&nbsp;</label><label class="nvr-toggle" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" name="detail" value="1"' . ($detail ? ' checked' : '') . '> Show bills</label></div>',
    'subtitle' => 'As at ' . nvr_nice_date($as_of),
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Total owing</div><div class="v"><?= nvr_money($t['total'], false) ?></div><div class="s"><?= count($ap['vendors']) ?> vendor<?= count($ap['vendors']) === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k">Not yet due</div><div class="v"><?= nvr_money($t['current'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Overdue</div><div class="v<?= $overdue > 0 ? ' neg' : '' ?>"><?= nvr_money($overdue, false) ?></div><div class="s"><?= $t['total'] > 0 ? number_format($overdue / $t['total'] * 100, 0) . '% of the total' : '' ?></div></div>
    <div class="nvr-stat"><div class="k">Over 90 days</div><div class="v<?= $t['older'] > 0 ? ' neg' : '' ?>"><?= nvr_money($t['older'], false) ?></div><div class="s">Pay these soon to protect supplier terms</div></div>
</div>

<?php if (!$ap['vendors']): ?>
    <div class="nvr-empty"><i class="fas fa-check-circle"></i><b>Nothing owing</b>No vendor has an unpaid bill dated on or before <?= nvr_nice_date($as_of) ?>.</div>
<?php else: ?>
<?php if ($t['total'] > 0): ?>
<div style="display:flex;height:10px;border-radius:999px;overflow:hidden;margin:-4px 0 20px;background:#eef2f7" title="Share of the total by age">
    <?php foreach (['current' => '#16a34a', 'd30' => '#f59e0b', 'd60' => '#f97316', 'd90' => '#ef4444', 'older' => '#991b1b'] as $k => $c): if ($t[$k] <= 0) { continue; } ?>
        <span style="width:<?= round($t[$k] / $t['total'] * 100, 2) ?>%;background:<?= $c ?>" title="<?= $buckets[$k] ?>: <?= number_format($t[$k], 2) ?>"></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th>Vendor</th><?php foreach ($buckets as $lbl): ?><th class="num"><?= $lbl ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($ap['vendors'] as $v): ?>
        <tr class="<?= $detail ? 'sub' : 'acct' ?>">
            <td><b><?= htmlspecialchars($v['name']) ?></b> <span class="muted" style="font-size:.78rem"><?= htmlspecialchars($v['code']) ?></span><?php if ($v['oldest'] > 90): ?> <span class="nvr-pill" style="background:#fef2f2;color:#b91c1c"><?= $v['oldest'] ?> days</span><?php endif; ?></td>
            <?php foreach ($buckets as $k => $_): ?><td class="num<?= $k !== 'current' && $v[$k] > 0 ? ' neg' : '' ?>"><?= nvr_money($v[$k]) ?></td><?php endforeach; ?>
            <td class="num"><b><?= nvr_money($v['total']) ?></b></td>
        </tr>
        <?php if ($detail): foreach ($v['bills'] as $b): ?>
        <tr class="acct">
            <td><a class="nvr-drill" href="./?page=purchases/manage_bill&id=<?= $b['id'] ?>"><?= htmlspecialchars($b['bill_no']) ?></a>
                <span class="muted" style="font-size:.78rem"> · dated <?= date('j M Y', strtotime($b['bill_date'])) ?><?= $b['due_date'] ? ' · due ' . date('j M Y', strtotime($b['due_date'])) : '' ?><?= $b['days'] ? ' · ' . $b['days'] . ' days overdue' : '' ?></span></td>
            <?php foreach ($buckets as $k => $_): ?><td class="num muted"><?= $b['bucket'] === $k ? nvr_money($b['balance']) : '' ?></td><?php endforeach; ?>
            <td class="num muted"><?= nvr_money($b['balance']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="grand"><td>Total</td><?php foreach ($buckets as $k => $_): ?><td class="num"><?= nvr_money($t[$k], false) ?></td><?php endforeach; ?><td class="num"><?= nvr_money($t['total'], false) ?></td></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Aged by due date (or bill date when there is none). Balances are as they stand today, for bills dated on or before the selected date.'], false);
