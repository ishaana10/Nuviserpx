<?php
/** Record a delivery against an order: choose how much of each line is being sent. */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\DeliveryService($conn, $_settings);
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tenant_id = (int)$_settings->active_tenant_id();
$orderId = (int)($_GET['order_id'] ?? 0);
$order = $svc->order($orderId);
if (!$order || (int)$order['delivery_mode'] !== 1 || $order['status'] !== 'confirmed') {
    echo "<script>alert('That order is not set up for delivery.'); location.replace('./?page=delivery_notes');</script>";
    exit;
}
$lines = $svc->lines($orderId);
$party = $conn->query("SELECT name FROM party_list WHERE id = " . (int)$order['party_id'] . " AND tenant_id = {$tenant_id}")->fetch_assoc();
$warehouses = [];
$wq = $conn->query("SELECT id, name FROM `warehouses` WHERE tenant_id = {$tenant_id} AND status = 1 ORDER BY name");
while ($wq && ($w = $wq->fetch_assoc())) { $warehouses[] = $w; }
$fmt = fn ($n) => rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.') ?: '0';
?>
<div class="card card-outline card-primary">
  <div class="card-header"><h3 class="card-title"><i class="fas fa-truck text-primary"></i> New delivery — order <?= $e($order['order_no']) ?> · <?= $e($party['name'] ?? '') ?></h3></div>
  <form id="dn-form">
    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
    <div class="card-body">
      <div class="form-row mb-3">
        <div class="col-md-3"><label>Delivery date</label><input type="date" name="delivery_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3"><label>Send from</label>
          <select name="warehouse_id" class="form-control form-control-sm">
            <option value="0" <?= (int)($order['warehouse_id'] ?? 0) === 0 ? 'selected' : '' ?>>Main / Unassigned</option>
            <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>" <?= (int)($order['warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label>Notes</label><input type="text" name="notes" class="form-control form-control-sm" maxlength="500" placeholder="Driver, vehicle, reference…"></div>
      </div>
      <table class="table table-sm">
        <thead><tr><th>Item</th><th class="text-right">Ordered</th><th class="text-right">Delivered so far</th><th class="text-right">Left</th><th style="width:140px" class="text-right">Deliver now</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $left = \App\Services\DeliveryService::remaining((float)$l['ordered'], (float)$l['delivered']); ?>
          <tr>
            <td><?= $e($l['item_name']) ?><?= (int)$l['product_id'] > 0 ? '' : ' <small class="text-muted">(service / non-stock)</small>' ?></td>
            <td class="text-right"><?= $fmt($l['ordered']) ?></td>
            <td class="text-right"><?= $fmt($l['delivered']) ?></td>
            <td class="text-right"><?= $fmt($left) ?></td>
            <td><input type="number" step="any" min="0" max="<?= $e($left) ?>" name="qty[<?= (int)$l['id'] ?>]" class="form-control form-control-sm text-right" value="<?= $left > 0 ? $e($left) : 0 ?>" <?= $left > 0 ? '' : 'readonly' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="text-muted text-sm mb-0">Stock is taken off the shelf for the quantities you deliver. Lower a quantity for a partial delivery; the rest stays open.</p>
    </div>
    <div class="card-footer text-right"><a href="./?page=delivery_notes" class="btn btn-sm btn-default">Back</a> <button class="btn btn-sm btn-primary" type="submit">Record delivery</button></div>
  </form>
</div>
<script>
$('#dn-form').on('submit', function (ev) {
    ev.preventDefault();
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=save_delivery_note', $(this).serialize(), function (r) {
        if (r && r.status === 'success') { location.href = './?page=delivery_notes/view&id=' + r.id; }
        else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
    }, 'json').fail(function () { alert_toast('An error occurred.', 'error'); end_loader(); });
});
</script>
