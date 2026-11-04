<?php
/** A printable delivery note (no prices: it travels with the goods). */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\DeliveryService($conn, $_settings);
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$d = $svc->note((int)($_GET['id'] ?? 0));
if (!$d) {
    echo "<script>alert('Delivery note not found.'); location.replace('./?page=delivery_notes');</script>";
    exit;
}
$items = $svc->noteItems((int)$d['id']);
$fmt = fn ($n) => rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.') ?: '0';
?>
<style>@media print { .no-print { display: none !important; } }</style>
<div class="card card-outline card-primary">
  <div class="card-header no-print">
    <h3 class="card-title">Delivery note <b><?= $e($d['dn_no']) ?></b> <?= $d['status'] === 'cancelled' ? '<span class="badge badge-dark">CANCELLED</span>' : '' ?></h3>
    <div class="card-tools"><button class="btn btn-sm btn-secondary" onclick="window.print()"><i class="fa fa-print"></i> Print</button> <a href="./?page=delivery_notes" class="btn btn-sm btn-default">Back</a></div>
  </div>
  <div class="card-body">
    <h3 class="font-weight-bold">DELIVERY NOTE</h3>
    <div class="row mb-3">
      <div class="col-6"><b>Deliver to</b><br><?= $e($d['party_name']) ?><br><small class="text-muted"><?= nl2br($e($d['party_address'])) ?></small></div>
      <div class="col-6 text-right"><b><?= $e($d['dn_no']) ?></b><br>Date: <?= date('d M Y', strtotime($d['delivery_date'])) ?><br>Order: <?= $e($d['order_no']) ?></div>
    </div>
    <table class="table table-bordered">
      <thead><tr><th>Item</th><th class="text-right" style="width:140px">Quantity</th></tr></thead>
      <tbody><?php foreach ($items as $i): ?><tr><td><?= $e($i['item_name']) ?></td><td class="text-right"><?= $fmt($i['qty']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php if ($d['notes']): ?><p><b>Notes:</b> <?= $e($d['notes']) ?></p><?php endif; ?>
    <div class="row mt-5"><div class="col-6">Delivered by: ______________________</div><div class="col-6 text-right">Received by (name &amp; signature): ______________________</div></div>
  </div>
</div>
