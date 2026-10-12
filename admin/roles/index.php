<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<ul class="nav nav-tabs mb-3" id="settingsTab" role="tablist">
  <li class="nav-item">
    <a class="nav-link text-secondary font-weight-bold" href="<?php echo base_url ?>admin/?page=system_info"><i class="fas fa-cogs mr-1"></i> System Information & Email</a>
  </li>
  <li class="nav-item">
    <a class="nav-link active font-weight-bold" href="<?php echo base_url ?>admin/?page=roles"><i class="fas fa-user-shield mr-1"></i> User Roles & Permissions</a>
  </li>
</ul>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-user-shield text-primary"></i> Customizable User Access Levels & Roles</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Add New Role</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="20%">
					<col width="35%">
					<col width="15%">
					<col width="10%">
					<col width="15%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Role Name</th>
						<th>Description / Scope</th>
						<th>Active Users</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT r.*, COUNT(u.id) as user_count from `roles` r left join `users` u on u.role_id = r.id where r.tenant_id = '{$tenant_id}' and r.delete_flag = 0 group by r.id order by r.name asc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo htmlspecialchars($row['name']) ?></b></td>
							<td>
								<p class="m-0 text-muted small"><?php echo htmlspecialchars($row['description'] ?? 'Custom Role') ?></p>
								<small class="badge badge-light border text-navy"><?php echo !empty($row['permissions']) ? count(explode(',', $row['permissions'])) . ' Modules Granted' : 'No Access' ?></small>
							</td>
							<td class="text-center">
								<span class="badge badge-info px-2 py-1"><i class="fas fa-users"></i> <?php echo $row['user_count'] ?> Users</span>
							</td>
							<td class="text-center">
                                <?php if($row['status'] == 1): ?>
                                    <span class="badge badge-success px-2 py-1 rounded-pill">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-2 py-1 rounded-pill">Inactive</span>
                                <?php endif; ?>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit Permissions</a>
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
			_conf("Are you sure to delete this role permanently? Users assigned to this role will revert to default staff access.","delete_role",[$(this).attr('data-id')])
		})
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-user-shield'></i> Add Custom Role","roles/manage_role.php", 'large')
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Role Permissions","roles/manage_role.php?id="+$(this).attr('data-id'), 'large')
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [5] }
			],
			order: [1, 'asc']
		});
	})
	function delete_role($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_role",
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