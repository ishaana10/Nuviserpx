<?php
require_once('../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $prod_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `product_list` where id = '{$prod_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
?>
<div class="container-fluid">
	<form action="" id="product-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="product_code" class="control-label">Product / SKU Code</label>
				<input type="text" name="product_code" id="product_code" class="form-control form-control-sm rounded-0" value="<?php echo isset($product_code) ? $product_code : 'SKU-'.date('Ym').'-'.rand(100,999); ?>" required/>
			</div>
			<div class="col-md-6 form-group">
				<label for="category" class="control-label">Category</label>
				<input type="text" name="category" id="category" class="form-control form-control-sm rounded-0" value="<?php echo isset($category) ? $category : ''; ?>" placeholder="e.g. Electronics, Hardware, Services" />
			</div>
		</div>

		<div class="form-group">
			<label for="name" class="control-label">Product Name</label>
			<input type="text" name="name" id="name" class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? $name : ''; ?>" required/>
		</div>

		<div class="row">
			<div class="col-md-4 form-group">
				<label for="unit" class="control-label">Unit of Measure</label>
				<input type="text" name="unit" id="unit" class="form-control form-control-sm rounded-0" value="<?php echo isset($unit) ? $unit : 'pcs'; ?>" placeholder="e.g. pcs, kg, box, hrs" required/>
			</div>
			<div class="col-md-4 form-group">
				<label for="cost_price" class="control-label">Cost Price (FJD)</label>
				<input type="number" step="any" name="cost_price" id="cost_price" class="form-control form-control-sm rounded-0 text-right" value="<?php echo isset($cost_price) ? $cost_price : '0.00'; ?>" required/>
			</div>
			<div class="col-md-4 form-group">
				<label for="selling_price" class="control-label">Selling Price (FJD)</label>
				<input type="number" step="any" name="selling_price" id="selling_price" class="form-control form-control-sm rounded-0 text-right font-weight-bold" value="<?php echo isset($selling_price) ? $selling_price : '0.00'; ?>" required/>
			</div>
		</div>

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="stock_quantity" class="control-label"><?php echo isset($id) ? 'Current Stock Quantity' : 'Initial Opening Stock Quantity' ?></label>
				<input type="number" step="any" name="stock_quantity" id="stock_quantity" class="form-control form-control-sm rounded-0 text-center font-weight-bold" value="<?php echo isset($stock_quantity) ? $stock_quantity : '0.00'; ?>" <?php echo isset($id) ? 'readonly' : '' ?> required/>
			</div>
			<div class="col-md-6 form-group">
				<label for="reorder_level" class="control-label">Reorder Warning Level</label>
				<input type="number" step="any" name="reorder_level" id="reorder_level" class="form-control form-control-sm rounded-0 text-center" value="<?php echo isset($reorder_level) ? $reorder_level : '10.00'; ?>" required/>
			</div>
		</div>

		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="1" <?php echo !isset($status) || $status == 1 ? 'selected' : '' ?>>Active</option>
				<option value="0" <?php echo isset($status) && $status == 0 ? 'selected' : '' ?>>Inactive</option>
			</select>
		</div>

		<div class="form-group">
			<label for="description" class="control-label">Description / Specifications</label>
			<textarea name="description" id="description" rows="2" class="form-control form-control-sm rounded-0"><?php echo isset($description) ? $description : ''; ?></textarea>
		</div>
	</form>
</div>
<script>
	$(document).ready(function(){
		$('#product-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_product",
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
		})
	})
</script>