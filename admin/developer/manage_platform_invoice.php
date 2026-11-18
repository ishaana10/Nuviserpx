<?php
require_once(dirname(__DIR__, 2) . '/config.php');
if ($_settings->userdata('type') != 1) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
$pre = (int)($_GET['tenant_id'] ?? 0);
$tenants = $conn->query("SELECT t.id, t.name, t.tenant_code, p.name pname, p.price FROM tenants t LEFT JOIN tenant_plans p ON p.id = t.plan_id WHERE t.delete_flag = 0 ORDER BY t.name");
$tl = []; while ($tenants && $r = $tenants->fetch_assoc()) { $tl[] = $r; }
$sel = null; foreach ($tl as $r) { if ($r['id'] == $pre) $sel = $r; }
$products = ['Nuvis ERPX subscription', 'Nuvis MedcareX subscription', 'Nuvis StudioX subscription', 'Setup and onboarding', 'Training', 'Support and maintenance', 'Customisation'];
?>
<form id="pinv-form" class="container-fluid">
  <div class="row">
    <div class="col-md-6 form-group"><label>Tenant</label>
      <select name="tenant_id" id="pi_tenant" class="form-control form-control-sm" required>
        <option value="">Choose a tenant</option>
        <?php foreach ($tl as $r): ?><option value="<?= $r['id'] ?>" data-plan="<?= htmlspecialchars((string)$r['pname']) ?>" data-price="<?= htmlspecialchars((string)$r['price']) ?>" <?= $r['id'] == $pre ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['tenant_code']) ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3 form-group"><label>Date</label><input type="date" name="issue_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-3 form-group"><label>Due</label><input type="date" name="due_date" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+14 days')) ?>"></div>
  </div>
  <table class="table table-sm" id="pi_lines">
    <thead><tr><th>Product or service</th><th width="12%">Qty</th><th width="18%">Unit price</th><th width="16%" class="text-right">Amount</th><th width="4%"></th></tr></thead>
    <tbody></tbody>
  </table>
  <datalist id="pi_products"><?php foreach ($products as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?php endforeach; ?></datalist>
  <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="pi_add"><i class="fa fa-plus"></i> Add line</button>
  <div class="row">
    <div class="col-md-7 form-group"><label>Notes</label><textarea name="notes" rows="3" class="form-control form-control-sm"></textarea></div>
    <div class="col-md-5">
      <div class="form-group form-row align-items-center"><label class="col-6 mb-0">Tax rate %</label><div class="col-6"><input type="number" step="0.01" min="0" name="tax_rate" id="pi_tax" class="form-control form-control-sm" value="0"></div></div>
      <div class="d-flex justify-content-between"><span>Subtotal</span><b id="pi_sub">0.00</b></div>
      <div class="d-flex justify-content-between"><span>Tax</span><b id="pi_taxamt">0.00</b></div>
      <div class="d-flex justify-content-between border-top mt-1 pt-1"><span>Total</span><b id="pi_total">0.00</b></div>
    </div>
  </div>
</form>
<script>
(function(){
  function row(d,q,p){
    return $('<tr><td><input name="description[]" list="pi_products" class="form-control form-control-sm" value=""></td><td><input type="number" step="0.01" min="0" name="qty[]" class="form-control form-control-sm pi-q"></td><td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm pi-p"></td><td class="text-right pi-a">0.00</td><td><button type="button" class="btn btn-xs btn-link text-danger pi-x">&times;</button></td></tr>')
      .find('[name="description[]"]').val(d||'').end().find('.pi-q').val(q||1).end().find('.pi-p').val(p||'').end();
  }
  function calc(){
    var sub=0; $('#pi_lines tbody tr').each(function(){var a=(parseFloat($(this).find('.pi-q').val())||0)*(parseFloat($(this).find('.pi-p').val())||0);$(this).find('.pi-a').text(a.toFixed(2));sub+=a;});
    var tx=sub*(parseFloat($('#pi_tax').val())||0)/100; $('#pi_sub').text(sub.toFixed(2));$('#pi_taxamt').text(tx.toFixed(2));$('#pi_total').text((sub+tx).toFixed(2));
  }
  function seed(){
    var o=$('#pi_tenant option:selected'), plan=o.data('plan'), price=o.data('price');
    $('#pi_lines tbody').empty().append(plan ? row(plan+' plan', 1, price) : row('Nuvis ERPX subscription',1,''));
    calc();
  }
  $('#pi_add').click(function(){ $('#pi_lines tbody').append(row()); });
  $(document).off('input.pi').on('input.pi','#pi_lines input,#pi_tax',calc);
  $(document).off('click.pix').on('click.pix','.pi-x',function(){ if($('#pi_lines tbody tr').length>1){$(this).closest('tr').remove();calc();} });
  $('#pi_tenant').change(seed); seed();
  $('#pinv-form').off('submit').submit(function(e){
    e.preventDefault(); start_loader();
    $.ajax({url:_base_url_+'classes/Master.php?f=save_platform_invoice',data:new FormData(this),cache:false,contentType:false,processData:false,method:'POST',dataType:'json',
      error:function(){alert_toast('An error occurred','error');end_loader();},
      success:function(r){ if(r&&r.status=='success'){ location.href=_base_url_+'admin/?page=developer/platform_invoices'; } else {alert_toast((r&&r.msg)||'Error','error');end_loader();} }});
  });
})();
</script>
