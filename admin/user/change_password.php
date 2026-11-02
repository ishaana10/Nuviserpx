<?php
/**
 * Change Password — available to every logged-in user (no special permission).
 * Updates only the current user's own password.
 */
$user = $conn->query("SELECT id, username, firstname, lastname FROM users WHERE id = '".(int)$_settings->userdata('id')."'");
$meta = $user ? $user->fetch_assoc() : [];
?>
<div class="card card-outline card-primary shadow-sm" style="max-width:520px; margin: 2rem auto;">
	<div class="card-header py-2">
		<h4 class="card-title font-weight-bold text-navy m-0">
			<i class="fas fa-key text-warning mr-2"></i> Change Password
		</h4>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<div id="cp-msg"></div>
			<p class="text-muted mb-3">Update the password for <b><?php echo htmlspecialchars(($meta['firstname'] ?? '').' '.($meta['lastname'] ?? '')) ?></b> (<?php echo htmlspecialchars($meta['username'] ?? '') ?>).</p>
			<form action="" id="change-password-form">
				<input type="hidden" name="id" value="<?php echo (int)$_settings->userdata('id') ?>">
				<div class="form-group">
					<label for="current_password" class="control-label font-weight-bold text-navy">Current Password</label>
					<input type="password" name="current_password" id="current_password" class="form-control form-control-sm rounded-0" required autocomplete="current-password">
				</div>
				<div class="form-group">
					<label for="new_password" class="control-label font-weight-bold text-navy">New Password</label>
					<input type="password" name="new_password" id="new_password" class="form-control form-control-sm rounded-0" required autocomplete="new-password" minlength="6">
				</div>
				<div class="form-group">
					<label for="confirm_password" class="control-label font-weight-bold text-navy">Confirm New Password</label>
					<input type="password" name="confirm_password" id="confirm_password" class="form-control form-control-sm rounded-0" required autocomplete="new-password" minlength="6">
				</div>
			</form>
		</div>
	</div>
	<div class="card-footer text-right bg-transparent border-top">
		<button class="btn btn-primary font-weight-bold px-4" form="change-password-form"><i class="fas fa-save mr-1"></i> Update Password</button>
		<a class="btn btn-default border font-weight-bold" href="./?page=user"><i class="fas fa-times mr-1"></i> Cancel</a>
	</div>
</div>

<script>
$('#change-password-form').submit(function(e){
	e.preventDefault();
	var np = $('#new_password').val();
	var cp = $('#confirm_password').val();
	if(np !== cp){
		$('#cp-msg').html('<div class="alert alert-danger">New password and confirmation do not match.</div>');
		return;
	}
	if(np.length < 6){
		$('#cp-msg').html('<div class="alert alert-danger">Password must be at least 6 characters.</div>');
		return;
	}
	start_loader();
	$.ajax({
		url: _base_url_+'classes/Users.php?f=change_password',
		data: new FormData($(this)[0]),
		cache: false,
		contentType: false,
		processData: false,
		method: 'POST',
		type: 'POST',
		dataType: 'json',
		success: function(resp){
			if(resp && resp.status === 'success'){
				$('#cp-msg').html('<div class="alert alert-success"><i class="fas fa-check mr-1"></i>'+resp.msg+'</div>');
				$('#change-password-form')[0].reset();
			} else {
				$('#cp-msg').html('<div class="alert alert-danger">'+(resp && resp.msg ? resp.msg : 'An error occurred.')+'</div>');
			}
			end_loader();
		},
		error: function(){
			$('#cp-msg').html('<div class="alert alert-danger">An error occurred.</div>');
			end_loader();
		}
	});
});
</script>
