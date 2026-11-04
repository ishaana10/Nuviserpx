<?php
/** Delivery notes, and the orders waiting to be delivered. */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\DeliveryService($conn, $_settings);
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tenant_id = (int)$_settings->active_tenant_id();

// Orders set up for delivery that still have something to send or to invoice.
$open = [];
$oq = $conn->query("SELECT o.id, o.order_no, o.order_date, p.name party_name FROM sales_order_list o INNER JOIN party_list p ON p.id = o.party_id
    WHERE o.tenant_id = {$tenant_id} AND o.delivery_mode = 1 AND o.status = 'confirmed' ORDER BY o.id DESC");
while ($oq && ($r = $oq->fetch_assoc())) {
    $lines = $svc->lines((int)$r['id']);
    $r['fulfilment'] = \App\Services\DeliveryService::fulfilment($lines);
    $r['to_invoice'] = array_sum(array_map(fn ($l) => \App\Services\DeliveryService::invoiceable((float)$l['delivered'], (float)$l['invoiced']), $lines));
    $open[] = $r;
}
$notes = $svc->all();
?>
<div class="card card-outline card-primary">
  <div class="card-header"><h3 class="card-title"><i class="fas fa-truck text-primary"></i> Orders to deliver</h3></div>
  <div class="card-body">
    <table class="table table-sm table-striped">
      <thead><tr><th>Order</th><th>Customer</th><th>Delivery</th><th class="text-right">Delivered, not invoiced</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($open as $o): ?>
        <tr>
          <td><a href="./?page=sales_orders/view&id=<?= (int)$o['id'] ?>"><?= $e($o['order_no']) ?></a></td>
          <td><?= $e($o['party_name']) ?></td>
          <td><span class="badge badge-<?= $o['fulfilment'] === 'delivered' ? 'success' : ($o['fulfilment'] === 'partially_delivered' ? 'warning' : 'secondary') ?>"><?= $e(strtoupper(str_replace('_', ' ', $o['fulfilment']))) ?></span></td>
          <td class="text-right"><?= $e(rtrim(rtrim(number_format((float)$o['to_invoice'], 4, '.', ''), '0'), '.') ?: '0') ?></td>
          <td class="text-right">
            <?php if ($o['fulfilment'] !== 'delivered'): ?><a class="btn btn-xs btn-primary" href="./?page=delivery_notes/new&order_id=<?= (int)$o['id'] ?>">Record delivery</a><?php endif; ?>
            <?php if ($o['to_invoice'] > 0): ?><button class="btn btn-xs btn-success invoice-delivered" data-id="<?= (int)$o['id'] ?>">Invoice delivered goods</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$open): ?><tr><td colspan="5" class="text-muted">No orders are waiting for delivery. Use "Confirm for delivery" on an order or quote.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card-outline card-info">
  <div class="card-header"><h3 class="card-title">Delivery notes</h3></div>
  <div class="card-body">
    <table class="table table-sm table-striped" id="dn-list">
      <thead><tr><th>Delivery note</th><th>Date</th><th>Order</th><th>Customer</th><th class="text-right">Units</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($notes as $n): ?>
        <tr>
          <td><a href="./?page=delivery_notes/view&id=<?= (int)$n['id'] ?>"><b><?= $e($n['dn_no']) ?></b></a></td>
          <td><?= date('d M Y', strtotime($n['delivery_date'])) ?></td>
          <td><?= $e($n['order_no']) ?></td>
          <td><?= $e($n['party_name']) ?></td>
          <td class="text-right"><?= $e(rtrim(rtrim(number_format((float)$n['units'], 4, '.', ''), '0'), '.') ?: '0') ?></td>
          <td><span class="badge badge-<?= $n['status'] === 'delivered' ? ((int)$n['invoiced_lines'] > 0 ? 'success' : 'primary') : 'dark' ?>"><?= $n['status'] === 'delivered' && (int)$n['invoiced_lines'] > 0 ? 'INVOICED' : $e(strtoupper($n['status'])) ?></span></td>
          <td class="text-right"><?php if ($n['status'] === 'delivered' && (int)$n['invoiced_lines'] === 0): ?><button class="btn btn-xs btn-outline-danger cancel-dn" data-id="<?= (int)$n['id'] ?>" data-no="<?= $e($n['dn_no']) ?>">Cancel</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
$(function () {
    var go = function (f, data) {
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=' + f, data, function (r) {
            if (r && r.status === 'success') { location.reload(); }
            else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
        }, 'json').fail(function () { alert_toast('An error occurred.', 'error'); end_loader(); });
    };
    $('.invoice-delivered').on('click', function () {
        if (window.confirm('Raise an invoice for everything delivered on this order and not yet invoiced?')) { go('invoice_delivered', { id: $(this).data('id') }); }
    });
    $('.cancel-dn').on('click', function () {
        if (window.confirm('Cancel delivery note ' + $(this).data('no') + '? The goods go back into stock and can be delivered again.')) { go('cancel_delivery_note', { id: $(this).data('id') }); }
    });
});
</script>
