<?php
require_once(__DIR__ . '/../../config.php');

if(!$_settings->can_access('roles_manage')){
	echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>You don\'t have permission to manage roles.</div>';
	return;
}

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $role_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `roles` where id = '{$role_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

$permissions_list = \App\Support\Permissions::parse(isset($permissions) ? (string)$permissions : '');
$permission_groups = \App\Support\Permissions::groups();
?>

<div class="container-fluid">
	<form action="" id="role-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<input type="hidden" name="csrf_token" value="<?php echo $_settings->csrf_token() ?>">

		<div class="form-group">
			<label for="name" class="control-label">Role Title / Name</label>
			<input type="text" name="name" id="name" class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" placeholder="e.g. Accountant, Sales Manager, HR Officer, Warehouse Clerk" required/>
		</div>

		<div class="form-group">
			<label for="description" class="control-label">Description</label>
			<textarea name="description" id="description" rows="2" class="form-control form-control-sm rounded-0" placeholder="Role duties or access scope..."><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
		</div>

		<div class="form-group">
			<div class="d-flex align-items-center justify-content-between">
				<label class="control-label font-weight-bold text-navy m-0"><i class="fas fa-user-shield"></i> Custom Permission Matrix</label>
				<div class="btn-group btn-group-sm">
					<button type="button" class="btn btn-outline-secondary" id="perm-select-all"><i class="fas fa-check-double mr-1"></i> Select all</button>
					<button type="button" class="btn btn-outline-secondary" id="perm-clear-all"><i class="fas fa-times mr-1"></i> Clear</button>
				</div>
			</div>
			<small class="text-muted d-block mb-2">Super administrators always have full access regardless of this matrix.</small>

			<div class="card card-outline card-navy p-3">
				<?php $g_i = 0; foreach($permission_groups as $group_label => $perms): $g_i++; ?>
					<h6 class="font-weight-bold text-primary border-bottom pb-1 <?php echo $g_i > 1 ? 'mt-2' : '' ?>">
						<i class="fas fa-layer-group mr-1"></i> <?php echo htmlspecialchars($group_label) ?>
					</h6>
					<div class="row mb-3">
						<?php foreach($perms as $perm_key => $perm_label):
							$dom_id = 'p_' . preg_replace('/[^a-z0-9_]/i', '_', $perm_key); ?>
							<div class="col-md-6">
								<div class="custom-control custom-checkbox mb-1">
									<input type="checkbox" class="custom-control-input perm-check" id="<?php echo $dom_id ?>" name="permissions[]" value="<?php echo htmlspecialchars($perm_key) ?>" <?php echo in_array($perm_key, $permissions_list, true) ? 'checked' : '' ?>>
									<label class="custom-control-label" for="<?php echo $dom_id ?>"><?php echo htmlspecialchars($perm_label) ?></label>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="1" <?php echo !isset($status) || $status == 1 ? 'selected' : '' ?>>Active</option>
				<option value="0" <?php echo isset($status) && $status == 0 ? 'selected' : '' ?>>Inactive</option>
			</select>
		</div>
	</form>
</div>

<script>
	$(document).ready(function(){
		$('#perm-select-all').on('click', function(){ $('.perm-check').prop('checked', true); });
		$('#perm-clear-all').on('click', function(){ $('.perm-check').prop('checked', false); });
		$('#role-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_role",
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