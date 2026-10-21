<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">List of Tenants / Companies</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" onclick="uni_modal('<i class=\'fa fa-plus\'></i> Add New Tenant', 'tenants/manage_tenant.php')" class="btn btn-flat btn-primary"><span class="fas fa-plus"></span> Add New Tenant</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="15%">
					<col width="25%">
					<col width="20%">
					<col width="15%">
					<col width="10%">
					<col width="10%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Tenant Code</th>
						<th>Company Name</th>
						<th>Email / Phone</th>
						<th>Address</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$qry = $conn->query("SELECT * from `tenants` where delete_flag = 0 order by id asc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['tenant_code'] ?></b></td>
							<td><?php echo $row['name'] ?></td>
							<td>
								<p class="m-0"><small><?php echo $row['email'] ?></small></p>
								<small class="text-muted"><?php echo $row['phone'] ?></small>
							</td>
							<td><small><?php echo $row['address'] ?></small></td>
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
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" onclick="uni_modal('<i class=\'fa fa-edit\'></i> Edit Tenant', 'tenants/manage_tenant.php?id=<?php echo $row['id'] ?>')"><span class="fa fa-edit text-primary"></span> Edit</a>
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
		if ($.fn.DataTable.isDataTable('#list')) {
			$('#list').DataTable().destroy();
		}
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [5,6] }
			],
			order: [0, 'asc']
		});

		$(document).on('click', '.delete_data', function(e){
			e.preventDefault();
			_conf("Are you sure to delete this Tenant? Data associated with this tenant will be inaccessible.","delete_tenant",[$(this).attr('data-id')])
		});

	})
	function delete_tenant($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_tenant",
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
