<?php
require_once('../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $key_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `api_keys` where id = '{$key_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

$permissions_list = isset($permissions) ? explode(',', $permissions) : ['*'];
?>

<div class="container-fluid">
	<form action="" id="api-key-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

		<div class="form-group">
			<label for="name" class="control-label">Integration / Application Name</label>
			<input type="text" name="name" id="name" class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" placeholder="e.g. WooCommerce Store, POS Mobile App, Third-party HR" required/>
		</div>

		<?php if(isset($api_key)): ?>
		<div class="form-group bg-light p-3 border rounded">
			<label class="control-label text-navy font-weight-bold"><i class="fas fa-key"></i> Active API Key</label>
			<div class="input-group">
				<input type="text" class="form-control form-control-sm rounded-0 font-weight-bold" value="<?php echo htmlspecialchars($api_key) ?>" readonly id="key_field">
				<div class="input-group-append">
					<button class="btn btn-sm btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText($('#key_field').val()); alert_toast('API Key copied!', 'info');"><i class="fas fa-copy"></i> Copy</button>
				</div>
			</div>
			<small class="text-muted d-block mt-1">API Secret Token: <code><?php echo htmlspecialchars($api_secret ?? 'Hidden') ?></code></small>
		</div>
		<?php endif; ?>

		<div class="form-group">
			<label class="control-label font-weight-bold text-navy"><i class="fas fa-shield-alt"></i> Granular Scope Permissions</label>
			<div class="row">
				<div class="col-md-6">
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input" id="perm_all" name="permissions[]" value="*" <?php echo in_array('*', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label font-weight-bold" for="perm_all">Full Access (*)</label>
					</div>
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_read_products" name="permissions[]" value="read_products" <?php echo in_array('read_products', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_read_products">Read Products & Inventory</label>
					</div>
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_write_products" name="permissions[]" value="write_products" <?php echo in_array('write_products', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_write_products">Write / Add Products</label>
					</div>
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_read_invoices" name="permissions[]" value="read_invoices" <?php echo in_array('read_invoices', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_read_invoices">Read Sales Invoices</label>
					</div>
				</div>
				<div class="col-md-6">
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_write_invoices" name="permissions[]" value="write_invoices" <?php echo in_array('write_invoices', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_write_invoices">Create Sales Invoices</label>
					</div>
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_read_payments" name="permissions[]" value="read_payments" <?php echo in_array('read_payments', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_read_payments">Read Payment Receipts</label>
					</div>
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_write_payments" name="permissions[]" value="write_payments" <?php echo in_array('write_payments', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_write_payments">Record Payment Receipts</label>
					</div>
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input perm-item" id="perm_read_journals" name="permissions[]" value="read_journals" <?php echo in_array('read_journals', $permissions_list) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="perm_read_journals">Read General Ledger</label>
					</div>
				</div>
			</div>
		</div>

		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="1" <?php echo !isset($status) || $status == 1 ? 'selected' : '' ?>>Active</option>
				<option value="0" <?php echo isset($status) && $status == 0 ? 'selected' : '' ?>>Revoked / Inactive</option>
			</select>
		</div>
	</form>
</div>

<script>
	$(document).ready(function(){
		$('#perm_all').change(function(){
			if($(this).is(':checked')){
				$('.perm-item').prop('checked', false);
			}
		});

		$('.perm-item').change(function(){
			if($(this).is(':checked')){
				$('#perm_all').prop('checked', false);
			}
		});

		$('#api-key-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_api_key",
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