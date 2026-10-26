<?php
/**
 * Sales by customer: invoiced sales per customer for a period, with what has
 * been paid and what is still owed. Draft and cancelled invoices are excluded.
 */
require_once __DIR__ . '/_kit.php';

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['fy_to_date'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['fy_to_date'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$tid = (int)$_settings->active_tenant_id();

$rows = [];
$stmt = $conn->prepare("SELECT p.id, p.name, p.party_code,
        COUNT(i.id) n, SUM(i.subtotal) net, SUM(i.tax_amount) tax, SUM(i.total) total,
        SUM(i.paid_amount) paid, SUM(i.balance) balance,
        SUM(CASE WHEN i.balance > 0 AND i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN i.balance ELSE 0 END) overdue
    FROM invoice_list i INNER JOIN party_list p ON p.id = i.party_id
    WHERE i.tenant_id = ? AND i.status NOT IN ('draft','cancelled') AND i.invoice_date BETWEEN ? AND ?
    GROUP BY p.id, p.name, p.party_code
    ORDER BY total DESC");
if ($stmt) {
    $stmt->bind_param('iss', $tid, $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
$t = ['n' => 0, 'net' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue' => 0.0];
foreach ($rows as $r) {
    foreach ($t as $k => $_) {
        $t[$k] += (float)$r[$k];
    }
}
$top = $rows[0] ?? null;

nvr_header([
    'page' => 'reports/sales_by_customer',
    'title' => 'Sales by Customer',
    'icon' => 'fas fa-users',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => nvr_nice_date($from) . ' – ' . nvr_nice_date($to),
]);
?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Sales (incl. tax)</div><div class="v"><?= nvr_money($t['total'], false) ?></div><div class="s"><?= (int)$t['n'] ?> invoice<?= (int)$t['n'] === 1 ? '' : 's' ?> · <?= count($rows) ?> customer<?= count($rows) === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k">Collected</div><div class="v"><?= nvr_money($t['paid'], false) ?></div><div class="s"><?= $t['total'] > 0 ? number_format($t['paid'] / $t['total'] * 100, 0) . '% of sales' : '–' ?></div></div>
    <div class="nvr-stat"><div class="k">Still owed</div><div class="v"><?= nvr_money($t['balance'], false) ?></div><div class="s"><?= $t['overdue'] > 0 ? nvr_money($t['overdue'], false) . ' overdue' : 'Nothing overdue' ?></div></div>
    <div class="nvr-stat"><div class="k">Largest customer</div><div class="v" style="font-size:1rem;line-height:1.5"><?= $top ? htmlspecialchars($top['name']) : '–' ?></div><div class="s"><?= $top && $t['total'] > 0 ? number_format($top['total'] / $t['total'] * 100, 0) . '% of sales' : '' ?></div></div>
</div>

<?php if (!$rows): ?>
    <div class="nvr-empty"><i class="fas fa-file-invoice"></i><b>No invoiced sales in this period</b>Draft and cancelled invoices aren’t counted.</div>
<?php else: ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th>Customer</th><th class="num">Invoices</th><th class="num">Net</th><th class="num">Tax</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Owing</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $share = $t['total'] > 0 ? (float)$r['total'] / $t['total'] * 100 : 0; ?>
        <tr class="acct">
            <td>
                <a class="nvr-drill" href="./?page=parties/manage_party&id=<?= (int)$r['id'] ?>" onclick="uni_modal('Contact','parties/manage_party.php?id=<?= (int)$r['id'] ?>','large');return false;"><?= htmlspecialchars($r['name']) ?></a>
                <span class="muted" style="font-size:.78rem"> <?= htmlspecialchars((string)$r['party_code']) ?></span>
                <div style="height:4px;background:#eef2f7;border-radius:999px;margin-top:5px;max-width:260px"><span style="display:block;height:100%;width:<?= round($share, 1) ?>%;background:#2563eb;border-radius:inherit"></span></div>
            </td>
            <td class="num"><?= (int)$r['n'] ?></td>
            <td class="num"><?= nvr_money($r['net']) ?></td>
            <td class="num"><?= nvr_money($r['tax']) ?></td>
            <td class="num"><b><?= nvr_money($r['total']) ?></b></td>
            <td class="num"><?= nvr_money($r['paid']) ?></td>
            <td class="num<?= (float)$r['overdue'] > 0 ? ' neg' : '' ?>" title="<?= (float)$r['overdue'] > 0 ? 'Overdue: ' . number_format((float)$r['overdue'], 2) : '' ?>"><?= nvr_money($r['balance']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="grand"><td>Total</td><td class="num"><?= (int)$t['n'] ?></td><td class="num"><?= nvr_money($t['net'], false) ?></td><td class="num"><?= nvr_money($t['tax'], false) ?></td><td class="num"><?= nvr_money($t['total'], false) ?></td><td class="num"><?= nvr_money($t['paid'], false) ?></td><td class="num"><?= nvr_money($t['balance'], false) ?></td></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php
nvr_footer(['Based on invoice dates. Paid and owing amounts are as they stand today. Red owing figures include overdue invoices — see <a href="./?page=reports/ar_aging">aged receivables</a>.'], false);
