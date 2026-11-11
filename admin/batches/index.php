<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\BatchService;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$svc = new BatchService($conn, $_settings);
$filter = in_array($_GET['show'] ?? '', ['soon', 'expired'], true) ? $_GET['show'] : 'all';
$rows = $svc->listing($filter);
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.') ?: '0';
$badge = ['expired' => 'danger', 'soon' => 'warning', 'ok' => 'success', 'none' => 'secondary'];
$label = ['expired' => 'Expired', 'soon' => 'Expires soon', 'ok' => 'OK', 'none' => 'No expiry'];
$value = 0.0; $exp = []; 
foreach ($rows as $r) {
    $value += (float)$r['qty_remaining'] * (float)$r['unit_cost'];
    if ($r['status'] === 'expired') { $exp[$r['product_id'] . '-' . $r['warehouse_id']] = [(int)$r['product_id'], (int)$r['warehouse_id'], $r['name']]; }
}
?>
<div class="card card-outline card-warning">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-hourglass-half text-warning"></i> Batches &amp; Expiry</h3>
        <div class="card-tools">
            <a class="btn btn-sm btn-flat <?= $filter === 'all' ? 'btn-primary' : 'btn-default' ?>" href="./?page=batches">All</a>
            <a class="btn btn-sm btn-flat <?= $filter === 'soon' ? 'btn-primary' : 'btn-default' ?>" href="./?page=batches&show=soon">Expiring in <?= BatchService::WARN_DAYS ?> days</a>
            <a class="btn btn-sm btn-flat <?= $filter === 'expired' ? 'btn-primary' : 'btn-default' ?>" href="./?page=batches&show=expired">Expired</a>
        </div>
    </div>
    <div class="card-body">
        <p class="text-sm text-muted">Batches exist for products with "Track batch / lot numbers and expiry dates" ticked on the product. Stock is used first-expiry-first-out. Stock that was on hand before tracking was switched on is not in any batch and does not show here. Value at batch cost: <b><?= number_format($value, 2) ?></b></p>
        <?php if ($exp): ?>
        <div class="alert alert-danger py-2 text-sm">Expired stock is still counted as on hand until it is written off.
            <?php foreach ($exp as $e): ?><button class="btn btn-xs btn-danger btn-flat ml-1 wo" data-p="<?= $e[0] ?>" data-w="<?= $e[1] ?>"><?= $h($e[2]) ?>: write off expired</button><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead><tr><th>Product</th><th>Batch</th><th>Location</th><th>Expiry</th><th class="text-right">Quantity</th><th class="text-right">Unit cost</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $h($r['name']) ?> <small class="text-muted"><?= $h($r['product_code']) ?></small></td>
                    <td><?= $h($r['batch_no']) ?></td>
                    <td><?= $h($r['warehouse_name'] ?: 'Main / unassigned') ?></td>
                    <td><?= $r['expiry_date'] ? $h(date('d M Y', strtotime($r['expiry_date']))) : '-' ?></td>
                    <td class="text-right"><?= $fmt($r['qty_remaining']) ?></td>
                    <td class="text-right"><?= number_format((float)$r['unit_cost'], 2) ?></td>
                    <td><span class="badge badge-<?= $badge[$r['status']] ?>"><?= $label[$r['status']] ?></span></td>
                </tr>
            <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">No batches to show.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<script>
$('.wo').click(function(){
    if (!confirm('Write off all expired stock of this product at this location? This reduces stock and posts to the ledger if inventory accounting is on.')) return;
    start_loader();
    $.post(_base_url_+'classes/Master.php?f=write_off_expired', {product_id: $(this).data('p'), warehouse_id: $(this).data('w')}, null, 'json')
        .fail(function(){ alert_toast('An error occurred.','error'); end_loader(); })
        .done(function(r){ alert_toast(r.msg || 'Done.', r.status==='success'?'success':'error'); end_loader(); if (r.status==='success') setTimeout(function(){ location.reload(); }, 1000); });
});
</script>
