<?php 
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && $_GET['id'] > 0){
    $user_id_clean = intval($_GET['id']);
    $user = $conn->query("SELECT * FROM users WHERE id ='{$user_id_clean}'");
    if($user && $user->num_rows > 0){
        foreach($user->fetch_array() as $k => $v){
            $meta[$k] = $v;
        }
    }
}

$user_tenant_id = isset($meta['tenant_id']) ? $meta['tenant_id'] : $tenant_id;
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<style>
	img#cimg{
		height: 120px;
		width: 120px;
		object-fit: cover;
		border-radius: 50%;
	}
</style>

<div class="card card-outline card-primary shadow-sm">
	<div class="card-header py-2">
		<h4 class="card-title font-weight-bold text-navy m-0">
			<i class="fas <?= isset($_GET['id']) ? 'fa-user-edit text-primary' : 'fa-user-plus text-success' ?> mr-2"></i>
			<?= isset($_GET['id']) ? 'Edit System User Profile' : 'Create New System User' ?>
		</h4>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<div id="msg"></div>
			<form action="" id="manage-user">	
				<input type="hidden" name="id" value="<?php echo isset($meta['id']) ? $meta['id']: '' ?>">

				<div class="row">
					<div class="form-group col-md-6 col-12">
						<label for="firstname" class="control-label font-weight-bold text-navy">First Name</label>
						<input type="text" name="firstname" id="firstname" class="form-control form-control-sm rounded-0" value="<?php echo isset($meta['firstname']) ? htmlspecialchars($meta['firstname']): '' ?>" required>
					</div>
					<div class="form-group col-md-6 col-12">
						<label for="lastname" class="control-label font-weight-bold text-navy">Last Name</label>
						<input type="text" name="lastname" id="lastname" class="form-control form-control-sm rounded-0" value="<?php echo isset($meta['lastname']) ? htmlspecialchars($meta['lastname']): '' ?>" required>
					</div>
				</div>

				<div class="row">
					<div class="form-group col-md-6 col-12">
						<label for="username" class="control-label font-weight-bold text-navy">Username / Login ID</label>
						<input type="text" name="username" id="username" class="form-control form-control-sm rounded-0" value="<?php echo isset($meta['username']) ? htmlspecialchars($meta['username']): '' ?>" required autocomplete="off">
					</div>
					<div class="form-group col-md-6 col-12">
						<label for="password" class="control-label font-weight-bold text-navy">Password</label>
						<div class="input-group input-group-sm">
							<input type="password" name="password" id="password" class="form-control rounded-0" value="" autocomplete="off" <?php echo isset($meta['id']) ? "": 'required' ?>>
							<div class="input-group-append">
								<button class="btn btn-outline-secondary btn-flat" type="button" id="toggle-pass"><i class="fas fa-eye"></i></button>
							</div>
						</div>
						<?php if(isset($_GET['id'])): ?>
							<small class="text-muted"><i>Leave blank if you do not want to change the password.</i></small>
						<?php endif; ?>
					</div>
				</div>

				<div class="row">
					<div class="form-group col-md-6 col-12">
						<label for="type" class="control-label font-weight-bold text-navy">User Privilege Level</label>
						<select name="type" id="type" class="form-control form-control-sm rounded-0" required>
							<option value="1" <?php echo isset($meta['type']) && $meta['type'] == 1 ? 'selected': '' ?>>Super Administrator (Full System Access)</option>
							<option value="2" <?php echo !isset($meta['type']) || $meta['type'] == 2 ? 'selected': '' ?>>Staff User (Role-Based Permissions)</option>
						</select>
					</div>

					<div class="form-group col-md-6 col-12" id="role-group" style="<?php echo isset($meta['type']) && $meta['type'] == 1 ? 'display:none;' : '' ?>">
						<label for="role_id" class="control-label font-weight-bold text-navy">Assigned Custom Role</label>
						<select name="role_id" id="role_id" class="form-control form-control-sm rounded-0 select2">
							<option value="0">Default Staff Permissions</option>
							<?php
							$roles = $conn->query("SELECT * FROM `roles` WHERE tenant_id = '{$user_tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
							while($r = $roles->fetch_assoc()):
							?>
							<option value="<?php echo $r['id'] ?>" <?php echo isset($meta['role_id']) && $meta['role_id'] == $r['id'] ? 'selected' : '' ?>>
								<?php echo htmlspecialchars($r['name']) ?>
							</option>
							<?php endwhile; ?>
						</select>
					</div>
				</div>

				<div class="row">
					<!-- Tenant Selection for Superadmin or Tenant auto-binding -->
					<div class="form-group col-md-6 col-12">
						<label for="tenant_id" class="control-label font-weight-bold text-navy">Tenant / Company Isolation</label>
						<?php if ($_settings->userdata('type') == 1): ?>
							<select name="tenant_id" id="tenant_id" class="form-control form-control-sm rounded-0 select2" required>
								<?php
								$tenants_qry = $conn->query("SELECT * FROM `tenants` WHERE delete_flag = 0 AND status = 1 ORDER BY name ASC");
								while($trow = $tenants_qry->fetch_assoc()):
								?>
								<option value="<?php echo $trow['id'] ?>" <?php echo $user_tenant_id == $trow['id'] ? 'selected' : '' ?>>
									<?php echo htmlspecialchars($trow['name']) ?>
								</option>
								<?php endwhile; ?>
							</select>
						<?php else: ?>
							<input type="hidden" name="tenant_id" value="<?php echo $user_tenant_id ?>">
							<input type="text" class="form-control form-control-sm rounded-0 bg-light" value="<?php echo htmlspecialchars($_settings->info('name')) ?>" readonly>
						<?php endif; ?>
					</div>

					<!-- Linked Employee Profile -->
					<div class="form-group col-md-6 col-12">
						<label for="employee_id" class="control-label font-weight-bold text-navy">Link to Employee Profile (Payroll & HR)</label>
						<select name="employee_id" id="employee_id" class="form-control form-control-sm rounded-0 select2">
							<option value="">None (Standalone User Account)</option>
							<?php
							$employees_qry = $conn->query("SELECT * FROM `employee_list` WHERE tenant_id = '{$user_tenant_id}' AND delete_flag = 0 ORDER BY firstname ASC");
							while($emp = $employees_qry->fetch_assoc()):
							?>
							<option value="<?php echo $emp['id'] ?>" <?php echo isset($meta['employee_id']) && $meta['employee_id'] == $emp['id'] ? 'selected' : '' ?>>
								<?php echo htmlspecialchars($emp['employee_code'] . ' - ' . $emp['firstname'] . ' ' . $emp['lastname']) ?>
							</option>
							<?php endwhile; ?>
						</select>
					</div>
				</div>

				<div class="row align-items-center mt-3">
					<div class="form-group col-md-6 col-12">
						<label for="customFile" class="control-label font-weight-bold text-navy">User Avatar Photo</label>
						<div class="custom-file">
							<input type="file" class="custom-file-input" id="customFile" name="img" onchange="displayImg(this,$(this))">
							<label class="custom-file-label rounded-0" for="customFile">Choose avatar file</label>
						</div>
					</div>
					<div class="form-group col-md-6 col-12 text-center">
						<img src="<?php echo validate_image(isset($meta['avatar']) ? $meta['avatar'] :'') ?>" alt="User Avatar" id="cimg" class="img-fluid img-thumbnail shadow-sm">
					</div>
				</div>
			</form>
		</div>
	</div>
	<div class="card-footer text-right bg-transparent border-top">
		<button class="btn btn-primary font-weight-bold px-4" form="manage-user"><i class="fas fa-save mr-1"></i> Save User Profile</button>
		<a class="btn btn-default border font-weight-bold" href="./?page=user/list"><i class="fas fa-times mr-1"></i> Cancel</a>
	</div>
</div>

<script>
	function displayImg(input,_this) {
	    if (input.files && input.files[0]) {
	        var reader = new FileReader();
	        reader.onload = function (e) {
	        	$('#cimg').attr('src', e.target.result);
	        }
	        reader.readAsDataURL(input.files[0]);
	    }
	}

	$(function(){
		$('.select2').select2({ width:'100%' });

		$('#type').change(function(){
			if($(this).val() == 1){
				$('#role-group').slideUp();
			} else {
				$('#role-group').slideDown();
			}
		});

		$('#toggle-pass').click(function(){
			var field = $('#password');
			var icon = $(this).find('i');
			if(field.attr('type') === 'password'){
				field.attr('type', 'text');
				icon.removeClass('fa-eye').addClass('fa-eye-slash');
			} else {
				field.attr('type', 'password');
				icon.removeClass('fa-eye-slash').addClass('fa-eye');
			}
		});

		$('#manage-user').submit(function(e){
			e.preventDefault();
			var _this = $(this)
			start_loader()
			$.ajax({
				url:_base_url_+'classes/Users.php?f=save',
				data: new FormData($(this)[0]),
				cache: false,
				contentType: false,
				processData: false,
				method: 'POST',
				type: 'POST',
				success:function(resp){
					if(resp == 1){
						location.href = './?page=user/list';
					}else{
						$('#msg').html('<div class="alert alert-danger">Failed to save user or username already exists.</div>')
						$("html, body").animate({ scrollTop: 0 }, "fast");
					}
					end_loader()
				}
			})
		});
	});
</script>
