<?php
require_once('../../config.php');

$tenant_id = $_settings->active_tenant_id();
$preset_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
?>

<div class="container-fluid">
	<form action="" id="adjustment-form">
		<div class="form-group">
			<label for="product_id" class="control-label">Product</label>
			<select name="product_id" id="product_id" class="form-control form-control-sm rounded-0 select2" required>
				<option value="" disabled <?php echo empty($preset_product_id) ? 'selected' : '' ?>></option>
				<?php
				$products = $conn->query("SELECT * FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
				while($p = $products->fetch_assoc()):
				?>
				<option value="<?php echo $p['id'] ?>" data-stock="<?php echo $p['stock_quantity'] ?>" data-cost="<?php echo $p['cost_price'] ?>" <?php echo $preset_product_id == $p['id'] ? 'selected' : '' ?>>
					<?php echo $p['product_code'] . ' - ' . $p['name'] ?> (Current Stock: <?php echo $p['stock_quantity'] . ' ' . $p['unit'] ?>)
				</option>
				<?php endwhile; ?>
			</select>
		</div>

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="type" class="control-label">Adjustment Type</label>
				<select name="type" id="type" class="form-control form-control-sm rounded-0" required>
					<option value="in">Stock In (Purchase / Restock)</option>
					<option value="out">Stock Out (Damage / Loss / Internal Use)</option>
					<option value="adjustment">Stock Correction (Absolute Count)</option>
				</select>
			</div>
			<div class="col-md-6 form-group">
				<label for="qty" class="control-label">Quantity</label>
				<input type="number" step="any" name="qty" id="qty" class="form-control form-control-sm rounded-0 text-center font-weight-bold" value="1.00" required/>
			</div>
		</div>

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="unit_cost" class="control-label">Unit Cost Price (FJD)</label>
				<input type="number" step="any" name="unit_cost" id="unit_cost" class="form-control form-control-sm rounded-0 text-right" value="0.00"/>
			</div>
			<div class="col-md-6 form-group">
				<label for="reference" class="control-label">Reference No / Reason</label>
				<input type="text" name="reference" id="reference" class="form-control form-control-sm rounded-0" value="ADJ-<?php echo date('Ymd-His') ?>" placeholder="e.g. Stock Take / Restock / Damage" required/>
			</div>
		</div>

		<div class="form-group">
			<label for="notes" class="control-label">Notes / Explanation</label>
			<textarea name="notes" id="notes" rows="2" class="form-control form-control-sm rounded-0" placeholder="Optional notes..."></textarea>
		</div>
	</form>
</div>

<script>
	$(document).ready(function(){
		$('.select2').select2({ placeholder:"Select Product", width:'100%', dropdownParent:$('#uni_modal') });

		$('#product_id').change(function(){
			var cost = $(this).find(':selected').attr('data-cost');
			if(cost){
				$('#unit_cost').val(parseFloat(cost).toFixed(2));
			}
		});

		if($('#product_id').val()){
			$('#product_id').trigger('change');
		}

		$('#adjustment-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_stock_adjustment",
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
						location.reload()
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