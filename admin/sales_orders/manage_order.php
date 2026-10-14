<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();

$page_type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $so_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `sales_order_list` where id = '{$so_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

// Determine default doc type and prefix if not editing existing
if (!isset($type) || empty($type)) {
    if ($page_type === 'po' || $page_type === 'purchase_order') {
        $type = 'purchase_order';
    } elseif ($page_type === 'quote') {
        $type = 'quote';
    } else {
        $type = 'proforma';
    }
}

$is_po = ($page_type === 'po' || $page_type === 'purchase_order' || (isset($type) && ($type === 'po' || $type === 'purchase_order')));

if (!isset($order_no) || empty($order_no)) {
    $prefix = $is_po ? 'PO-' : 'SO-';
    $order_no = $prefix . date('Ym') . '-' . rand(1000, 9999);
}

// Preload active product catalog for fast product select
$products_qry = $conn->query("SELECT * FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
$products_data = [];
while($p = $products_qry->fetch_assoc()){
    $products_data[$p['id']] = $p;
}
?>

<style>
    @media (max-width: 767.98px) {
        .card-body { padding: 0.75rem !important; }
        .table-responsive { border: 0; }
        .btn-mobile-block { width: 100%; margin-bottom: 0.5rem; }
    }
</style>

<div class="card card-outline card-primary shadow-sm">
	<div class="card-header py-2">
		<h3 class="card-title font-weight-bold text-primary m-0">
			<i class="fas <?= $is_po ? 'fa-shopping-bag text-secondary' : 'fa-mobile-alt text-primary' ?>"></i>
			<?= $is_po ? 'Purchase Order Creator' : 'On-The-Go Order, Quote & Proforma Creator' ?>
		</h3>
	</div>
	<div class="card-body">
		<form action="" id="sales-order-form">
			<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

			<div class="row">
				<div class="col-md-4 col-12 form-group">
					<label for="party_id" class="control-label"><?= $is_po ? 'Supplier / Vendor / Party' : 'Customer / Party' ?></label>
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
				<div class="col-md-3 col-6 form-group">
					<label for="order_no" class="control-label"><?= $is_po ? 'Purchase Order No' : 'Order / Quote / Proforma No' ?></label>
					<input type="text" name="order_no" id="order_no" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($order_no); ?>" required/>
				</div>
				<div class="col-md-3 col-6 form-group">
					<label for="order_date" class="control-label">Order Date</label>
					<input type="date" name="order_date" id="order_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($order_date) ? $order_date : date('Y-m-d'); ?>" required/>
				</div>
				<div class="col-md-2 col-12 form-group">
					<label for="type" class="control-label">Order Type</label>
					<select name="type" id="type" class="form-control form-control-sm rounded-0" required>
						<?php if ($is_po): ?>
							<option value="purchase_order" <?php echo $type === 'purchase_order' || $type === 'po' ? 'selected' : '' ?>>Purchase Order</option>
						<?php else: ?>
							<option value="proforma" <?php echo $type === 'proforma' ? 'selected' : '' ?>>Proforma Invoice</option>
							<option value="quote" <?php echo $type === 'quote' ? 'selected' : '' ?>>Sales Quote</option>
							<option value="sales_order" <?php echo $type === 'sales_order' ? 'selected' : '' ?>>Sales Order</option>
						<?php endif; ?>
					</select>
				</div>
			</div>

			<hr class="my-2">
			<div class="d-flex justify-content-between align-items-center mb-2">
				<h6 class="font-weight-bold text-navy m-0"><i class="fas fa-boxes"></i> Select Products / Items</h6>
				<button type="button" class="btn btn-xs btn-success" id="add_item_row"><i class="fas fa-plus"></i> Add Item Line</button>
			</div>

			<div class="table-responsive mb-3">
				<table class="table table-bordered table-sm m-0" id="items-table">
					<thead class="bg-light">
						<tr>
							<th width="30%">Product / Item</th>
							<th width="10%">Qty</th>
							<th width="15%">Price (FJD)</th>
							<th width="10%">Tax (%)</th>
							<th width="15%" class="text-right">Amount</th>
							<th width="5%" class="text-center">Action</th>
						</tr>
					</thead>
					<tbody>
						<?php
						if(isset($id)):
							$items = $conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}' ORDER BY id ASC");
							while($item = $items->fetch_assoc()):
						?>
						<tr>
							<td>
								<select name="product_id[]" class="form-control form-control-sm rounded-0 product-select select2-item">
									<option value="">Custom Item</option>
									<?php foreach($products_data as $pid => $prod): ?>
									<option value="<?= $pid ?>"
										data-price="<?= $is_po ? ($prod['buying_price'] ?? $prod['selling_price']) : $prod['selling_price'] ?>"
										data-name="<?= htmlspecialchars($prod['name']) ?>"
										data-stock="<?= $prod['stock_quantity'] ?>"
										<?= $item['product_id'] == $pid ? 'selected' : '' ?>>
										<?= $prod['product_code'] ?> - <?= $prod['name'] ?> (Stock: <?= $prod['stock_quantity'] ?>)
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
						<?php
							endwhile;
						else:
						?>
						<tr>
							<td>
								<select name="product_id[]" class="form-control form-control-sm rounded-0 product-select select2-item">
									<option value="">Select from Catalog or Custom</option>
									<?php foreach($products_data as $pid => $prod): ?>
									<option value="<?= $pid ?>"
										data-price="<?= $is_po ? ($prod['buying_price'] ?? $prod['selling_price']) : $prod['selling_price'] ?>"
										data-name="<?= htmlspecialchars($prod['name']) ?>"
										data-stock="<?= $prod['stock_quantity'] ?>">
										<?= $prod['product_code'] ?> - <?= $prod['name'] ?> (Stock: <?= $prod['stock_quantity'] ?>)
									</option>
									<?php endforeach; ?>
								</select>
								<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 mt-1 item-name" placeholder="Item Name" required>
								<input type="hidden" name="item_desc[]" class="item-desc" value="">
							</td>
							<td><input type="number" step="any" name="item_qty[]" class="form-control form-control-sm rounded-0 text-center calc-item qty" value="1" required></td>
							<td><input type="number" step="any" name="item_price[]" class="form-control form-control-sm rounded-0 text-right calc-item price" value="0.00" required></td>
							<td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-sm rounded-0 text-center calc-item tax_rate" value="15.00"></td>
							<td><input type="number" step="any" name="item_amount[]" class="form-control form-control-sm rounded-0 text-right amount bg-light" value="0.00" readonly></td>
							<td class="text-center align-middle"><button type="button" class="btn btn-xs btn-danger remove_row"><i class="fas fa-trash"></i></button></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="row">
				<div class="col-md-6 col-12 form-group">
					<label for="notes" class="control-label">Order Notes / Terms</label>
					<textarea name="notes" id="notes" rows="3" class="form-control form-control-sm rounded-0" placeholder="Special delivery instructions or order terms..."><?php echo isset($notes) ? $notes : ''; ?></textarea>
				</div>
				<div class="col-md-6 col-12">
					<div class="card bg-light border p-3">
						<div class="d-flex justify-content-between mb-2">
							<span class="font-weight-bold">Subtotal:</span>
							<input type="number" step="any" name="subtotal" id="subtotal" class="form-control form-control-sm text-right bg-white rounded-0 border-0 font-weight-bold" style="width: 140px;" value="<?php echo isset($subtotal) ? $subtotal : '0.00'; ?>" readonly>
						</div>
						<div class="d-flex justify-content-between mb-2">
							<span class="font-weight-bold">Tax / VAT Amount:</span>
							<input type="number" step="any" name="tax_amount" id="tax_amount" class="form-control form-control-sm text-right bg-white rounded-0 border-0 font-weight-bold" style="width: 140px;" value="<?php echo isset($tax_amount) ? $tax_amount : '0.00'; ?>" readonly>
						</div>
						<div class="d-flex justify-content-between mb-2 align-items-center">
							<span class="font-weight-bold">Discount:</span>
							<input type="number" step="any" name="discount" id="discount" class="form-control form-control-sm text-right rounded-0 calc-totals" style="width: 140px;" value="<?php echo isset($discount) ? $discount : '0.00'; ?>">
						</div>
						<hr class="my-2">
						<div class="d-flex justify-content-between align-items-center">
							<h5 class="font-weight-bold text-navy m-0">Total Amount (FJD):</h5>
							<input type="number" step="any" name="total" id="total" class="form-control form-control-lg text-right bg-white rounded-0 border-0 font-weight-bold text-primary" style="width: 160px; font-size: 1.4rem;" value="<?php echo isset($total) ? $total : '0.00'; ?>" readonly>
						</div>
					</div>
				</div>
			</div>

			<div class="card-footer text-right bg-transparent border-top mt-3 px-0">
				<input type="hidden" name="status" id="order_status" value="<?php echo isset($status) ? $status : ($is_po ? 'purchase_order' : 'proforma'); ?>">
				<?php if ($is_po): ?>
					<button class="btn btn-primary btn-flat btn-mobile-block" type="submit" onclick="$('#order_status').val('purchase_order')"><i class="fas fa-save"></i> Save Purchase Order</button>
				<?php else: ?>
					<button class="btn btn-info btn-flat btn-mobile-block" type="submit" onclick="$('#order_status').val('proforma')"><i class="fas fa-file-invoice"></i> Save Proforma / Quote</button>
					<button class="btn btn-success btn-flat btn-mobile-block" type="submit" onclick="$('#order_status').val('confirmed')"><i class="fas fa-check-circle"></i> Confirm Sale & Auto Adjust Stock</button>
				<?php endif; ?>
				<a href="./?page=sales_orders" class="btn btn-default btn-flat btn-mobile-block"><i class="fas fa-arrow-left"></i> Cancel</a>
			</div>
		</form>
	</div>
</div>

<script>
	function calculate_order_totals(){
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
		$('.select2').select2({ placeholder:"Select party", width:'100%' });

		$(document).on('change', '.product-select', function(){
			var option = $(this).find(':selected');
			var price = option.attr('data-price');
			var name = option.attr('data-name');
			var row = $(this).closest('tr');

			if(price){
				row.find('.price').val(parseFloat(price).toFixed(2));
			}
			if(name){
				row.find('.item-name').val(name);
			}
			calculate_order_totals();
		});

		$('#add_item_row').click(function(){
			var first_select_options = $('.product-select').first().html();
			var row = `<tr>
				<td>
					<select name="product_id[]" class="form-control form-control-sm rounded-0 product-select">
						${first_select_options}
					</select>
					<input type="text" name="item_name[]" class="form-control form-control-sm rounded-0 mt-1 item-name" placeholder="Item Name" required>
					<input type="hidden" name="item_desc[]" class="item-desc" value="">
				</td>
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
				calculate_order_totals();
			} else {
				alert_toast("Order must contain at least one product.", 'warning');
			}
		});

		$(document).on('input change', '.calc-item, .calc-totals', function(){
			calculate_order_totals();
		});

		calculate_order_totals();

		$('#sales-order-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_sales_order",
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
						location.href = './?page=sales_orders';
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
