<?php 
$user = $conn->query("SELECT * FROM users where id ='".$_settings->userdata('id')."'");
foreach($user->fetch_array() as $k =>$v){
	$meta[$k] = $v;
}
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-body">
		<div class="container-fluid">
			<div id="msg"></div>
			<form action="" id="manage-user">	
				<input type="hidden" name="id" value="<?php echo $_settings->userdata('id') ?>">
				<div class="form-group">
					<label for="name">First Name</label>
					<input type="text" name="firstname" id="firstname" class="form-control" value="<?php echo isset($meta['firstname']) ? $meta['firstname']: '' ?>" required>
				</div>
				<div class="form-group">
					<label for="name">Last Name</label>
					<input type="text" name="lastname" id="lastname" class="form-control" value="<?php echo isset($meta['lastname']) ? $meta['lastname']: '' ?>" required>
				</div>
				<div class="form-group">
					<label for="username">Username</label>
					<input type="text" name="username" id="username" class="form-control" value="<?php echo isset($meta['username']) ? $meta['username']: '' ?>" required  autocomplete="off">
				</div>
				<div class="form-group">
					<label for="email">Email Address</label>
					<?php if(!empty($meta['email']) && !empty($meta['email_verified_at'])): ?>
						<span class="badge badge-success ml-2" id="email-verify-state"><i class="fas fa-check-circle mr-1"></i>Verified<?php echo ' on '.date('d M Y', strtotime($meta['email_verified_at'])) ?></span>
					<?php elseif(!empty($meta['email'])): ?>
						<span class="badge badge-warning ml-2" id="email-verify-state"><i class="fas fa-clock mr-1"></i>Not verified</span>
						<a href="javascript:void(0)" id="resend-verification" class="ml-2 small">Resend verification email</a>
					<?php else: ?>
						<span class="badge badge-secondary ml-2" id="email-verify-state">Not registered</span>
					<?php endif; ?>
					<input type="email" name="email" id="email" class="form-control" value="<?php echo isset($meta['email']) ? htmlspecialchars((string)$meta['email']): '' ?>" autocomplete="off" placeholder="you@example.com">
					<small><i>Changing your email sends a new verification link to the new address.</i></small>
				</div>
				<div class="form-group">
					<label for="password">Password</label>
					<input type="password" name="password" id="password" class="form-control" value="" autocomplete="off">
					<small><i>Leave this blank if you dont want to change the password.</i></small>
				</div>
				<div class="form-group">
					<label for="" class="control-label">Avatar</label>
					<div class="custom-file">
		              <input type="file" class="custom-file-input rounded-circle" id="customFile" name="img" onchange="displayImg(this,$(this))">
		              <label class="custom-file-label" for="customFile">Choose file</label>
		            </div>
				</div>
				<div class="form-group d-flex justify-content-center">
					<img src="<?php echo validate_image(isset($meta['avatar']) ? $meta['avatar'] :'') ?>" alt="" id="cimg" class="img-fluid img-thumbnail">
				</div>
			</form>
		</div>
	</div>
	<div class="card-footer">
			<div class="col-md-12">
				<div class="row">
					<button class="btn btn-sm btn-primary" form="manage-user">Update</button>
				</div>
			</div>
		</div>
</div>
<style>
	img#cimg{
		height: 15vh;
		width: 15vh;
		object-fit: cover;
		border-radius: 100% 100%;
	}
</style>
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
	$('#resend-verification').click(function(){
		start_loader()
		$.ajax({
			url:_base_url_+'classes/Users.php?f=resend_verification',
			method: 'POST',
			data: {id: <?php echo (int)$_settings->userdata('id') ?>},
			dataType: 'json',
			error:function(){ alert_toast('An error occurred','error'); end_loader(); },
			success:function(resp){
				alert_toast(resp.msg || 'Done', resp.status == 'success' ? 'success' : 'error');
				end_loader();
			}
		})
	})
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
				var code = $.trim(String(resp));
				if(code == '1'){
					location.reload()
				}else{
					var msg = 'Failed to save. Please try again.';
					if(code == '3'){ msg = 'That username is already taken.'; }
					else if(code == '4'){ msg = 'Your current password is incorrect.'; }
					else if(code == '5'){ msg = 'Please enter a valid email address.'; }
					else if(code == '6'){ msg = 'That email address is already registered to another user.'; }
					$('#msg').html('<div class="alert alert-danger">'+msg+'</div>')
					end_loader()
				}
			}
		})
	})

</script>