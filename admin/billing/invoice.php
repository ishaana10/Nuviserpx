<?php
require_once(dirname(__DIR__, 2) . '/config.php');
require_once __DIR__ . '/_lib.php';
$id = (int)($_GET['id'] ?? 0);
$all = nv_pb_svc()->rows($_settings->userdata('type') == 1 ? 0 : (int)$_settings->active_tenant_id());
$inv = null; foreach ($all as $r) { if ((int)$r['id'] === $id) { $inv = $r; } }
if (!$inv) { echo '<div class="alert alert-warning">Invoice not found.</div>'; return; }
$oi = (int)$inv['owner_invoice_id'];
$items = $conn->query("SELECT * FROM invoice_items WHERE invoice_id = {$oi} ORDER BY id");
$pays = nv_pb_svc()->receipts($oi);
$cur = null;
$brand = $conn->query('SELECT * FROM tenants WHERE id = ' . (int)$inv['owner_id'])->fetch_assoc() ?: [];
?>
<style>@media print{.main-sidebar,.main-header,.nv-page-head,.no-print,.main-footer{display:none!important}.content-wrapper{margin:0!important}}</style>
<div class="card" id="pinv">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between">
      <div><h3 class="mb-0"><?= htmlspecialchars((string)($brand['name'] ?? 'Nuvis Technologies')) ?></h3><?php if (!empty($brand['address'])): ?><div class="text-muted"><?= nl2br(htmlspecialchars($brand['address'])) ?></div><?php endif; ?><?php if (!empty($brand['tin'])): ?><div class="text-muted">TIN: <?= htmlspecialchars($brand['tin']) ?></div><?php endif; ?><div class="text-muted"><?= htmlspecialchars((string)($brand['email'] ?? '')) ?></div></div>
      <div class="text-right"><h2 class="mb-1"><?= $inv['tax_amount'] > 0 ? 'TAX INVOICE' : 'INVOICE' ?></h2>
        <div>No. <b><?= htmlspecialchars($inv['invoice_no']) ?></b></div>
        <div>Date: <?= date('d M Y', strtotime($inv['issue_date'])) ?></div>
        <div>Due: <?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-' ?></div>
        <div class="mt-1"><?= nv_pb_badge($inv) ?></div></div>
    </div>
    <hr>
    <div class="mb-3"><div class="text-muted small">Bill to</div><b><?= htmlspecialchars($inv['tname']) ?></b> (<?= htmlspecialchars($inv['tenant_code']) ?>)
      <?php if ($inv['taddr']): ?><div><?= nl2br(htmlspecialchars($inv['taddr'])) ?></div><?php endif; ?>
      <?php if ($inv['ttin']): ?><div>TIN: <?= htmlspecialchars($inv['ttin']) ?></div><?php endif; ?></div>
    <table class="table table-sm">
      <thead><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Amount</th></tr></thead>
      <tbody><?php while ($items && $i = $items->fetch_assoc()): ?>
        <tr><td><?= htmlspecialchars($i['item_name']) ?></td><td class="text-right"><?= rtrim(rtrim(number_format($i['qty'], 2), '0'), '.') ?></td><td class="text-right"><?= number_format($i['unit_price'], 2) ?></td><td class="text-right"><?= number_format($i['amount'], 2) ?></td></tr>
      <?php endwhile; ?></tbody>
    </table>
    <div class="row"><div class="col-md-6 offset-md-6">
      <table class="table table-sm">
        <tr><td>Subtotal</td><td class="text-right"><?= nv_pb_money($inv['subtotal']) ?></td></tr>
        <?php if ($inv['tax_amount'] > 0): ?><tr><td>Tax (<?= rtrim(rtrim(number_format(($inv['subtotal'] > 0 ? $inv['tax_amount'] * 100 / $inv['subtotal'] : 0), 2), '0'), '.') ?>%)</td><td class="text-right"><?= nv_pb_money($inv['tax_amount']) ?></td></tr><?php endif; ?>
        <tr><th>Total</th><th class="text-right"><?= nv_pb_money($inv['total']) ?></th></tr>
        <tr><td>Paid</td><td class="text-right text-success"><?= nv_pb_money($inv['paid_amount']) ?></td></tr>
        <tr><th>Balance due</th><th class="text-right"><?= nv_pb_money($inv['total'] - $inv['paid_amount'] - $inv['credited_amount']) ?></th></tr>
      </table></div></div>
    <?php if ($pays): ?>
      <div class="small text-muted mb-1">Payments received</div>
      <table class="table table-sm"><tbody><?php foreach ($pays as $p): ?>
        <tr><td><?= date('d M Y', strtotime($p['pay_date'])) ?></td><td><?= htmlspecialchars((string)$p['method']) ?> <?= htmlspecialchars((string)$p['reference']) ?></td><td class="text-right"><?= number_format($p['amount'], 2) ?></td></tr>
      <?php endforeach; ?></tbody></table>
    <?php endif; ?>
    <?php if ($inv['notes']): ?><div class="mt-2"><div class="small text-muted">Notes</div><?= nl2br(htmlspecialchars($inv['notes'])) ?></div><?php endif; ?>
    <div class="no-print mt-3"><button class="btn btn-primary" onclick="window.print()"><i class="fa fa-print"></i> Print</button></div>
  </div>
</div>
