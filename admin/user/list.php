<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<style>
    .img-avatar{
        width:42px;
        height:42px;
        object-fit:cover;
        object-position:center center;
        border-radius:50%;
    }
</style>
<div class="card card-outline card-primary shadow-sm">
	<div class="card-header py-2">
		<h3 class="card-title font-weight-bold text-navy m-0"><i class="fas fa-users-cog text-primary mr-2"></i> System Users & Staff Management</h3>
		<div class="card-tools">
			<a href="?page=user/manage_user" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus mr-1"></span> Create New User</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<div class="table-responsive">
				<table class="table table-hover table-striped table-bordered">
					<thead class="bg-navy text-white text-center">
						<tr>
							<th width="5%">#</th>
							<th width="8%">Avatar</th>
							<th>User Details</th>
							<th width="15%">Role / Privilege</th>
							<th width="15%">Tenant / Company</th>
							<th width="15%">Linked Employee</th>
							<th width="10%">Status</th>
							<th width="10%">Action</th>
						</tr>
					</thead>
					<tbody>
						<?php
							$i = 1;
							$tenant_id = $_settings->active_tenant_id();
							$is_superadmin = $_settings->userdata('type') == 1;

							$where_clause = " WHERE u.id != '1' ";
							if (!$is_superadmin) {
								$where_clause .= " AND u.tenant_id = '{$tenant_id}' ";
							}

							$qry = $conn->query("SELECT u.*, concat(u.firstname,' ',u.lastname) as name, r.name as role_name, t.name as tenant_name,
								concat(e.firstname,' ',e.lastname) as emp_name, e.employee_code
								FROM `users` u
								LEFT JOIN `roles` r ON u.role_id = r.id
								LEFT JOIN `tenants` t ON u.tenant_id = t.id
								LEFT JOIN `employee_list` e ON u.employee_id = e.id
								{$where_clause}
								ORDER BY concat(u.firstname,' ',u.lastname) ASC ");

							while($row = $qry->fetch_assoc()):
								$role_label = ($row['type'] == 1) ? "Super Administrator" : (!empty($row['role_name']) ? $row['role_name'] : "Standard Staff");
						?>
							<tr>
								<td class="text-center align-middle"><?php echo $i++; ?></td>
								<td class="text-center align-middle">
									<img src="<?php echo validate_image($row['avatar']) ?>" class="img-avatar img-thumbnail p-0 border shadow-sm" alt="user_avatar">
								</td>
								<td class="align-middle">
									<p class="m-0 font-weight-bold text-navy"><?php echo ucwords(htmlspecialchars($row['name'])) ?></p>
									<small class="text-muted"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($row['username']) ?></small>
								</td>
								<td class="align-middle text-center">
									<span class="badge badge-<?php echo $row['type'] == 1 ? 'navy' : 'info' ?> px-3 py-1 font-weight-bold">
										<i class="fas <?= $row['type'] == 1 ? 'fa-user-shield' : 'fa-user-tag' ?> mr-1"></i>
										<?php echo htmlspecialchars($role_label) ?>
									</span>
								</td>
								<td class="align-middle text-center">
									<span class="badge badge-light border text-dark px-2 py-1">
										<i class="fas fa-building text-secondary mr-1"></i>
										<?php echo htmlspecialchars($row['tenant_name'] ?? 'Default Tenant') ?>
									</span>
								</td>
								<td class="align-middle text-center">
									<?php if (!empty($row['emp_name'])): ?>
										<span class="badge badge-success px-2 py-1 font-weight-normal">
											<i class="fas fa-user-tie mr-1"></i>
											<?php echo htmlspecialchars($row['emp_name']) ?> (<?= htmlspecialchars($row['employee_code']) ?>)
										</span>
									<?php else: ?>
										<span class="text-muted text-xs">Unlinked</span>
									<?php endif; ?>
								</td>
								<td class="align-middle text-center">
									<?php if($row['status'] == 1): ?>
										<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Verified</span>
									<?php else: ?>
										<span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i> Unverified</span>
									<?php endif; ?>
								</td>
								<td class="align-middle text-center">
									<button type="button" class="btn btn-flat btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
										Action
										<span class="sr-only">Toggle Dropdown</span>
									</button>
									<div class="dropdown-menu dropdown-menu-right" role="menu">
										<a class="dropdown-item" href="?page=user/manage_user&id=<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary mr-2"></span> Edit User</a>
										<div class="dropdown-divider"></div>
										<?php if($row['status'] != 1): ?>
										<a class="dropdown-item verify_user" href="javascript:void(0)" data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['username']) ?>"><span class="fa fa-check text-success mr-2"></span> Verify Account</a>
										<div class="dropdown-divider"></div>
										<?php endif; ?>
										<a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger mr-2"></span> Delete User</a>
									</div>
								</td>
							</tr>
						<?php endwhile; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<script>
	$(document).ready(function(){
		$('.delete_data').click(function(){
			_conf("Are you sure to delete this User permanently?","delete_user",[$(this).attr('data-id')])
		})
		$('.table td,.table th').addClass('py-1 px-2 align-middle')
		$('.table').dataTable({
			columnDefs: [
				{ orderable: false, targets: [1, 7] }
			]
		});
		$('.verify_user').click(function(){
			_conf("Are you sure to verify <b>"+$(this).attr('data-name')+"<b/>?","verify_user",[$(this).attr('data-id')])
		})
	})
	function delete_user($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Users.php?f=delete",
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
	function verify_user($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Users.php?f=verify_user",
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
