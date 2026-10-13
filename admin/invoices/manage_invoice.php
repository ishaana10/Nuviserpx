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

// Fetch products for dropdown
$products_list = [];
$prod_qry = $conn->query("SELECT * FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
while($p = $prod_qry->fetch_assoc()){
    $products_list[] = $p;
}

// Fetch revenue/sales accounts
$accounts_list = [];
$acc_qry = $conn->query("SELECT * FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
while($a = $acc_qry->fetch_assoc()){
    $accounts_list[] = $a;
}
?>

<div class="card card-outline card-primary shadow-sm">
	<div class="card-header d-flex justify-content-between align-items-center">
		<h3 class="card-title m-0"><i class="fas fa-file-invoice text-primary mr-1"></i> <?php echo isset($id) ? 'Edit Invoice #'.htmlspecialchars($invoice_no) : 'Create New Invoice' ?></h3>
	</div>
	<div class="card-body">
		<form action="" id="invoice-form">
			<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

			<div class="row">
				<div class="col-md-4 form-group">
					<label for="party_id" class="control-label font-weight-bold">Customer / Party</label>
					<select name="party_id" id="party_id" class="form-control form-control-sm rounded-0 select2" required>
						<option value="" disabled <?php echo !isset($party_id) ? 'selected' : '' ?>></option>
						<?php
						$parties = $conn->query("SELECT * FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
						while($p = $parties->fetch_assoc()):
						?>
						<option value="<?php echo $p['id'] ?>" <?php echo isset($party_id) && $party_id == $p['id'] ? 'selected' : '' ?>>
							<?php echo htmlspecialchars($p['party_code'] . ' - ' . $p['name']) ?>
						</option>
						<?php endwhile; ?>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label for="invoice_no" class="control-label font-weight-bold">Invoice Number</label>
					<input type="text" name="invoice_no" id="invoice_no" class="form-control form-control-sm rounded-0" value="<?php echo isset($invoice_no) ? htmlspecialchars($invoice_no) : 'INV-'.date('Ym').'-'.rand(1000,9999); ?>" required/>
				</div>
				<div class="col-md-2 form-group">
					<label for="invoice_date" class="control-label font-weight-bold">Invoice Date</label>
					<input type="date" name="invoice_date" id="invoice_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($invoice_date) ? $invoice_date : date('Y-m-d'); ?>" required/>
				</div>
				<div class="col-md-3 form-group">
					<label for="due_date" class="control-label font-weight-bold">Due Date</label>
					<input type="date" name="due_date" id="due_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($due_date) ? $due_date : date('Y-m-d', strtotime('+30 days')); ?>" required/>
				</div>
			</div>

			<hr class="my-3">
			<div class="d-flex justify-content-between align-items-center mb-3">
				<h6 class="font-weight-bold text-navy m-0"><i class="fas fa-list mr-1"></i> Line Items</h6>
				<button type="button" class="btn btn-sm btn-outline-primary" id="btn-quick-new-item">
					<i class="fas fa-plus-circle mr-1"></i> Add / Create New Product
				</button>
			</div>

			<table class="table table-bordered table-sm" id="items-table">
				<thead class="bg-light">
					<tr>
						<th width="28%">Item / Product</th>
						<th width="22%">Description</th>
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
						<td>
							<div class="input-group input-group-sm">
								<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 item-name-input" value="<?php echo htmlspecialchars($item['item_name']) ?>" placeholder="Select or enter item" required>
								<div class="input-group-append">
									<button class="btn btn-outline-secondary dropdown-toggle btn-select-product" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fas fa-box"></i></button>
									<div class="dropdown-menu dropdown-menu-right product-dropdown-menu">
										<a class="dropdown-item quick-add-product-option text-primary font-weight-bold" href="javascript:void(0)"><i class="fas fa-plus"></i> + Create New Item</a>
										<div class="dropdown-divider"></div>
										<?php foreach($products_list as $prod): ?>
											<a class="dropdown-item select-product-item" href="javascript:void(0)" data-code="<?php echo htmlspecialchars($prod['product_code']) ?>" data-name="<?php echo htmlspecialchars($prod['name']) ?>" data-price="<?php echo $prod['selling_price'] ?>" data-desc="<?php echo htmlspecialchars($prod['description'] ?? '') ?>">
												<strong>[<?php echo htmlspecialchars($prod['product_code']) ?>]</strong> <?php echo htmlspecialchars($prod['name']) ?> ($<?php echo number_format($prod['selling_price'], 2) ?>)
											</a>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						</td>
						<td><input type="text" name="item_desc[]" class="form-control form-control-sm rounded-0 item-desc-input" value="<?php echo htmlspecialchars($item['description']) ?>"></td>
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
						<td>
							<div class="input-group input-group-sm">
								<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 item-name-input" placeholder="Product or Service Name" required>
								<div class="input-group-append">
									<button class="btn btn-outline-secondary dropdown-toggle btn-select-product" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fas fa-box"></i></button>
									<div class="dropdown-menu dropdown-menu-right product-dropdown-menu">
										<a class="dropdown-item quick-add-product-option text-primary font-weight-bold" href="javascript:void(0)"><i class="fas fa-plus"></i> + Create New Item</a>
										<div class="dropdown-divider"></div>
										<?php foreach($products_list as $prod): ?>
											<a class="dropdown-item select-product-item" href="javascript:void(0)" data-code="<?php echo htmlspecialchars($prod['product_code']) ?>" data-name="<?php echo htmlspecialchars($prod['name']) ?>" data-price="<?php echo $prod['selling_price'] ?>" data-desc="<?php echo htmlspecialchars($prod['description'] ?? '') ?>">
												<strong>[<?php echo htmlspecialchars($prod['product_code']) ?>]</strong> <?php echo htmlspecialchars($prod['name']) ?> ($<?php echo number_format($prod['selling_price'], 2) ?>)
											</a>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						</td>
						<td><input type="text" name="item_desc[]" class="form-control form-control-sm rounded-0 item-desc-input" placeholder="Optional description"></td>
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
					<label for="notes" class="control-label font-weight-bold">Notes / Terms & Conditions</label>
					<textarea name="notes" id="notes" rows="4" class="form-control form-control-sm rounded-0" placeholder="Payment terms, bank details, or notes..."><?php echo isset($notes) ? htmlspecialchars($notes) : 'Thank you for your business!'; ?></textarea>
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
				<button class="btn btn-primary btn-flat" type="submit"><i class="fas fa-save mr-1"></i> Save Invoice</button>
				<a href="./?page=invoices" class="btn btn-default btn-flat"><i class="fas fa-arrow-left mr-1"></i> Cancel</a>
			</div>
		</form>
	</div>
</div>

<!-- Modal for Quick Adding New Item / Product -->
<div class="modal fade" id="newItemModal" tabindex="-1" role="dialog" aria-labelledby="newItemModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
		<div class="modal-content shadow">
			<div class="modal-header bg-white border-bottom-0 pb-0">
				<h5 class="modal-title font-weight-bold" id="newItemModalLabel">New item</h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body pt-2">
				<form id="quick-item-form">
					<div class="row">
						<div class="col-md-6 form-group">
							<label for="modal_product_code" class="control-label font-weight-bold text-secondary">Code <span class="text-danger">(required)</span></label>
							<input type="text" name="product_code" id="modal_product_code" class="form-control rounded" required placeholder="e.g. SKU-1001">
						</div>
						<div class="col-md-6 form-group">
							<label for="modal_name" class="control-label font-weight-bold text-secondary">Name</label>
							<input type="text" name="name" id="modal_name" class="form-control rounded" required placeholder="Item name">
						</div>
					</div>

					<div class="form-group my-3">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="track_inventory" name="track_inventory" value="1" checked>
							<label class="custom-control-label font-weight-bold" for="track_inventory">Track inventory item</label>
						</div>
						<small class="text-muted d-block mt-1">
							Track the quantity and value of stock on hand. This option is suitable for organisations who purchase items before they are sold, and calculate the average cost of items.
						</small>
					</div>

					<div class="form-group my-3">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="purchase_flag" name="purchase_flag" value="1">
							<label class="custom-control-label font-weight-bold" for="purchase_flag">Purchase</label>
						</div>
						<small class="text-muted d-block">Add item to bills, purchase orders, and other purchase transactions</small>
					</div>

					<div class="form-group my-3">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="sell_flag" name="sell_flag" value="1" checked>
							<label class="custom-control-label font-weight-bold" for="sell_flag">Sell</label>
						</div>
						<small class="text-muted d-block">Add item to invoices, quotes, and other sales transactions</small>
					</div>

					<div class="row mt-3">
						<div class="col-md-4 form-group">
							<label for="modal_selling_price" class="control-label text-secondary font-weight-bold">Sale price</label>
							<input type="number" step="any" name="selling_price" id="modal_selling_price" class="form-control rounded" value="0.00" required>
						</div>
						<div class="col-md-4 form-group">
							<label for="modal_sales_account" class="control-label text-secondary font-weight-bold">Sales account</label>
							<select name="sales_account" id="modal_sales_account" class="form-control rounded">
								<option value="">Select Account...</option>
								<?php foreach($accounts_list as $acc): ?>
									<option value="<?php echo $acc['id'] ?>"><?php echo htmlspecialchars($acc['code'] . ' - ' . $acc['name']) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-4 form-group">
							<label for="modal_tax_rate" class="control-label text-secondary font-weight-bold">Tax rate (%)</label>
							<input type="number" step="any" name="tax_rate" id="modal_tax_rate" class="form-control rounded" value="15.00">
						</div>
					</div>

					<div class="form-group">
						<label for="modal_description" class="control-label text-secondary font-weight-bold">Description</label>
						<textarea name="description" id="modal_description" rows="2" class="form-control rounded" placeholder="Description for invoices and sales documents"></textarea>
					</div>
				</form>
			</div>
			<div class="modal-footer border-top-0 pt-0">
				<button type="button" class="btn btn-light border" data-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-primary px-4" id="btn-save-quick-item">Save</button>
			</div>
		</div>
	</div>
</div>

<script>
	var targetRowForNewItem = null;
	var productsData = <?php echo json_encode($products_list); ?>;

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

	function renderProductDropdownOptions(){
		var html = `<a class="dropdown-item quick-add-product-option text-primary font-weight-bold" href="javascript:void(0)"><i class="fas fa-plus"></i> + Create New Item</a><div class="dropdown-divider"></div>`;
		productsData.forEach(function(prod){
			html += `<a class="dropdown-item select-product-item" href="javascript:void(0)" data-code="${prod.product_code}" data-name="${prod.name}" data-price="${prod.selling_price}" data-desc="${prod.description || ''}">
				<strong>[${prod.product_code}]</strong> ${prod.name} ($${parseFloat(prod.selling_price).toFixed(2)})
			</a>`;
		});
		$('.product-dropdown-menu').html(html);
	}

	function openNewItemModal(targetTr){
		targetRowForNewItem = targetTr || null;
		$('#quick-item-form')[0].reset();
		var randCode = 'SKU-' + Math.floor(1000 + Math.random() * 9000);
		$('#modal_product_code').val(randCode);
		$('#modal_tax_rate').val('15.00');
		$('#newItemModal').modal('show');
	}

	$(document).ready(function(){
		$('.select2').select2({
			placeholder:"Please select customer",
			width:'100%'
		});

		$('#add_item_row').click(function(){
			var row = `<tr>
				<td>
					<div class="input-group input-group-sm">
						<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 item-name-input" placeholder="Product or Service Name" required>
						<div class="input-group-append">
							<button class="btn btn-outline-secondary dropdown-toggle btn-select-product" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fas fa-box"></i></button>
							<div class="dropdown-menu dropdown-menu-right product-dropdown-menu">`;
			row += `</div></div></td>
				<td><input type="text" name="item_desc[]" class="form-control form-control-sm rounded-0 item-desc-input" placeholder="Optional description"></td>
				<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="1" required></td>
				<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="0.00" required></td>
				<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="15.00"></td>
				<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="0.00" readonly></td>
				<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td>
			</tr>`;
			var $row = $(row);
			$('#items-table tbody').append($row);
			renderProductDropdownOptions();
		});

		$(document).on('click', '.remove_row', function(){
			if($('#items-table tbody tr').length > 1){
				$(this).closest('tr').remove();
				calculate_invoice_totals();
			} else {
				alert_toast("Invoice must have at least one line item.", 'warning');
			}
		});

		$(document).on('click', '.select-product-item', function(){
			var name = $(this).attr('data-name');
			var price = $(this).attr('data-price');
			var desc = $(this).attr('data-desc');

			var tr = $(this).closest('tr');
			tr.find('.item-name-input').val(name);
			tr.find('.item-desc-input').val(desc);
			tr.find('.price').val(parseFloat(price).toFixed(2));

			calculate_invoice_totals();
		});

		$(document).on('click', '.quick-add-product-option', function(){
			var tr = $(this).closest('tr');
			openNewItemModal(tr);
		});

		$('#btn-quick-new-item').click(function(){
			openNewItemModal(null);
		});

		$('#btn-save-quick-item').click(function(){
			var form = $('#quick-item-form');
			if(!form[0].checkValidity()){
				form[0].reportValidity();
				return;
			}

			start_loader();
			var formData = new FormData(form[0]);
			if(!formData.get('unit')) formData.append('unit', 'pcs');
			if(!formData.get('cost_price')) formData.append('cost_price', '0.00');

			$.ajax({
				url: _base_url_ + "classes/Master.php?f=save_product",
				method: 'POST',
				data: formData,
				cache: false,
				contentType: false,
				processData: false,
				dataType: 'json',
				error: function(err){
					console.log(err);
					alert_toast("An error occurred while saving new item", "error");
					end_loader();
				},
				success: function(resp){
					end_loader();
					if(typeof resp === 'object' && resp.status === 'success'){
						alert_toast("New item created successfully", "success");
						$('#newItemModal').modal('hide');

						var newProd = resp.product || {
							product_code: $('#modal_product_code').val(),
							name: $('#modal_name').val(),
							selling_price: $('#modal_selling_price').val(),
							description: $('#modal_description').val()
						};

						productsData.push(newProd);
						renderProductDropdownOptions();

						// If created from a specific row or top button
						var target = targetRowForNewItem;
						if(!target || target.length === 0){
							target = $('#items-table tbody tr:last-child');
						}

						if(target && target.length > 0){
							target.find('.item-name-input').val(newProd.name);
							target.find('.item-desc-input').val(newProd.description || '');
							target.find('.price').val(parseFloat(newProd.selling_price || 0).toFixed(2));
							if($('#modal_tax_rate').val()){
								target.find('.tax_rate').val($('#modal_tax_rate').val());
							}
							calculate_invoice_totals();
						}
					} else {
						alert_toast(resp.msg || "An error occurred", "error");
					}
				}
			});
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
