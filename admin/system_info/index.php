<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<?php
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'system_info';
$tenant_id = $_settings->active_tenant_id();

// Ensure audit log migration is ready
$_settings->log_audit('Viewed System Settings', 'Settings', 'Navigated to settings tab: ' . $active_tab);
?>

<style>
	img#cimg{
		height: 15vh;
		width: 15vh;
		object-fit: scale-down;
		border-radius: 100% 100%;
	}
	img#cimg2{
		height: 40vh;
		width: 100%;
		object-fit: contain;
	}
</style>

<div class="col-lg-12">
	<ul class="nav nav-tabs mb-3 shadow-sm bg-white p-2 rounded" id="settingsTab" role="tablist">
	  <li class="nav-item">
	    <a class="nav-link <?php echo $active_tab == 'system_info' ? 'active font-weight-bold text-primary' : 'text-secondary font-weight-bold' ?>" href="<?php echo base_url ?>admin/?page=system_info&tab=system_info"><i class="fas fa-cogs mr-1"></i> System Information & Email</a>
	  </li>
	  <li class="nav-item">
	    <a class="nav-link <?php echo $active_tab == 'error_logs' ? 'active font-weight-bold text-danger' : 'text-secondary font-weight-bold' ?>" href="<?php echo base_url ?>admin/?page=system_info&tab=error_logs"><i class="fas fa-exclamation-triangle mr-1"></i> System Error Logs</a>
	  </li>
	  <li class="nav-item">
	    <a class="nav-link <?php echo $active_tab == 'audit_logs' ? 'active font-weight-bold text-info' : 'text-secondary font-weight-bold' ?>" href="<?php echo base_url ?>admin/?page=system_info&tab=audit_logs"><i class="fas fa-user-shield mr-1"></i> System Audit Trail / Logs</a>
	  </li>
	  <li class="nav-item">
	    <a class="nav-link text-secondary font-weight-bold" href="<?php echo base_url ?>admin/?page=roles"><i class="fas fa-lock mr-1"></i> User Roles & Permissions</a>
	  </li>
	</ul>

	<?php if($active_tab == 'system_info'): ?>
	<!-- TAB 1: SYSTEM INFORMATION & EMAIL -->
	<div class="card card-outline card-primary rounded-0 shadow">
		<div class="card-header">
			<h5 class="card-title font-weight-bold text-navy"><i class="fas fa-sliders-h mr-1"></i> General System Identification & Email Relay Settings</h5>
		</div>
		<div class="card-body">
			<form action="" id="system-frm">
			<div id="msg" class="form-group"></div>
			<div class="row">
				<div class="col-md-6 form-group">
					<label for="name" class="control-label font-weight-bold">System Name</label>
					<input type="text" class="form-control form-control-sm rounded-0" name="name" id="name" value="<?php echo $_settings->info('name') ?>" required>
				</div>
				<div class="col-md-6 form-group">
					<label for="short_name" class="control-label font-weight-bold">System Short Name / Branding</label>
					<input type="text" class="form-control form-control-sm rounded-0" name="short_name" id="short_name" value="<?php echo  $_settings->info('short_name') ?>" required>
				</div>
			</div>

			<div class="row">
				<div class="col-md-6 form-group">
					<label for="" class="control-label font-weight-bold">System Logo</label>
					<div class="custom-file">
		              <input type="file" class="custom-file-input rounded-circle" id="customFile" name="img" onchange="displayImg(this,$(this))">
		              <label class="custom-file-label" for="customFile">Choose file</label>
		            </div>
		            <div class="mt-2 text-center">
				<img src="<?php echo validate_image($_settings->info('logo')) ?>" alt="" id="cimg" class="img-fluid img-thumbnail">
		            </div>
				</div>
				<div class="col-md-6 form-group">
					<label for="" class="control-label font-weight-bold">Cover Banner</label>
					<div class="custom-file">
		              <input type="file" class="custom-file-input rounded-circle" id="customFile2" name="cover" onchange="displayImg2(this,$(this))">
		              <label class="custom-file-label" for="customFile2">Choose file</label>
		            </div>
		            <div class="mt-2 text-center">
				<img src="<?php echo validate_image($_settings->info('cover')) ?>" alt="" id="cimg2" class="img-fluid img-thumbnail bg-gradient-dark border-dark">
		            </div>
				</div>
			</div>

			<hr class="border-primary my-4">
			<h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-envelope-open-text mr-2"></i> Email & SMTP Settings</h5>
			<div class="row">
				<div class="col-md-6 form-group">
					<label for="smtp_host" class="control-label font-weight-bold">SMTP Host</label>
					<input type="text" class="form-control form-control-sm rounded-0" name="smtp_host" id="smtp_host" value="<?php echo $_settings->info('smtp_host') ? $_settings->info('smtp_host') : 'smtp.gmail.com' ?>">
				</div>
				<div class="col-md-3 form-group">
					<label for="smtp_port" class="control-label font-weight-bold">SMTP Port</label>
					<input type="text" class="form-control form-control-sm rounded-0" name="smtp_port" id="smtp_port" value="<?php echo $_settings->info('smtp_port') ? $_settings->info('smtp_port') : '587' ?>">
				</div>
				<div class="col-md-3 form-group">
					<label for="smtp_encryption" class="control-label font-weight-bold">Encryption</label>
					<select name="smtp_encryption" id="smtp_encryption" class="form-control form-control-sm rounded-0">
						<option value="tls" <?php echo $_settings->info('smtp_encryption') == 'tls' ? 'selected' : '' ?>>TLS</option>
						<option value="ssl" <?php echo $_settings->info('smtp_encryption') == 'ssl' ? 'selected' : '' ?>>SSL</option>
						<option value="none" <?php echo $_settings->info('smtp_encryption') == 'none' ? 'selected' : '' ?>>None</option>
					</select>
				</div>
			</div>
			<div class="row">
				<div class="col-md-6 form-group">
					<label for="smtp_user" class="control-label font-weight-bold">SMTP Username / Email</label>
					<input type="email" class="form-control form-control-sm rounded-0" name="smtp_user" id="smtp_user" value="<?php echo $_settings->info('smtp_user') ?>">
				</div>
				<div class="col-md-6 form-group">
					<label for="smtp_pass" class="control-label font-weight-bold">SMTP Password</label>
					<input type="password" class="form-control form-control-sm rounded-0" name="smtp_pass" id="smtp_pass" value="<?php echo $_settings->info('smtp_pass') ?>">
				</div>
			</div>

			<hr class="border-primary my-4">
			<h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-microchip mr-2"></i> FRCS VMS (VAT Monitoring System) Integration</h5>
			<div class="row">
				<div class="col-md-6 form-group">
					<label for="vms_url" class="control-label">SDC Server / VMS API Endpoint URL</label>
					<input type="text" class="form-control form-control-sm" name="vms_url" id="vms_url" value="<?php echo $_settings->info('vms_url') ? $_settings->info('vms_url') : 'http://localhost:8088/api/v1/sdc' ?>" placeholder="e.g. http://sdc.frcs.org.fj/api/v1">
				</div>
				<div class="col-md-3 form-group">
					<label for="vms_tin" class="control-label">Taxpayer TIN</label>
					<input type="text" class="form-control form-control-sm" name="vms_tin" id="vms_tin" value="<?php echo $_settings->info('vms_tin') ?>" placeholder="e.g. 50-00000-0-0">
				</div>
				<div class="col-md-3 form-group">
					<label for="vms_status" class="control-label">VMS Integration Mode</label>
					<select name="vms_status" id="vms_status" class="form-control form-control-sm">
						<option value="1" <?php echo $_settings->info('vms_status') == '1' ? 'selected' : '' ?>>Enabled (Production / SDC Live)</option>
						<option value="2" <?php echo $_settings->info('vms_status') == '2' ? 'selected' : '' ?>>Sandbox / Test Environment</option>
						<option value="0" <?php echo $_settings->info('vms_status') == '0' || !$_settings->info('vms_status') ? 'selected' : '' ?>>Disabled</option>
					</select>
				</div>
			</div>

			<hr class="border-primary my-4">
			<h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-sliders-h mr-2"></i> Sidebar Navigation Customization</h5>
			<div class="row">
				<div class="col-md-4 form-group">
					<label for="sidenav_theme" class="control-label">Sidebar Theme</label>
					<select name="sidenav_theme" id="sidenav_theme" class="form-control form-control-sm">
						<option value="sidebar-dark-navy" <?php echo $_settings->info('sidenav_theme') == 'sidebar-dark-navy' ? 'selected' : '' ?>>Navy Dark (Default)</option>
						<option value="sidebar-dark-primary" <?php echo $_settings->info('sidenav_theme') == 'sidebar-dark-primary' ? 'selected' : '' ?>>Primary Dark</option>
						<option value="sidebar-dark-dark" <?php echo $_settings->info('sidenav_theme') == 'sidebar-dark-dark' ? 'selected' : '' ?>>Charcoal Dark</option>
						<option value="sidebar-light-primary" <?php echo $_settings->info('sidenav_theme') == 'sidebar-light-primary' ? 'selected' : '' ?>>Clean Light</option>
					</select>
				</div>
				<div class="col-md-4 form-group">
					<label for="sidenav_mode" class="control-label">Navigation Style</label>
					<select name="sidenav_mode" id="sidenav_mode" class="form-control form-control-sm">
						<option value="accordion" <?php echo $_settings->info('sidenav_mode') == 'accordion' || !$_settings->info('sidenav_mode') ? 'selected' : '' ?>>Accordion Collapsible Groups</option>
						<option value="expanded" <?php echo $_settings->info('sidenav_mode') == 'expanded' ? 'selected' : '' ?>>Always Expanded Groups</option>
					</select>
				</div>
				<div class="col-md-4 form-group">
					<label for="sidenav_compact" class="control-label">Compact Density</label>
					<select name="sidenav_compact" id="sidenav_compact" class="form-control form-control-sm">
						<option value="1" <?php echo $_settings->info('sidenav_compact') == '1' || !$_settings->info('sidenav_compact') ? 'selected' : '' ?>>Compact (Modern)</option>
						<option value="0" <?php echo $_settings->info('sidenav_compact') == '0' ? 'selected' : '' ?>>Normal Spacing</option>
					</select>
				</div>
			</div>
			<div class="row">
				<div class="col-md-3 form-group">
					<label class="control-label d-block">Show Commercial Group</label>
					<select name="sidenav_show_commercial" class="form-control form-control-sm">
						<option value="1" <?php echo $_settings->info('sidenav_show_commercial') !== '0' ? 'selected' : '' ?>>Show</option>
						<option value="0" <?php echo $_settings->info('sidenav_show_commercial') === '0' ? 'selected' : '' ?>>Hide</option>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label class="control-label d-block">Show Payroll & HR Group</label>
					<select name="sidenav_show_payroll" class="form-control form-control-sm">
						<option value="1" <?php echo $_settings->info('sidenav_show_payroll') !== '0' ? 'selected' : '' ?>>Show</option>
						<option value="0" <?php echo $_settings->info('sidenav_show_payroll') === '0' ? 'selected' : '' ?>>Hide</option>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label class="control-label d-block">Show Financial Group</label>
					<select name="sidenav_show_financial" class="form-control form-control-sm">
						<option value="1" <?php echo $_settings->info('sidenav_show_financial') !== '0' ? 'selected' : '' ?>>Show</option>
						<option value="0" <?php echo $_settings->info('sidenav_show_financial') === '0' ? 'selected' : '' ?>>Hide</option>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label class="control-label d-block">Show Maintenance Group</label>
					<select name="sidenav_show_maintenance" class="form-control form-control-sm">
						<option value="1" <?php echo $_settings->info('sidenav_show_maintenance') !== '0' ? 'selected' : '' ?>>Show</option>
						<option value="0" <?php echo $_settings->info('sidenav_show_maintenance') === '0' ? 'selected' : '' ?>>Hide</option>
					</select>
				</div>
			</div>
			</form>
		</div>
		<div class="card-footer bg-light text-right">
			<button class="btn btn-sm btn-primary font-weight-bold" form="system-frm"><i class="fas fa-save mr-1"></i> Save Settings</button>
			<button type="button" class="btn btn-sm btn-info font-weight-bold ml-2" id="btn_send_test_email"><i class="fas fa-paper-plane mr-1"></i> Send Test Email</button>
		</div>
	</div>

	<?php elseif($active_tab == 'error_logs'): ?>
	<!-- TAB 2: SYSTEM ERROR LOGS -->
	<div class="card card-outline card-danger rounded-0 shadow">
		<div class="card-header d-flex justify-content-between align-items-center">
			<h5 class="card-title font-weight-bold text-danger mb-0"><i class="fas fa-exclamation-triangle mr-1"></i> System PHP & Exception Error Log File</h5>
			<div>
				<button type="button" class="btn btn-sm btn-outline-danger font-weight-bold" id="btn-clear-error-log"><i class="fas fa-trash mr-1"></i> Clear Error Log</button>
			</div>
		</div>
		<div class="card-body">
			<?php
			$error_log_path = base_app . 'error_log';
			$log_contents = '';
			if(file_exists($error_log_path)){
				$log_contents = file_get_contents($error_log_path);
			}
			?>
			<?php if(!empty(trim($log_contents))): ?>
				<pre class="bg-dark text-warning p-3 rounded shadow-inner" style="max-height: 550px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; border: 1px solid #444;"><?php echo htmlspecialchars($log_contents); ?></pre>
			<?php else: ?>
				<div class="alert alert-success text-center my-4 py-4 rounded">
					<i class="fas fa-check-circle fa-3x mb-2 text-success"></i>
					<h5 class="font-weight-bold">No Errors Found</h5>
					<p class="m-0 text-muted">System error log is currently clear and no runtime exceptions have been logged.</p>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php elseif($active_tab == 'audit_logs'): ?>
	<!-- TAB 3: AUDIT LOGS / TRAIL -->
	<div class="card card-outline card-info rounded-0 shadow">
		<div class="card-header d-flex justify-content-between align-items-center">
			<h5 class="card-title font-weight-bold text-info mb-0"><i class="fas fa-user-shield mr-1"></i> System Activity Audit Trail & Log History</h5>
			<div>
				<button type="button" class="btn btn-sm btn-outline-danger font-weight-bold" id="btn-clear-audit-log"><i class="fas fa-trash-alt mr-1"></i> Clear Audit Log</button>
			</div>
		</div>
		<div class="card-body">
			<div class="table-responsive">
				<table class="table table-bordered table-striped table-hover table-sm" id="audit-table">
					<thead class="bg-light">
						<tr>
							<th width="5%">#</th>
							<th width="15%">Date & Time</th>
							<th width="12%">User</th>
							<th width="12%">Module</th>
							<th width="18%">Action</th>
							<th width="26%">Details</th>
							<th width="12%">IP Address</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$i = 1;
						$audit_qry = $conn->query("SELECT * FROM `audit_logs` WHERE tenant_id = '{$tenant_id}' ORDER BY id DESC LIMIT 500");
						if($audit_qry && $audit_qry->num_rows > 0):
							while($row = $audit_qry->fetch_assoc()):
						?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td class="font-weight-bold text-navy"><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'])); ?></td>
							<td>
								<span class="badge badge-light border text-dark font-weight-bold">
									<i class="fas fa-user mr-1 text-secondary"></i><?php echo htmlspecialchars($row['username'] ?? 'System') ?>
								</span>
							</td>
							<td><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($row['module']) ?></span></td>
							<td class="font-weight-bold text-dark"><?php echo htmlspecialchars($row['action']) ?></td>
							<td><small class="text-muted"><?php echo htmlspecialchars($row['details'] ?? '') ?></small></td>
							<td class="text-muted font-monospace"><small><?php echo htmlspecialchars($row['ip_address'] ?? '127.0.0.1') ?></small></td>
						</tr>
						<?php
							endwhile;
						else:
						?>
						<tr>
							<td colspan="7" class="text-center text-muted py-4">No audit activity logged yet.</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<?php endif; ?>

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

	$(document).ready(function(){
		if($('#audit-table').length > 0){
			$('#audit-table').DataTable({
				order: [[1, 'desc']],
				pageLength: 25
			});
		}

		$('#btn-clear-error-log').click(function(){
			if(confirm("Are you sure you want to clear all error log entries?")){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/SystemSettings.php?f=clear_error_log",
					method: "POST",
					dataType: "json",
					success: function(resp){
						end_loader();
						alert_toast(resp.msg, 'success');
						setTimeout(function(){ location.reload(); }, 1000);
					}
				});
			}
		});

		$('#btn-clear-audit-log').click(function(){
			if(confirm("Are you sure you want to clear all audit trail records?")){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/SystemSettings.php?f=clear_audit_log",
					method: "POST",
					dataType: "json",
					success: function(resp){
						end_loader();
						alert_toast(resp.msg, 'success');
						setTimeout(function(){ location.reload(); }, 1000);
					}
				});
			}
		});

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

		$('#system-frm').submit(function(e){
			e.preventDefault();
			start_loader();
			$.ajax({
				url: _base_url_ + "classes/SystemSettings.php?f=update_settings",
				data: new FormData($(this)[0]),
				cache: false,
				contentType: false,
				processData: false,
				method: 'POST',
				type: 'POST',
				success: function(resp){
					end_loader();
					if(resp == 1){
						alert_toast("System Settings successfully updated.", 'success');
						setTimeout(function(){ location.reload(); }, 1000);
					} else {
						alert_toast("An error occurred.", 'error');
					}
				}
			});
		});
	});
</script>
