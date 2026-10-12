<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $inv_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `invoice_list` where id = '{$inv_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
?>

<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-file-invoice text-primary"></i> <?php echo isset($id) ? 'Edit Invoice #'.$invoice_no : 'Create New Invoice' ?></h3>
	</div>
	<div class="card-body">
		<form action="" id="invoice-form">
			<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

			<div class="row">
				<div class="col-md-4 form-group">
					<label for="party_id" class="control-label">Customer / Party</label>
					<select name="party_id" id="party_id" class="form-control form-control-sm rounded-0 select2" required>
						<option value="" disabled <?php echo !isset($party_id) ? 'selected' : '' ?>></option>
						<?php
						$parties = $conn->query("SELECT * FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
						while($p = $parties->fetch_assoc()):
						?>
						<option value="<?php echo $p['id'] ?>" <?php echo isset($party_id) && $party_id == $p['id'] ? 'selected' : '' ?>>
							<?php echo $p['party_code'] . ' - ' . $p['name'] ?>
						</option>
						<?php endwhile; ?>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label for="invoice_no" class="control-label">Invoice Number</label>
					<input type="text" name="invoice_no" id="invoice_no" class="form-control form-control-sm rounded-0" value="<?php echo isset($invoice_no) ? $invoice_no : 'INV-'.date('Ym').'-'.rand(1000,9999); ?>" required/>
				</div>
				<div class="col-md-2 form-group">
					<label for="invoice_date" class="control-label">Invoice Date</label>
					<input type="date" name="invoice_date" id="invoice_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($invoice_date) ? $invoice_date : date('Y-m-d'); ?>" required/>
				</div>
				<div class="col-md-3 form-group">
					<label for="due_date" class="control-label">Due Date</label>
					<input type="date" name="due_date" id="due_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($due_date) ? $due_date : date('Y-m-d', strtotime('+30 days')); ?>" required/>
				</div>
			</div>

			<hr class="my-3">
			<h6 class="font-weight-bold text-navy mb-3"><i class="fas fa-list"></i> Line Items</h6>

			<table class="table table-bordered table-sm" id="items-table">
				<thead class="bg-light">
					<tr>
						<th width="25%">Item Name</th>
						<th width="25%">Description</th>
						<th width="10%">Qty</th>
						<th width="12%">Unit Price</th>
						<th width="10%">Tax Rate (%)</th>
						<th width="13%" class="text-right">Amount</th>
						<th width="5%" class="text-center"><button type="button" class="btn btn-xs btn-primary" id="add_item_row"><i class="fas fa-plus"></i></button></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if(isset($id)):
						$items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$id}' ORDER BY id ASC");
						while($item = $items->fetch_assoc()):
					?>
					<tr>
						<td><input type="text" name="item_name[]" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($item['item_name']) ?>" required></td>
						<td><input type="text" name="item_desc[]" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($item['description']) ?>"></td>
						<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="<?php echo $item['qty'] ?>" required></td>
						<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="<?php echo $item['unit_price'] ?>" required></td>
						<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="<?php echo $item['tax_rate'] ?>"></td>
						<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="<?php echo $item['amount'] ?>" readonly></td>
						<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td>
					</tr>
					<?php
						endwhile;
					else:
					?>
					<tr>
						<td><input type="text" name="item_name[]" class="form-control form-control-sm rounded-0" placeholder="Product or Service Name" required></td>
						<td><input type="text" name="item_desc[]" class="form-control form-control-sm rounded-0" placeholder="Optional description"></td>
						<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="1" required></td>
						<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="0.00" required></td>
						<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="15.00"></td>
						<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="0.00" readonly></td>
						<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="row mt-4">
				<div class="col-md-6 form-group">
					<label for="notes" class="control-label">Notes / Terms & Conditions</label>
					<textarea name="notes" id="notes" rows="4" class="form-control form-control-sm rounded-0" placeholder="Payment terms, bank details, or notes..."><?php echo isset($notes) ? $notes : 'Thank you for your business!'; ?></textarea>
				</div>
				<div class="col-md-6">
					<div class="card bg-light border p-3">
						<div class="d-flex justify-content-between mb-2">
							<span class="font-weight-bold">Subtotal:</span>
							<input type="number" step="any" name="subtotal" id="subtotal" class="form-control form-control-sm text-right bg-white rounded-0 border-0 font-weight-bold" style="width: 150px;" value="<?php echo isset($subtotal) ? $subtotal : '0.00'; ?>" readonly>
						</div>
						<div class="d-flex justify-content-between mb-2">
							<span class="font-weight-bold">Tax / VAT Amount:</span>
							<input type="number" step="any" name="tax_amount" id="tax_amount" class="form-control form-control-sm text-right bg-white rounded-0 border-0 font-weight-bold" style="width: 150px;" value="<?php echo isset($tax_amount) ? $tax_amount : '0.00'; ?>" readonly>
						</div>
						<div class="d-flex justify-content-between mb-2 align-items-center">
							<span class="font-weight-bold">Discount:</span>
							<input type="number" step="any" name="discount" id="discount" class="form-control form-control-sm text-right rounded-0 calc-totals" style="width: 150px;" value="<?php echo isset($discount) ? $discount : '0.00'; ?>">
						</div>
						<hr class="my-2">
						<div class="d-flex justify-content-between align-items-center">
							<h5 class="font-weight-bold text-navy m-0">Grand Total (FJD):</h5>
							<input type="number" step="any" name="total" id="total" class="form-control form-control-lg text-right bg-white rounded-0 border-0 font-weight-bold text-success" style="width: 180px; font-size: 1.4rem;" value="<?php echo isset($total) ? $total : '0.00'; ?>" readonly>
						</div>
					</div>
				</div>
			</div>

			<div class="card-footer text-right bg-transparent border-top mt-3">
				<button class="btn btn-primary btn-flat" type="submit"><i class="fas fa-save"></i> Save Invoice</button>
				<a href="./?page=invoices" class="btn btn-default btn-flat"><i class="fas fa-arrow-left"></i> Cancel</a>
			</div>
		</form>
	</div>
</div>

<script>
	function calculate_invoice_totals(){
		var subtotal = 0;
		var total_tax = 0;

		$('#items-table tbody tr').each(function(){
			var qty = parseFloat($(this).find('.qty').val()) || 0;
			var price = parseFloat($(this).find('.price').val()) || 0;
			var tax_rate = parseFloat($(this).find('.tax_rate').val()) || 0;

			var line_sub = qty * price;
			var line_tax = line_sub * (tax_rate / 100);
			var line_total = line_sub + line_tax;

			$(this).find('.amount').val(line_total.toFixed(2));

			subtotal += line_sub;
			total_tax += line_tax;
		});

		var discount = parseFloat($('#discount').val()) || 0;
		var grand_total = (subtotal + total_tax) - discount;

		$('#subtotal').val(subtotal.toFixed(2));
		$('#tax_amount').val(total_tax.toFixed(2));
		$('#total').val(grand_total.toFixed(2));
	}

	$(document).ready(function(){
		$('.select2').select2({
			placeholder:"Please select customer",
			width:'100%'
		});

		$('#add_item_row').click(function(){
			var row = `<tr>
				<td><input type="text" name="item_name[]" class="form-control form-control-sm rounded-0" placeholder="Product or Service Name" required></td>
				<td><input type="text" name="item_desc[]" class="form-control form-control-sm rounded-0" placeholder="Optional description"></td>
				<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="1" required></td>
				<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="0.00" required></td>
				<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="15.00"></td>
				<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="0.00" readonly></td>
				<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td>
			</tr>`;
			$('#items-table tbody').append(row);
		});

		$(document).on('click', '.remove_row', function(){
			if($('#items-table tbody tr').length > 1){
				$(this).closest('tr').remove();
				calculate_invoice_totals();
			} else {
				alert_toast("Invoice must have at least one line item.", 'warning');
			}
		});

		$(document).on('input change', '.calc-item, .calc-totals', function(){
			calculate_invoice_totals();
		});

		calculate_invoice_totals();

		$('#invoice-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_invoice",
				data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
				error:err=>{
					console.log(err)
					alert_toast("An error occurred",'error');
					end_loader();
				},
				success:function(resp){
					if(typeof resp =='object' && resp.status == 'success'){
						location.href = './?page=invoices';
					}else if(resp.status == 'failed' && !!resp.msg){
                        var el = $('<div>')
                            el.addClass("alert alert-danger err-msg").text(resp.msg)
                            _this.prepend(el)
                            el.show('slow')
                            end_loader()
                    }else{
						alert_toast("An error occurred",'error');
						end_loader();
					}
				}
			})
		});
	});
</script>