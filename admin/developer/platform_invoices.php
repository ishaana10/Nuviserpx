<?php
require_once(dirname(__DIR__, 2) . '/config.php');
if ($_settings->userdata('type') != 1) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
require_once dirname(__DIR__) . '/billing/_lib.php';
$new_for = (int)($_GET['new_for'] ?? 0);
$q = $conn->query("SELECT pi.*, t.name tname, t.tenant_code FROM platform_invoices pi JOIN tenants t ON t.id = pi.tenant_id ORDER BY pi.issue_date DESC, pi.id DESC");
?>
<div class="card card-outline card-primary">
  <div class="card-header"><h3 class="card-title">Tenant invoices</h3>
    <div class="card-tools"><button class="btn btn-primary btn-sm" onclick="uni_modal('New invoice','developer/manage_platform_invoice.php','large')"><i class="fa fa-plus"></i> New invoice</button></div></div>
  <div class="card-body">
    <table class="table table-sm table-striped table-bordered" id="list">
      <thead><tr><th>Invoice</th><th>Tenant</th><th>Date</th><th>Due</th><th class="text-right">Total</th><th class="text-right">Paid</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php while ($q && $r = $q->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($r['invoice_no']) ?></td>
          <td><?= htmlspecialchars($r['tname']) ?> <small class="text-muted"><?= htmlspecialchars($r['tenant_code']) ?></small></td>
          <td><?= date('d M Y', strtotime($r['issue_date'])) ?></td>
          <td><?= $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '-' ?></td>
          <td class="text-right"><?= number_format($r['total'], 2) ?></td>
          <td class="text-right"><?= number_format($r['paid_amount'], 2) ?></td>
          <td><?= nv_pb_badge($r) ?></td>
          <td>
            <a class="btn btn-xs btn-outline-primary" target="_blank" href="<?= base_url ?>admin/?page=billing/invoice&id=<?= (int)$r['id'] ?>">View / print</a>
            <?php if (in_array($r['status'], ['unpaid', 'partial'], true)): ?>
              <button class="btn btn-xs btn-outline-success" onclick="uni_modal('Record payment — <?= htmlspecialchars($r['invoice_no'], ENT_QUOTES) ?>','developer/record_platform_payment.php?id=<?= (int)$r['id'] ?>')">Record payment</button>
            <?php endif; ?>
            <?php if ($r['status'] === 'unpaid'): ?>
              <button class="btn btn-xs btn-outline-danger void-inv" data-id="<?= (int)$r['id'] ?>">Void</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
$(function(){
  $('.void-inv').click(function(){
    var id = $(this).data('id');
    _conf("Void this invoice? It stays on record but can no longer be paid.", "void_inv", [id]);
  });
  <?php if ($new_for): ?>uni_modal('New invoice','developer/manage_platform_invoice.php?tenant_id=<?= $new_for ?>','large');<?php endif; ?>
});
function void_inv(id){
  start_loader();
  $.post(_base_url_+'classes/Master.php?f=void_platform_invoice',{id:id},function(r){
    if(r&&r.status=='success'){location.reload();}else{alert_toast((r&&r.msg)||'Error','error');end_loader();}
  },'json').fail(function(){alert_toast('An error occurred','error');end_loader();});
}
</script>
