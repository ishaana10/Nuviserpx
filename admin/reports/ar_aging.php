<?php
/**
 * Aged receivables: what each customer owes, split by how long it has been due.
 * Draft and cancelled invoices are not receivables and are excluded.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\LedgerService;

$as_of = nvr_date($_GET['as_of'] ?? null, date('Y-m-d'));
$detail = ($_GET['detail'] ?? '') === '1';
$tid = (int)$_settings->active_tenant_id();

$rows = [];
$stmt = $conn->prepare("SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.total, i.balance,
        p.id party_id, p.name party_name, p.party_code
    FROM invoice_list i INNER JOIN party_list p ON p.id = i.party_id
    WHERE i.tenant_id = ? AND i.status NOT IN ('draft','cancelled') AND i.balance > 0 AND i.invoice_date <= ?
    ORDER BY p.name, i.invoice_date");
if ($stmt) {
    $stmt->bind_param('is', $tid, $as_of);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
$ar = LedgerService::agedReceivables($rows, $as_of);
$t = $ar['totals'];
$buckets = LedgerService::AGING_BUCKETS;
$overdue = $t['total'] - $t['current'];

nvr_header([
    'page' => 'reports/ar_aging',
    'title' => 'Aged Receivables',
    'icon' => 'fas fa-hourglass-half',
    'mode' => 'asat',
    'asat' => $as_of,
    'extra' => '<div class="nvr-field"><label>&nbsp;</label><label class="nvr-toggle" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" name="detail" value="1"' . ($detail ? ' checked' : '') . '> Show invoices</label></div>',
    'subtitle' => 'As at ' . nvr_nice_date($as_of),
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Total owed</div><div class="v"><?= nvr_money($t['total'], false) ?></div><div class="s"><?= count($ar['customers']) ?> customer<?= count($ar['customers']) === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k">Not yet due</div><div class="v"><?= nvr_money($t['current'], false) ?></div></div>
    <div class="nvr-stat"><div class="k">Overdue</div><div class="v<?= $overdue > 0 ? ' neg' : '' ?>"><?= nvr_money($overdue, false) ?></div><div class="s"><?= $t['total'] > 0 ? number_format($overdue / $t['total'] * 100, 0) . '% of the total' : '' ?></div></div>
    <div class="nvr-stat"><div class="k">Over 90 days</div><div class="v<?= $t['older'] > 0 ? ' neg' : '' ?>"><?= nvr_money($t['older'], false) ?></div><div class="s">Consider following up or providing for these</div></div>
</div>

<?php if (!$ar['customers']): ?>
    <div class="nvr-empty"><i class="fas fa-check-circle"></i><b>Nothing owed</b>No customer has an unpaid invoice dated on or before <?= nvr_nice_date($as_of) ?>.</div>
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
    <thead><tr><th>Customer</th><?php foreach ($buckets as $lbl): ?><th class="num"><?= $lbl ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($ar['customers'] as $c): ?>
        <tr class="<?= $detail ? 'sub' : 'acct' ?>">
            <td><b><?= htmlspecialchars($c['name']) ?></b> <span class="muted" style="font-size:.78rem"><?= htmlspecialchars($c['code']) ?></span><?php if ($c['oldest'] > 90): ?> <span class="nvr-pill" style="background:#fef2f2;color:#b91c1c"><?= $c['oldest'] ?> days</span><?php endif; ?></td>
            <?php foreach ($buckets as $k => $_): ?><td class="num<?= $k !== 'current' && $c[$k] > 0 ? ' neg' : '' ?>"><?= nvr_money($c[$k]) ?></td><?php endforeach; ?>
            <td class="num"><b><?= nvr_money($c['total']) ?></b></td>
        </tr>
        <?php if ($detail): foreach ($c['invoices'] as $i): ?>
        <tr class="acct">
            <td><a class="nvr-drill" href="./?page=invoices/view&id=<?= $i['id'] ?>"><?= htmlspecialchars($i['invoice_no']) ?></a>
                <span class="muted" style="font-size:.78rem"> · issued <?= date('j M Y', strtotime($i['invoice_date'])) ?><?= $i['due_date'] ? ' · due ' . date('j M Y', strtotime($i['due_date'])) : '' ?><?= $i['days'] ? ' · ' . $i['days'] . ' days overdue' : '' ?></span></td>
            <?php foreach ($buckets as $k => $_): ?><td class="num muted"><?= $i['bucket'] === $k ? nvr_money($i['balance']) : '' ?></td><?php endforeach; ?>
            <td class="num muted"><?= nvr_money($i['balance']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="grand"><td>Total</td><?php foreach ($buckets as $k => $_): ?><td class="num"><?= nvr_money($t[$k], false) ?></td><?php endforeach; ?><td class="num"><?= nvr_money($t['total'], false) ?></td></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Aged by due date (or invoice date when there is none). Balances are as they stand today, for invoices issued on or before the selected date.'], false);
