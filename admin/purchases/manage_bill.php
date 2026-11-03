<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();
$default_tax_rate = number_format((new \App\Services\TaxService($conn, $_settings))->defaultRate('purchase'), 2, '.', '');

$prefill_items = [];
$purchase_order_id = '';

// Editing an existing bill
if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $bill_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `vendor_bill_list` where id = '{$bill_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){ $$k = $v; }
        $bi = $conn->query("SELECT * FROM `vendor_bill_items` WHERE vendor_bill_id = '{$bill_id_clean}' ORDER BY id ASC");
        while($r = $bi->fetch_assoc()){ $prefill_items[] = $r; }
    }
}
// Creating a new bill from a PO
elseif(isset($_GET['po_id']) && is_numeric($_GET['po_id']) && $_GET['po_id'] > 0){
    $po_id_clean = intval($_GET['po_id']);
    $po = $conn->query("SELECT * FROM `purchase_order_list` WHERE id = '{$po_id_clean}' AND tenant_id = '{$tenant_id}'");
    if($po && $po->num_rows > 0){
        $po = $po->fetch_assoc();
        $vendor_id = $po['vendor_id'];
        $purchase_order_id = $po['id'];
        $subtotal = $po['subtotal']; $tax_amount = $po['tax_amount']; $discount = $po['discount']; $total = $po['total'];
        $poi = $conn->query("SELECT * FROM `purchase_order_items` WHERE purchase_order_id = '{$po_id_clean}' ORDER BY id ASC");
        while($r = $poi->fetch_assoc()){
            $prefill_items[] = [
                'product_id' => $r['product_id'],
                'item_name'  => $r['item_name'],
                'description'=> $r['description'],
                'qty'        => $r['qty'],
                'unit_price' => $r['unit_price'],
                'tax_rate'   => $r['tax_rate'],
                'amount'     => $r['amount'],
            ];
        }
    }
}

if (!isset($bill_no) || empty($bill_no)) { $bill_no = 'BILL-' . date('Ym') . '-' . rand(1000, 9999); }
if (empty($prefill_items)) {
    $prefill_items[] = ['product_id'=>null,'item_name'=>'','description'=>'','qty'=>1,'unit_price'=>'0.00','tax_rate'=>$default_tax_rate,'amount'=>'0.00'];
}

$products_qry = $conn->query("SELECT * FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
$products_data = [];
while($p = $products_qry->fetch_assoc()){ $products_data[$p['id']] = $p; }
?>
<style>
    @media (max-width: 767.98px) {
        .card-body { padding: 0.75rem !important; }
        .btn-mobile-block { width: 100%; margin-bottom: 0.5rem; }
    }
</style>
<div class="card card-outline card-navy shadow-sm">
	<div class="card-header py-2">
		<h3 class="card-title font-weight-bold text-navy m-0"><i class="fas fa-file-invoice-dollar"></i> <?= isset($id) ? 'Edit Vendor Bill' : 'New Vendor Bill' ?></h3>
	</div>
	<div class="card-body">
		<form action="" id="vendor-bill-form">
			<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
			<input type="hidden" name="purchase_order_id" value="<?php echo htmlspecialchars((string)($purchase_order_id ?? '')) ?>">

			<div class="row">
				<div class="col-md-4 col-12 form-group">
					<label class="control-label">Vendor</label>
					<select name="vendor_id" id="vendor_id" class="form-control form-control-sm rounded-0 select2" required>
						<option value="" disabled <?php echo !isset($vendor_id) ? 'selected' : '' ?>></option>
						<?php
						$parties = $conn->query("SELECT * FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY (type='vendor') DESC, name ASC");
						while($p = $parties->fetch_assoc()):
						?>
						<option value="<?php echo $p['id'] ?>" <?php echo isset($vendor_id) && $vendor_id == $p['id'] ? 'selected' : '' ?>><?php echo $p['party_code'].' - '.$p['name'] ?></option>
						<?php endwhile; ?>
					</select>
				</div>
				<div class="col-md-2 col-6 form-group">
					<label class="control-label">Bill No</label>
					<input type="text" name="bill_no" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($bill_no) ?>" required>
				</div>
				<div class="col-md-2 col-6 form-group">
					<label class="control-label">Vendor Ref</label>
					<input type="text" name="vendor_ref" class="form-control form-control-sm rounded-0" value="<?php echo isset($vendor_ref) ? htmlspecialchars($vendor_ref) : '' ?>" placeholder="Supplier invoice #">
				</div>
				<div class="col-md-2 col-6 form-group">
					<label class="control-label">Bill Date</label>
					<input type="date" name="bill_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($bill_date) ? $bill_date : date('Y-m-d') ?>" required>
				</div>
				<div class="col-md-2 col-6 form-group">
					<label class="control-label">Due Date</label>
					<input type="date" name="due_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($due_date) ? $due_date : date('Y-m-d', strtotime('+30 days')) ?>" required>
				</div>
			</div>

			<hr class="my-2">
			<div class="d-flex justify-content-between align-items-center mb-2">
				<h6 class="font-weight-bold text-navy m-0"><i class="fas fa-list"></i> Bill Lines</h6>
				<button type="button" class="btn btn-xs btn-success" id="add_item_row"><i class="fas fa-plus"></i> Add Line</button>
			</div>

			<div class="table-responsive mb-3">
				<table class="table table-bordered table-sm m-0" id="items-table">
					<thead class="bg-light">
						<tr>
							<th width="35%">Item</th>
							<th width="10%">Qty</th>
							<th width="15%">Unit Cost</th>
							<th width="10%">Tax (%)</th>
							<th width="15%" class="text-right">Amount</th>
							<th width="5%" class="text-center">Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($prefill_items as $item): ?>
						<tr>
							<td>
								<select name="product_id[]" class="form-control form-control-sm rounded-0 product-select">
									<option value="">Custom Item</option>
									<?php foreach($products_data as $pid => $prod): ?>
									<option value="<?= $pid ?>" data-price="<?= $prod['cost_price'] ?>" data-name="<?= htmlspecialchars($prod['name']) ?>" data-desc="<?= htmlspecialchars($prod['description'] ?? '') ?>" <?= $item['product_id'] == $pid ? 'selected' : '' ?>>
										<?= $prod['product_code'] ?> - <?= $prod['name'] ?>
									</option>
									<?php endforeach; ?>
								</select>
								<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 mt-1 item-name" value="<?= htmlspecialchars($item['item_name']) ?>" placeholder="Item Name" required>
								<input type="hidden" name="item_desc[]" class="item-desc" value="<?= htmlspecialchars($item['description'] ?? '') ?>">
							</td>
							<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="<?= $item['qty'] ?>" required></td>
							<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="<?= $item['unit_price'] ?>" required></td>
							<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="<?= $item['tax_rate'] ?>"></td>
							<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="<?= $item['amount'] ?>" readonly></td>
							<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="row">
				<div class="col-md-6 col-12 form-group">
					<label class="control-label">Notes</label>
					<textarea name="notes" rows="3" class="form-control form-control-sm rounded-0"><?php echo isset($notes) ? htmlspecialchars($notes) : '' ?></textarea>
				</div>
				<div class="col-md-6 col-12">
					<div class="card bg-light border p-3">
						<div class="d-flex justify-content-between mb-2"><span class="font-weight-bold">Subtotal:</span><input type="number" step="any" name="subtotal" id="subtotal" class="form-control form-control-sm text-right bg-white rounded-0 border-0 font-weight-bold" style="width:140px;" value="<?php echo isset($subtotal) ? $subtotal : '0.00' ?>" readonly></div>
						<div class="d-flex justify-content-between mb-2"><span class="font-weight-bold">Tax / VAT:</span><input type="number" step="any" name="tax_amount" id="tax_amount" class="form-control form-control-sm text-right bg-white rounded-0 border-0 font-weight-bold" style="width:140px;" value="<?php echo isset($tax_amount) ? $tax_amount : '0.00' ?>" readonly></div>
						<div class="d-flex justify-content-between mb-2 align-items-center"><span class="font-weight-bold">Discount:</span><input type="number" step="any" name="discount" id="discount" class="form-control form-control-sm text-right rounded-0 calc-totals" style="width:140px;" value="<?php echo isset($discount) ? $discount : '0.00' ?>"></div>
						<hr class="my-2">
						<div class="d-flex justify-content-between align-items-center"><h5 class="font-weight-bold text-navy m-0">Total<?php echo $_settings->currency_tag() ?>:</h5><input type="number" step="any" name="total" id="total" class="form-control form-control-lg text-right bg-white rounded-0 border-0 font-weight-bold text-primary" style="width:160px; font-size:1.4rem;" value="<?php echo isset($total) ? $total : '0.00' ?>" readonly></div>
					</div>
				</div>
			</div>

			<div class="card-footer text-right bg-transparent border-top mt-3 px-0">
				<input type="hidden" name="status" id="bill_status" value="<?php echo isset($status) ? $status : 'unpaid' ?>">
				<button class="btn btn-outline-secondary btn-flat btn-mobile-block" type="submit" onclick="$('#bill_status').val('draft')"><i class="fas fa-save"></i> Save Draft</button>
				<button class="btn btn-primary bg-navy btn-flat btn-mobile-block" type="submit" onclick="$('#bill_status').val('unpaid')"><i class="fas fa-check-circle"></i> Post Bill (A/P)</button>
				<a href="./?page=purchases/vendor_bills" class="btn btn-default btn-flat btn-mobile-block"><i class="fas fa-arrow-left"></i> Cancel</a>
			</div>
		</form>
	</div>
</div>
<script>
	function calculate_bill_totals(){
		var subtotal = 0, total_tax = 0;
		$('#items-table tbody tr').each(function(){
			var qty = parseFloat($(this).find('.qty').val()) || 0;
			var price = parseFloat($(this).find('.price').val()) || 0;
			var tax_rate = parseFloat($(this).find('.tax_rate').val()) || 0;
			var line_sub = qty * price;
			var line_tax = line_sub * (tax_rate / 100);
			$(this).find('.amount').val((line_sub + line_tax).toFixed(2));
			subtotal += line_sub; total_tax += line_tax;
		});
		var discount = parseFloat($('#discount').val()) || 0;
		$('#subtotal').val(subtotal.toFixed(2));
		$('#tax_amount').val(total_tax.toFixed(2));
		$('#total').val(((subtotal + total_tax) - discount).toFixed(2));
	}
	$(document).ready(function(){
		$('.select2').select2({ placeholder:"Select vendor", width:'100%' });
		$(document).on('change', '.product-select', function(){
			var o = $(this).find(':selected'), row = $(this).closest('tr');
			if(o.attr('data-price')) row.find('.price').val(parseFloat(o.attr('data-price')).toFixed(2));
			if(o.attr('data-name')) row.find('.item-name').val(o.attr('data-name'));
			if(o.attr('data-desc')) row.find('.item-desc').val(o.attr('data-desc'));
			calculate_bill_totals();
		});
		$('#add_item_row').click(function(){
			var opts = $('.product-select').first().html();
			$('#items-table tbody').append(`<tr>
				<td><select name="product_id[]" class="form-control form-control-sm rounded-0 product-select">${opts}</select>
				<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 mt-1 item-name" placeholder="Item Name" required>
				<input type="hidden" name="item_desc[]" class="item-desc" value=""></td>
				<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="1" required></td>
				<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="0.00" required></td>
				<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="<?= $default_tax_rate ?>"></td>
				<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="0.00" readonly></td>
				<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td></tr>`);
		});
		$(document).on('click', '.remove_row', function(){
			if($('#items-table tbody tr').length > 1){ $(this).closest('tr').remove(); calculate_bill_totals(); }
			else { alert_toast("A bill must contain at least one line.", 'warning'); }
		});
		$(document).on('input change', '.calc-item, .calc-totals', calculate_bill_totals);
		calculate_bill_totals();
		$('#vendor-bill-form').submit(function(e){
			e.preventDefault();
			var _this = $(this); $('.err-msg').remove(); start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_vendor_bill",
				data: new FormData($(this)[0]), cache:false, contentType:false, processData:false,
				method:'POST', dataType:'json',
				error:err=>{ console.log(err); alert_toast("An error occurred",'error'); end_loader(); },
				success:function(resp){
					if(typeof resp=='object' && resp.status=='success'){ location.href='./?page=purchases/vendor_bills'; }
					else if(resp.status=='failed' && !!resp.msg){ var el=$('<div>').addClass("alert alert-danger err-msg").text(resp.msg); _this.prepend(el); el.show('slow'); end_loader(); }
					else{ alert_toast("An error occurred",'error'); end_loader(); }
				}
			})
		});
	});
</script>
