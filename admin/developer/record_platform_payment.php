<?php
require_once(dirname(__DIR__, 2) . '/config.php');
if ($_settings->userdata('type') != 1) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
$id = (int)($_GET['id'] ?? 0);
$q = $conn->query("SELECT * FROM platform_invoices WHERE id = {$id}");
$inv = $q ? $q->fetch_assoc() : null;
if (!$inv) { echo '<div class="alert alert-warning">Invoice not found.</div>'; return; }
$bal = round($inv['total'] - $inv['paid_amount'], 2);
?>
<form id="ppay-form" class="container-fluid">
  <input type="hidden" name="invoice_id" value="<?= $id ?>">
  <p class="mb-2">Balance due: <b><?= number_format($bal, 2) ?></b></p>
  <div class="row">
    <div class="col-6 form-group"><label>Date received</label><input type="date" name="pay_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
    <div class="col-6 form-group"><label>Amount</label><input type="number" step="0.01" min="0.01" max="<?= $bal ?>" name="amount" class="form-control form-control-sm" value="<?= $bal ?>" required></div>
    <div class="col-6 form-group"><label>Method</label><select name="method" class="form-control form-control-sm"><option>Bank transfer</option><option>Cash</option><option>Cheque</option><option>Card</option><option>Mobile money</option></select></div>
    <div class="col-6 form-group"><label>Reference</label><input name="reference" class="form-control form-control-sm"></div>
  </div>
</form>
<script>
$('#ppay-form').off('submit').submit(function(e){
  e.preventDefault(); start_loader();
  $.ajax({url:_base_url_+'classes/Master.php?f=record_platform_payment',data:new FormData(this),cache:false,contentType:false,processData:false,method:'POST',dataType:'json',
    error:function(){alert_toast('An error occurred','error');end_loader();},
    success:function(r){ if(r&&r.status=='success'){location.reload();}else{alert_toast((r&&r.msg)||'Error','error');end_loader();} }});
});
</script>
