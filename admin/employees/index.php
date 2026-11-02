<?php
$can_create = $_settings->can_access('employees_create');
$can_edit   = $_settings->can_access('employees_edit');
$can_delete = $_settings->can_access('employees_delete');

// Standard users see only their own employee record
$scope = $_settings->employee_scope('employees');
$tenant_id = $_settings->active_tenant_id();

$where = "tenant_id = '{$tenant_id}' AND delete_flag = 0";
if ($scope !== null) {
    $scope_id = intval($scope);
    $where .= " AND id = '{$scope_id}'";
}
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">List of Employees</h3>
		<?php if($can_create): ?>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary"><span class="fas fa-plus"></span> Create New Employee</a>
		</div>
		<?php endif; ?>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="18%">
					<col width="18%">
					<col width="15%">
					<col width="12%">
					<col width="10%">
					<?php if($can_edit || $can_delete): ?><col width="10%"><?php endif; ?>
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Emp Code</th>
						<th>Name</th>
						<th>TIN / FNPF</th>
						<th>Department / Designation</th>
						<th>Monthly Salary</th>
						<th>Status</th>
						<?php if($can_edit || $can_delete): ?><th>Action</th><?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$qry = $conn->query("SELECT *, concat(firstname, ' ', lastname) as name from `employee_list` where {$where} order by id desc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['employee_code'] ?></b></td>
							<td><?php echo $row['name'] ?></td>
							<td>
								<small class="d-block"><b>TIN:</b> <?php echo !empty($row['tin']) ? $row['tin'] : 'N/A' ?></small>
								<small class="d-block text-muted"><b>FNPF:</b> <?php echo !empty($row['fnpf_no']) ? $row['fnpf_no'] : 'N/A' ?></small>
							</td>
							<td>
								<p class="m-0"><?php echo $row['department'] ?></p>
								<small class="text-muted"><?php echo $row['designation'] ?></small>
							</td>
							<td class="text-right font-weight-bold"><?php echo number_format($row['salary'], 2) ?> FJD</td>
							<td class="text-center">
                                <?php if($row['status'] == 1): ?>
                                    <span class="badge badge-success px-3 rounded-pill">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 rounded-pill">Inactive</span>
                                <?php endif; ?>
                            </td>
							<?php if($can_edit || $can_delete): ?>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <?php if($can_edit): ?>
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <?php endif; ?>
				                    <?php if($can_edit && $can_delete): ?><div class="dropdown-divider"></div><?php endif; ?>
				                    <?php if($can_delete): ?>
				                    <a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
				                    <?php endif; ?>
				                  </div>
							</td>
							<?php endif; ?>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<script>
	$(document).ready(function(){
		<?php if($can_delete): ?>
		$('.delete_data').click(function(){
			_conf("Are you sure to delete this Employee permanently?","delete_employee",[$(this).attr('data-id')])
		})
		<?php endif; ?>
		<?php if($can_create): ?>
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-plus'></i> Add New Employee","employees/manage_employee.php", 'large')
		})
		<?php endif; ?>
		<?php if($can_edit): ?>
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Employee","employees/manage_employee.php?id="+$(this).attr('data-id'), 'large')
		})
		<?php endif; ?>
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [<?php echo ($can_edit || $can_delete) ? '6,7' : '6' ?>] }
			],
			order: [0, 'asc']
		});
	})
	<?php if($can_delete): ?>
	function delete_employee($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_employee",
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
	<?php endif; ?>
</script>