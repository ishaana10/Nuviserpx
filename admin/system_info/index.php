<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<style>
	img#cimg{
		height: 15vh;
		width: 15vh;
		object-fit: scale-down;
		border-radius: 100% 100%;
	}
	img#cimg2{
		height: 50vh;
		width: 100%;
		object-fit: contain;
		/* border-radius: 100% 100%; */
	}
</style>
<div class="col-lg-12">
	<div class="card card-outline card-primary rounded-0 shadow">
		<div class="card-header">
			<h5 class="card-title">System Information</h5>
			<!-- <div class="card-tools">
				<a class="btn btn-block btn-sm btn-default btn-flat border-primary new_department" href="javascript:void(0)"><i class="fa fa-plus"></i> Add New</a>
			</div> -->
		</div>
		<div class="card-body">
			<form action="" id="system-frm">
			<div id="msg" class="form-group"></div>
			<div class="form-group">
				<label for="name" class="control-label">System Name</label>
				<input type="text" class="form-control form-control-sm" name="name" id="name" value="<?php echo $_settings->info('name') ?>">
			</div>
			<div class="form-group">
				<label for="short_name" class="control-label">System Short Name</label>
				<input type="text" class="form-control form-control-sm" name="short_name" id="short_name" value="<?php echo  $_settings->info('short_name') ?>">
			</div>
			<div class="form-group">
				<label for="" class="control-label">System Logo</label>
				<div class="custom-file">
	              <input type="file" class="custom-file-input rounded-circle" id="customFile" name="img" onchange="displayImg(this,$(this))">
	              <label class="custom-file-label" for="customFile">Choose file</label>
	            </div>
			</div>
			<div class="form-group d-flex justify-content-center">
				<img src="<?php echo validate_image($_settings->info('logo')) ?>" alt="" id="cimg" class="img-fluid img-thumbnail">
			</div>
			<div class="form-group">
				<label for="" class="control-label">Cover</label>
				<div class="custom-file">
	              <input type="file" class="custom-file-input rounded-circle" id="customFile" name="cover" onchange="displayImg2(this,$(this))">
	              <label class="custom-file-label" for="customFile">Choose file</label>
	            </div>
			</div>
			<div class="form-group d-flex justify-content-center">
				<img src="<?php echo validate_image($_settings->info('cover')) ?>" alt="" id="cimg2" class="img-fluid img-thumbnail bg-gradient-dark border-dark">
			</div>

			<hr class="border-primary my-4">
			<h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-envelope-open-text mr-2"></i> Email & SMTP Settings</h5>
			<div class="row">
				<div class="col-md-6 form-group">
					<label for="smtp_host" class="control-label">SMTP Host</label>
					<input type="text" class="form-control form-control-sm" name="smtp_host" id="smtp_host" value="<?php echo $_settings->info('smtp_host') ? $_settings->info('smtp_host') : 'smtp.gmail.com' ?>">
				</div>
				<div class="col-md-3 form-group">
					<label for="smtp_port" class="control-label">SMTP Port</label>
					<input type="text" class="form-control form-control-sm" name="smtp_port" id="smtp_port" value="<?php echo $_settings->info('smtp_port') ? $_settings->info('smtp_port') : '587' ?>">
				</div>
				<div class="col-md-3 form-group">
					<label for="smtp_encryption" class="control-label">Encryption</label>
					<select name="smtp_encryption" id="smtp_encryption" class="form-control form-control-sm">
						<option value="tls" <?php echo $_settings->info('smtp_encryption') == 'tls' ? 'selected' : '' ?>>TLS</option>
						<option value="ssl" <?php echo $_settings->info('smtp_encryption') == 'ssl' ? 'selected' : '' ?>>SSL</option>
						<option value="none" <?php echo $_settings->info('smtp_encryption') == 'none' ? 'selected' : '' ?>>None</option>
					</select>
				</div>
			</div>
			<div class="row">
				<div class="col-md-6 form-group">
					<label for="smtp_user" class="control-label">SMTP Username / Email</label>
					<input type="email" class="form-control form-control-sm" name="smtp_user" id="smtp_user" value="<?php echo $_settings->info('smtp_user') ?>">
				</div>
				<div class="col-md-6 form-group">
					<label for="smtp_pass" class="control-label">SMTP Password</label>
					<input type="password" class="form-control form-control-sm" name="smtp_pass" id="smtp_pass" value="<?php echo $_settings->info('smtp_pass') ?>">
				</div>
			</div>
			</form>
		</div>
		<div class="card-footer">
			<div class="col-md-12">
				<div class="row d-flex justify-content-between align-items-center">
					<button class="btn btn-sm btn-primary" form="system-frm"><i class="fas fa-save mr-1"></i> Save Settings</button>
					<button type="button" class="btn btn-sm btn-info" id="btn_send_test_email"><i class="fas fa-paper-plane mr-1"></i> Send Test Email</button>
				</div>
			</div>
		</div>

	</div>
</div>
<script>
	function displayImg(input,_this) {
	    if (input.files && input.files[0]) {
	        var reader = new FileReader();
	        reader.onload = function (e) {
	        	$('#cimg').attr('src', e.target.result);
	        	_this.siblings('.custom-file-label').html(input.files[0].name)
	        }

	        reader.readAsDataURL(input.files[0]);
	    }
	}
	function displayImg2(input,_this) {
	    if (input.files && input.files[0]) {
	        var reader = new FileReader();
	        reader.onload = function (e) {
	        	_this.siblings('.custom-file-label').html(input.files[0].name)
	        	$('#cimg2').attr('src', e.target.result);
	        }

	        reader.readAsDataURL(input.files[0]);
	    }
	}
	function displayImg3(input,_this) {
	    if (input.files && input.files[0]) {
	        var reader = new FileReader();
	        reader.onload = function (e) {
	        	_this.siblings('.custom-file-label').html(input.files[0].name)
	        	$('#cimg3').attr('src', e.target.result);
	        }

	        reader.readAsDataURL(input.files[0]);
	    }
	}
	$(document).ready(function(){
		$('#btn_send_test_email').click(function(){
			var test_email = prompt("Enter email address to receive test email:", "<?php echo $_settings->info('smtp_user') ?>");
			if(test_email){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/SystemSettings.php?f=send_test_email",
					method: "POST",
					data: { test_email: test_email },
					dataType: "json",
					error: err => {
						console.log(err);
						alert_toast("An error occurred.", 'error');
						end_loader();
					},
					success: function(resp){
						end_loader();
						if(resp.status == 'success'){
							alert_toast(resp.msg, 'success');
						} else {
							alert_toast(resp.msg || "Failed to send test email.", 'error');
						}
					}
				});
			}
		});
		 $('.summernote').summernote({
		        height: '60vh',
		        toolbar: [
		            [ 'style', [ 'style' ] ],
		            [ 'font', [ 'bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear'] ],
		            [ 'fontname', [ 'fontname' ] ],
		            [ 'fontsize', [ 'fontsize' ] ],
		            [ 'color', [ 'color' ] ],
		            [ 'para', [ 'ol', 'ul', 'paragraph', 'height' ] ],
		            [ 'table', [ 'table' ] ],
					['insert', ['link', 'picture']],
		            [ 'view', [ 'undo', 'redo', 'fullscreen', 'codeview', 'help' ] ]
		        ]
		    })
	})
</script>