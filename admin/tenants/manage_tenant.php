<?php
require_once(__DIR__ . '/../../config.php');
if(!$_settings->is_super_admin()){
    echo '<div class="alert alert-danger m-3">Only the developer can manage tenants.</div>';
    return;
}
$assigned_modules = null; // null = no restriction recorded yet (all modules on)
if(isset($_GET['id']) && is_numeric($_GET['id'])){
    $id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `tenants` where id = '{$id_clean}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
        if(array_key_exists('modules', $qry_row = $conn->query("SELECT * FROM `tenants` WHERE id = '{$id_clean}'")->fetch_assoc() ?: []) && $qry_row['modules'] !== null){
            $assigned_modules = \App\Support\Permissions::sanitizeTenantModules($qry_row['modules']);
        }
    }
}
?>
<div class="container-fluid">
	<form action="" id="tenant-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<div class="form-group">
			<label for="tenant_code" class="control-label">Tenant Code</label>
			<input type="text" name="tenant_code" id="tenant_code" class="form-control form-control-sm rounded-0" value="<?php echo isset($tenant_code) ? $tenant_code : 'TNT-'.sprintf('%03d', rand(1, 999)); ?>" required/>
		</div>
		<div class="form-group">
			<label for="name" class="control-label">Company / Tenant Name</label>
			<input type="text" name="name" id="name" class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? $name : ''; ?>" required/>
		</div>
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="email" class="control-label">Email</label>
				<input type="email" name="email" id="email" class="form-control form-control-sm rounded-0" value="<?php echo isset($email) ? $email : ''; ?>" />
			</div>
			<div class="col-md-6 form-group">
				<label for="phone" class="control-label">Phone</label>
				<input type="text" name="phone" id="phone" class="form-control form-control-sm rounded-0" value="<?php echo isset($phone) ? $phone : ''; ?>" />
			</div>
		</div>
		<div class="form-group">
			<label for="address" class="control-label">Address</label>
			<textarea name="address" id="address" rows="2" class="form-control form-control-sm rounded-0"><?php echo isset($address) ? $address : ''; ?></textarea>
		</div>
		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="1" <?php echo isset($status) && $status == 1 ? 'selected' : '' ?>>Active</option>
				<option value="0" <?php echo isset($status) && $status == 0 ? 'selected' : '' ?>>Inactive</option>
			</select>
		</div>
		<div class="form-group">
			<label class="control-label font-weight-bold"><i class="fas fa-puzzle-piece mr-1"></i> Modules assigned to this tenant</label>
			<small class="text-muted d-block mb-1">Only ticked modules appear for this tenant's users and can be used, regardless of their role.</small>
			<input type="hidden" name="modules_submitted" value="1">
			<div class="row">
			<?php foreach(\App\Support\Permissions::TENANT_MODULES as $mk => $mdef): ?>
				<div class="col-md-6">
					<div class="custom-control custom-checkbox mb-1">
						<input type="checkbox" class="custom-control-input" id="tm_<?php echo $mk ?>" name="modules[]" value="<?php echo $mk ?>" <?php echo ($assigned_modules === null || in_array($mk, $assigned_modules, true)) ? 'checked' : '' ?>>
						<label class="custom-control-label" for="tm_<?php echo $mk ?>"><?php echo htmlspecialchars($mdef['label']) ?></label>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
		</div>
	</form>
</div>
<script>
	$(document).ready(function(){
		$('#tenant-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			 $('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_tenant",
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
                        console.log(resp)
					}
				}
			})
		})
	})
</script>
