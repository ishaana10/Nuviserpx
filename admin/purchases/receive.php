<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$po = $conn->query("SELECT po.*, p.name as vendor_name FROM `purchase_order_list` po INNER JOIN `party_list` p ON po.vendor_id = p.id WHERE po.id = '{$id}' AND po.tenant_id = '{$tenant_id}'");
if(!$po || $po->num_rows == 0){ echo '<div class="alert alert-danger">Purchase order not found.</div>'; return; }
$po = $po->fetch_assoc();

if(in_array($po['status'], ['received','billed','cancelled'])){
	echo '<div class="alert alert-warning">This purchase order is '.strtoupper(str_replace('_',' ',$po['status'])).' and cannot receive further goods.</div>';
	echo '<a href="./?page=purchases/view&id='.$id.'" class="btn btn-sm btn-default btn-flat"><i class="fas fa-arrow-left"></i> Back to PO</a>';
	return;
}

$items = $conn->query("SELECT * FROM `purchase_order_items` WHERE purchase_order_id = '{$id}' ORDER BY id ASC");
?>
<div class="card card-outline card-success shadow-sm">
	<div class="card-header">
		<h3 class="card-title font-weight-bold text-success m-0"><i class="fas fa-truck-loading"></i> Receive Goods — <?php echo htmlspecialchars($po['po_no']) ?></h3>
	</div>
	<div class="card-body">
		<form id="receive-form">
			<input type="hidden" name="purchase_order_id" value="<?php echo $id ?>">
			<div class="row mb-3">
				<div class="col-md-6"><b>Vendor:</b> <?php echo htmlspecialchars($po['vendor_name']) ?></div>
				<div class="col-md-3 form-group mb-0">
					<label class="control-label small mb-0">Receipt Date</label>
					<input type="date" name="receipt_date" class="form-control form-control-sm rounded-0" value="<?php echo date('Y-m-d') ?>" required>
				</div>
			</div>

			<div class="table-responsive">
				<table class="table table-bordered table-sm">
					<thead class="bg-light">
						<tr>
							<th width="40%">Item</th>
							<th class="text-center">Ordered</th>
							<th class="text-center">Already Received</th>
							<th class="text-center">Outstanding</th>
							<th width="18%" class="text-center">Receive Now</th>
						</tr>
					</thead>
					<tbody>
						<?php $has_outstanding = false; while($it = $items->fetch_assoc()):
							$outstanding = (float)$it['qty'] - (float)$it['qty_received'];
							$fmt = fn($n) => rtrim(rtrim(number_format((float)$n,2),'0'),'.');
						?>
						<tr class="<?php echo $outstanding <= 0 ? 'text-muted bg-light' : '' ?>">
							<td>
								<b><?php echo htmlspecialchars($it['item_name']) ?></b>
								<input type="hidden" name="po_item_id[]" value="<?php echo $it['id'] ?>">
							</td>
							<td class="text-center"><?php echo $fmt($it['qty']) ?></td>
							<td class="text-center"><?php echo $fmt($it['qty_received']) ?></td>
							<td class="text-center font-weight-bold"><?php echo $fmt($outstanding) ?></td>
							<td>
								<?php if($outstanding > 0): $has_outstanding = true; ?>
								<input type="number" step="any" min="0" max="<?php echo $outstanding ?>" name="receive_qty[]" class="form-control form-control-sm rounded-0 text-center" value="<?php echo $outstanding ?>">
								<input type="text" name="batch_no[]" maxlength="60" class="form-control form-control-sm rounded-0 mt-1" placeholder="Batch / lot (if tracked)">
								<input type="date" name="expiry_date[]" class="form-control form-control-sm rounded-0 mt-1" title="Expiry date (if tracked)">
								<?php else: ?>
								<input type="hidden" name="receive_qty[]" value="0">
								<input type="hidden" name="batch_no[]" value="">
								<input type="hidden" name="expiry_date[]" value="">
								<span class="badge badge-success">Fully received</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endwhile; ?>
					</tbody>
				</table>
			</div>

			<div class="form-group">
				<label class="control-label small">Notes</label>
				<textarea name="notes" rows="2" class="form-control form-control-sm rounded-0" placeholder="Receiving notes (optional)..."></textarea>
			</div>

			<div class="text-right border-top pt-3">
				<?php if($has_outstanding): ?>
				<button type="submit" class="btn btn-success btn-flat"><i class="fas fa-check"></i> Confirm Receipt &amp; Update Stock</button>
				<?php else: ?>
				<span class="text-muted mr-2">Nothing outstanding to receive.</span>
				<?php endif; ?>
				<a href="./?page=purchases/view&id=<?php echo $id ?>" class="btn btn-default btn-flat"><i class="fas fa-arrow-left"></i> Cancel</a>
			</div>
		</form>
	</div>
</div>
<script>
	$(document).ready(function(){
		$('#receive-form').submit(function(e){
			e.preventDefault();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=receive_goods",
				data: new FormData($(this)[0]),
				cache:false, contentType:false, processData:false,
				method:'POST', dataType:'json',
				error:err=>{ console.log(err); alert_toast("An error occurred",'error'); end_loader(); },
				success:function(resp){
					if(typeof resp=='object' && resp.status=='success'){
						location.href = './?page=purchases/view&id=<?php echo $id ?>';
					}else{
						alert_toast(resp.msg || "An error occurred",'error'); end_loader();
					}
				}
			})
		});
	});
</script>
