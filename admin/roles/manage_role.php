<?php
require_once(__DIR__ . '/../../config.php');

if(!$_settings->is_super_admin()){
	echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>Only the developer (super administrator) can define roles.</div>';
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
$permission_matrix = \App\Support\Permissions::matrix();
$admin_groups      = \App\Support\Permissions::adminGroups();
$action_cols       = \App\Support\Permissions::ACTIONS; // view/create/edit/delete => labels
$special_groups    = \App\Support\Permissions::specialGroups();
$page_groups       = \App\Support\Permissions::pageGroups(); // discovered from the sidebar
$has = function($key) use ($permissions_list){ return in_array($key, $permissions_list, true); };
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
				<label class="control-label font-weight-bold text-navy m-0"><i class="fas fa-user-shield"></i> Module Permissions</label>
				<div class="btn-group btn-group-sm">
					<button type="button" class="btn btn-outline-secondary" id="perm-select-all"><i class="fas fa-check-double mr-1"></i> Select all</button>
					<button type="button" class="btn btn-outline-secondary" id="perm-clear-all"><i class="fas fa-times mr-1"></i> Clear</button>
				</div>
			</div>
			<small class="text-muted d-block mb-2">Grant View / Create / Edit / Delete per module. Any Create, Edit or Delete automatically allows View. Super administrators always have full access.</small>

			<div class="card card-outline card-navy p-0">
				<div class="table-responsive">
					<table class="table table-sm table-bordered m-0 perm-matrix">
						<thead class="bg-navy text-white text-center">
							<tr>
								<th class="text-left align-middle" style="min-width:220px;">Module</th>
								<?php foreach($action_cols as $act => $act_label): ?>
									<th class="align-middle" style="width:14%;">
										<div><?php echo htmlspecialchars($act_label) ?></div>
										<div class="custom-control custom-checkbox custom-control-inline mr-0 mt-1">
											<input type="checkbox" class="custom-control-input col-toggle" id="col_<?php echo $act ?>" data-action="<?php echo $act ?>">
											<label class="custom-control-label text-xs" for="col_<?php echo $act ?>">all</label>
										</div>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach($permission_matrix as $section => $modules): ?>
								<tr class="bg-light">
									<td colspan="<?php echo count($action_cols) + 1 ?>" class="font-weight-bold text-uppercase text-xs text-navy"><?php echo htmlspecialchars($section) ?></td>
								</tr>
								<?php foreach($modules as $mkey => $mod): ?>
									<tr>
										<td class="align-middle">
											<div class="custom-control custom-checkbox">
												<input type="checkbox" class="custom-control-input row-toggle" id="row_<?php echo $mkey ?>" data-module="<?php echo $mkey ?>">
												<label class="custom-control-label font-weight-bold" for="row_<?php echo $mkey ?>"><?php echo htmlspecialchars($mod['label']) ?></label>
											</div>
										</td>
										<?php foreach($action_cols as $act => $act_label):
											$key = $mod['actions'][$act] ?? null; ?>
											<td class="text-center align-middle">
												<?php if($key !== null): $dom_id = 'p_'.$mkey.'_'.$act; ?>
													<div class="custom-control custom-checkbox custom-control-inline mr-0">
														<input type="checkbox" class="custom-control-input perm-check" id="<?php echo $dom_id ?>"
															name="permissions[]" value="<?php echo htmlspecialchars($key) ?>"
															data-module="<?php echo $mkey ?>" data-action="<?php echo $act ?>"
															<?php echo $has($key) ? 'checked' : '' ?>>
														<label class="custom-control-label" for="<?php echo $dom_id ?>"></label>
													</div>
												<?php else: ?>
													<span class="text-muted">&mdash;</span>
												<?php endif; ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<?php if(!empty($special_groups)): ?>
		<div class="form-group">
			<label class="control-label font-weight-bold text-navy m-0 d-block mb-1"><i class="fas fa-key"></i> Special Actions</label>
			<small class="text-muted d-block mb-2">Sensitive actions that are <b>not</b> included by Create / Edit / Delete — grant each one deliberately. Untick to hide the matching buttons and block the action.</small>
			<div class="card card-outline card-navy p-3">
				<?php foreach($special_groups as $group_label => $perms): ?>
					<div class="text-xs font-weight-bold text-uppercase text-muted mb-1"><?php echo htmlspecialchars($group_label) ?></div>
					<?php foreach($perms as $perm_key => $perm_label): $dom_id = 'p_'.$perm_key; ?>
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input perm-check" id="<?php echo $dom_id ?>" name="permissions[]" value="<?php echo htmlspecialchars($perm_key) ?>" <?php echo $has($perm_key) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="<?php echo $dom_id ?>"><?php echo htmlspecialchars($perm_label) ?></label>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if(!empty($admin_groups)): ?>
		<div class="form-group">
			<label class="control-label font-weight-bold text-navy m-0 d-block mb-1"><i class="fas fa-users-cog"></i> Administration</label>
			<small class="text-muted d-block mb-2">A role with <b>Add, Edit &amp; Remove Users</b> acts as a Tenant Administrator. Defining roles and managing tenants stays with the developer (super administrator) only.</small>
			<div class="card card-outline card-navy p-3">
				<?php foreach($admin_groups as $group_label => $perms): ?>
					<div class="row">
						<?php foreach($perms as $perm_key => $perm_label):
							$dom_id = 'p_'.preg_replace('/[^a-z0-9_]/i','_',$perm_key); ?>
							<div class="col-md-6">
								<div class="custom-control custom-checkbox mb-1">
									<input type="checkbox" class="custom-control-input perm-check" id="<?php echo $dom_id ?>" name="permissions[]" value="<?php echo htmlspecialchars($perm_key) ?>" <?php echo $has($perm_key) ? 'checked' : '' ?>>
									<label class="custom-control-label" for="<?php echo $dom_id ?>"><?php echo htmlspecialchars($perm_label) ?></label>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

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
		function syncToggles(){
			$('.col-toggle').each(function(){
				var act = $(this).data('action');
				var boxes = $('.perm-check[data-action="'+act+'"]');
				$(this).prop('checked', boxes.length > 0 && boxes.filter(':checked').length === boxes.length);
			});
			$('.row-toggle').each(function(){
				var mod = $(this).data('module');
				var boxes = $('.perm-check[data-module="'+mod+'"]');
				$(this).prop('checked', boxes.length > 0 && boxes.filter(':checked').length === boxes.length);
			});
		}
		$('#perm-select-all').on('click', function(){ $('.perm-check').prop('checked', true); syncToggles(); syncSections(); });
		$('#perm-clear-all').on('click', function(){ $('.perm-check').prop('checked', false); syncToggles(); syncSections(); });
		$('.col-toggle').on('change', function(){
			var act = $(this).data('action'), on = $(this).is(':checked');
			$('.perm-check[data-action="'+act+'"]').prop('checked', on);
			syncToggles();
		});
		$('.row-toggle').on('change', function(){
			var mod = $(this).data('module'), on = $(this).is(':checked');
			$('.perm-check[data-module="'+mod+'"]').prop('checked', on);
			syncToggles();
		});
		$(document).on('change', '.perm-check', syncToggles);
		syncToggles();
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