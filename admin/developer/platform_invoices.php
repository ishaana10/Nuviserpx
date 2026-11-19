<?php
require_once(dirname(__DIR__, 2) . '/config.php');
if ($_settings->userdata('type') != 1) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
require_once dirname(__DIR__) . '/billing/_lib.php';
$new_for = (int)($_GET['new_for'] ?? 0);
$svc = nv_pb_svc(); $owner = $svc->ownerId();
$ownerName = $owner ? (string)$conn->query("SELECT name FROM tenants WHERE id = {$owner}")->fetch_row()[0] : '';
$list = $svc->rows(); $rev = $owner ? $svc->revenueByProduct($owner) : [];
?>
<?php if (!$owner): ?>
<div class="alert alert-warning">No company is set as the platform owner. Edit your own company under Tenants and tick "This company bills the other tenants" before raising invoices.</div>
<?php else: ?>
<div class="alert alert-light border small">Invoices raised here are sales invoices in <b><?= htmlspecialchars($ownerName) ?></b>'s books. They also appear under Sales, Customer Statements and the reports there.</div>
<?php if ($rev): ?>
<div class="row mb-2"><?php foreach ($rev as $r): ?>
  <div class="col-6 col-md-3"><div class="card mb-2"><div class="card-body py-2"><div class="text-muted small"><?= htmlspecialchars(\App\Services\PlatformBillingService::PRODUCTS[$r['p']] ?? ucfirst($r['p'])) ?></div>
    <b><?= nv_pb_money($r['s']) ?></b> <span class="text-muted small">invoiced, <?= nv_pb_money($r['paid']) ?> paid (<?= (int)$r['n'] ?>)</span></div></div></div>
<?php endforeach; ?></div>
<?php endif; endif; ?>
<div class="card card-outline card-primary">
  <div class="card-header"><h3 class="card-title">Tenant invoices</h3>
    <div class="card-tools"><button class="btn btn-primary btn-sm" onclick="uni_modal('New invoice','developer/manage_platform_invoice.php','large')"><i class="fa fa-plus"></i> New invoice</button></div></div>
  <div class="card-body">
    <table class="table table-sm table-striped table-bordered" id="list">
      <thead><tr><th>Invoice</th><th>Product</th><th>Tenant</th><th>Date</th><th>Due</th><th class="text-right">Total</th><th class="text-right">Paid</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['invoice_no']) ?></td>
          <td><?= htmlspecialchars(\App\Services\PlatformBillingService::PRODUCTS[$r['product_code']] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['tname']) ?> <small class="text-muted"><?= htmlspecialchars($r['tenant_code']) ?></small></td>
          <td><?= date('d M Y', strtotime($r['issue_date'])) ?></td>
          <td><?= $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '-' ?></td>
          <td class="text-right"><?= number_format($r['total'], 2) ?></td>
          <td class="text-right"><?= number_format($r['paid_amount'], 2) ?></td>
          <td><?= nv_pb_badge($r) ?></td>
          <td>
            <button class="btn btn-xs btn-outline-primary" onclick="uni_modal('Invoice <?= htmlspecialchars($r['invoice_no'], ENT_QUOTES) ?>','billing/doc_modal.php?type=invoice&id=<?= (int)$r['id'] ?>','large')">View / print</button>
            <?php if (in_array($r['status'], ['unpaid', 'partial'], true)): ?>
              <button class="btn btn-xs btn-outline-success" onclick="uni_modal('Record payment — <?= htmlspecialchars($r['invoice_no'], ENT_QUOTES) ?>','developer/record_platform_payment.php?id=<?= (int)$r['id'] ?>')">Record payment</button>
            <?php endif; ?>
            <?php if ($r['status'] === 'unpaid'): ?>
              <button class="btn btn-xs btn-outline-danger void-inv" data-id="<?= (int)$r['id'] ?>">Void</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
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
