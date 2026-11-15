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
    <?php $signed = !empty($d['received_at']) && \App\Support\ProposalLink::validSignatureImage((string)$d['receiver_signature']); ?>
    <div class="row mt-5">
      <div class="col-6">Delivered by: ______________________</div>
      <div class="col-6 text-right">
        <?php if ($signed): ?>
          <div><img src="<?= $e($d['receiver_signature']) ?>" alt="Receiver signature" style="max-height:90px;max-width:100%"></div>
          <div class="border-top d-inline-block pt-1" style="min-width:260px">Received by: <b><?= $e($d['received_by']) ?></b><br>
            <small class="text-muted">Signed electronically on <?= date('d M Y, H:i', strtotime((string)$d['received_at'])) ?></small></div>
        <?php else: ?>
          Received by (name &amp; signature): ______________________
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$signed && $d['status'] !== 'cancelled' && $_settings->can_access('sales_orders_edit')): ?>
    <div class="card mt-4 no-print" id="sign-card">
      <div class="card-header"><b><i class="fa fa-signature"></i> Receiver's signature</b></div>
      <div class="card-body">
        <div class="form-group">
          <label for="received_by">Name of person receiving the goods</label>
          <input type="text" id="received_by" class="form-control" maxlength="150" autocomplete="off" placeholder="Type the receiver's full name">
        </div>
        <label>Signature <small class="text-muted">(sign with finger, stylus or mouse)</small></label>
        <div style="border:2px dashed #cbd5e1;border-radius:8px;background:#fafafa;touch-action:none"><canvas id="sig-canvas" style="width:100%;height:180px;display:block"></canvas></div>
        <div class="mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="sig-clear">Clear</button>
          <button type="button" class="btn btn-sm btn-primary" id="sig-save"><i class="fa fa-check"></i> Save signature</button>
          <span class="text-muted small ml-2">Once saved, the signature is locked to this delivery note.</span>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var c = document.getElementById('sig-canvas'), ctx, drawing = false, dirty = false, last = null;
      function size() {
        var r = Math.max(window.devicePixelRatio || 1, 1), img = dirty ? c.toDataURL() : null;
        c.width = c.offsetWidth * r; c.height = c.offsetHeight * r;
        ctx = c.getContext('2d'); ctx.scale(r, r); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
        if (img) { var i = new Image(); i.onload = function () { ctx.drawImage(i, 0, 0, c.offsetWidth, c.offsetHeight); }; i.src = img; }
      }
      function pt(e) { var b = c.getBoundingClientRect(); return {x: e.clientX - b.left, y: e.clientY - b.top}; }
      c.addEventListener('pointerdown', function (e) { drawing = true; last = pt(e); c.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(last.x + .01, last.y + .01); ctx.stroke(); dirty = true; e.preventDefault(); });
      c.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pt(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); });
      ['pointerup', 'pointercancel'].forEach(function (n) { c.addEventListener(n, function () { drawing = false; }); });
      document.getElementById('sig-clear').onclick = function () { dirty = false; size(); };
      window.addEventListener('resize', size); size();
      document.getElementById('sig-save').onclick = function () {
        var name = document.getElementById('received_by').value.trim();
        if (name.length < 2) { alert_toast("Type the receiver's name.", 'warning'); return; }
        if (!dirty) { alert_toast("Please draw the receiver's signature.", 'warning'); return; }
        var btn = this; btn.disabled = true; start_loader();
        $.ajax({ url: _base_url_ + 'classes/Master.php?f=sign_delivery_note', method: 'POST', dataType: 'json',
          data: { id: <?= (int)$d['id'] ?>, received_by: name, signature: c.toDataURL('image/png') },
          error: function () { alert_toast('An error occurred', 'error'); btn.disabled = false; end_loader(); },
          success: function (r) {
            if (r.status === 'success') { location.reload(); }
            else { alert_toast(r.msg || 'Could not save the signature', 'error'); btn.disabled = false; end_loader(); }
          } });
      };
    })();
    </script>
    <?php endif; ?>
  </div>
</div>
