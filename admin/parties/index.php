<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-address-book text-primary"></i> Customers & Vendors List</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Add New Party</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="10%">
					<col width="20%">
					<col width="10%">
					<col width="20%">
					<col width="12%">
					<col width="10%">
					<col width="13%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Code</th>
						<th>Name / Company</th>
						<th>Type</th>
						<th>Contact Details</th>
						<th>Portal Status</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						// Build lookup for customer portal users
						$portal_users = [];
						$p_qry = $conn->query("SELECT * FROM `customer_portal_users` WHERE tenant_id = '{$tenant_id}'");
						if($p_qry){
							while($pr = $p_qry->fetch_assoc()){
								$portal_users[$pr['party_id']] = $pr;
							}
						}

						$qry = $conn->query("SELECT * from `party_list` where tenant_id = '{$tenant_id}' and delete_flag = 0 order by id desc ");
						while($row = $qry->fetch_assoc()):
							$p_status_badge = '<span class="badge badge-secondary px-2">N/A</span>';
							if($row['type'] == 'customer'){
								if(isset($portal_users[$row['id']])){
									$pu = $portal_users[$row['id']];
									if($pu['status'] == 1){
										$p_status_badge = '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>';
									} else {
										$p_status_badge = '<span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i> Pending Invite</span>';
									}
								} else {
									$p_status_badge = '<span class="badge badge-light border text-muted px-2 py-1">Not Invited</span>';
								}
							}
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['party_code'] ?></b></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['name'] ?></p>
								<small class="text-muted">TIN: <?php echo !empty($row['tin']) ? $row['tin'] : 'N/A' ?></small>
							</td>
							<td>
								<span class="badge badge-<?php echo $row['type'] == 'customer' ? 'info' : 'warning' ?> px-2 py-1">
									<?php echo ucfirst($row['type']) ?>
								</span>
							</td>
							<td>
								<small class="d-block"><b>Email:</b> <?php echo !empty($row['email']) ? $row['email'] : 'N/A' ?></small>
								<small class="d-block text-muted"><b>Phone:</b> <?php echo !empty($row['phone']) ? $row['phone'] : 'N/A' ?></small>
							</td>
							<td class="text-center"><?php echo $p_status_badge ?></td>
							<td class="text-center">
                                <?php if($row['status'] == 1): ?>
                                    <span class="badge badge-success px-3 rounded-pill">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 rounded-pill">Inactive</span>
                                <?php endif; ?>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <?php if($row['type'] == 'customer'): ?>
				                    <div class="dropdown-divider"></div>
				                    <a class="dropdown-item invite_portal" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-name="<?php echo htmlspecialchars($row['name']) ?>"><span class="fa fa-paper-plane text-info"></span> Invite / Resend Invitation</a>
				                    <a class="dropdown-item view_portal_logs" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-name="<?php echo htmlspecialchars($row['name']) ?>"><span class="fa fa-history text-secondary"></span> Portal Activity Logs</a>
				                    <?php endif; ?>
				                    <div class="dropdown-divider"></div>
				                    <a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
				                  </div>
							</td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<script>
	$(document).ready(function(){
		$('.delete_data').click(function(){
			_conf("Are you sure to delete this party permanently?","delete_party",[$(this).attr('data-id')])
		})
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-plus'></i> Add New Customer / Vendor","parties/manage_party.php", 'large')
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Party Details","parties/manage_party.php?id="+$(this).attr('data-id'), 'large')
		})
		$('.invite_portal').click(function(){
			var party_id = $(this).attr('data-id');
			var party_name = $(this).attr('data-name');
			if(confirm("Send Customer Portal access invitation email to " + party_name + "?")){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/Master.php?f=send_customer_invitation",
					method: "POST",
					data: { party_id: party_id },
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
							alert_toast(resp.msg || "Failed to send invitation.", 'error');
						}
					}
				});
			}
		});
		$('.view_portal_logs').click(function(){
			var party_id = $(this).attr('data-id');
			var party_name = $(this).attr('data-name');
			start_loader();
			$.ajax({
				url: _base_url_ + "classes/Master.php?f=get_portal_logs",
				method: "POST",
				data: { party_id: party_id },
				dataType: "json",
				error: err => {
					console.log(err);
					alert_toast("An error occurred.", 'error');
					end_loader();
				},
				success: function(resp){
					end_loader();
					if(resp.status == 'success'){
						var html = '<div class="table-responsive"><table class="table table-bordered table-striped table-sm">';
						html += '<thead><tr><th>Date & Time</th><th>Action</th><th>Details</th><th>IP Address</th></tr></thead><tbody>';
						if(resp.data.length > 0){
							$.each(resp.data, function(i, log){
								html += '<tr>';
								html += '<td><small>' + log.date_created + '</small></td>';
								html += '<td><span class="badge badge-info">' + log.action + '</span></td>';
								html += '<td>' + (log.details || '') + '</td>';
								html += '<td><small>' + (log.ip_address || 'N/A') + '</small></td>';
								html += '</tr>';
							});
						} else {
							html += '<tr><td colspan="4" class="text-center text-muted">No portal activity logged yet.</td></tr>';
						}
						html += '</tbody></table></div>';

						$('#uni_modal .modal-title').html('<i class="fas fa-history text-primary"></i> Portal Activity Log - ' + party_name);
						$('#uni_modal .modal-body').html(html);
						$('#uni_modal .modal-footer').html('<button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>');
						$('#uni_modal').modal('show');
					} else {
						alert_toast("Failed to load logs.", 'error');
					}
				}
			});
		});
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [7] }
			],
			order: [0, 'asc']
		});
	})
	function delete_party($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_party",
			method:"POST",
			data:{id: $id},
			dataType:"json",
			error:err=>{
				console.log(err)
				alert_toast("An error occurred.",'error');
				end_loader();
			},
			success:function(resp){
				if(typeof resp== 'object' && resp.status == 'success'){
					location.reload();
				}else{
					alert_toast("An error occurred.",'error');
					end_loader();
				}
			}
		})
	}
</script>