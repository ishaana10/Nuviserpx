<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-plug text-primary"></i> RESTful API Keys & Integrations</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Generate API Key</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="20%">
					<col width="30%">
					<col width="15%">
					<col width="15%">
					<col width="5%">
					<col width="10%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Application Name</th>
						<th>API Key</th>
						<th>Permissions</th>
						<th>Last Used</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT * from `api_keys` where tenant_id = '{$tenant_id}' order by id desc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo htmlspecialchars($row['name']) ?></b></td>
							<td>
								<code><?php echo htmlspecialchars($row['api_key']) ?></code>
							</td>
							<td>
								<small class="badge badge-light border text-navy"><?php echo htmlspecialchars($row['permissions']) ?></small>
							</td>
							<td>
								<small class="text-muted"><?php echo !empty($row['last_used_at']) ? date("d M Y H:i", strtotime($row['last_used_at'])) : 'Never' ?></small>
							</td>
							<td class="text-center">
                                <?php if($row['status'] == 1): ?>
                                    <span class="badge badge-success px-2 py-1 rounded-pill">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-2 py-1 rounded-pill">Revoked</span>
                                <?php endif; ?>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <div class="dropdown-divider"></div>
				                    <a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Revoke / Delete</a>
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
			_conf("Are you sure to revoke and delete this API Key? External applications using this key will immediately lose access.","delete_api_key",[$(this).attr('data-id')])
		})
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-key'></i> Generate API Key","api_keys/manage_api_key.php", 'large')
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit API Key","api_keys/manage_api_key.php?id="+$(this).attr('data-id'), 'large')
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [6] }
			],
			order: [0, 'asc']
		});
	})
	function delete_api_key($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_api_key",
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