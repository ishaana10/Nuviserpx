<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">Attendance Log</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary"><span class="fas fa-plus"></span> Record Attendance</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="15%">
					<col width="25%">
					<col width="15%">
					<col width="15%">
					<col width="10%">
					<col width="15%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Date</th>
						<th>Employee</th>
						<th>Check In</th>
						<th>Check Out</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$scope = $_settings->employee_scope('attendance');
						$own_filter = $scope === null ? '' : " AND a.employee_id = '".(int)$scope."' ";
						$qry = $conn->query("SELECT a.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code from `attendance_list` a inner join `employee_list` e on a.employee_id = e.id where a.tenant_id = '{$tenant_id}'{$own_filter} order by a.attendance_date desc, a.id desc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><?php echo date("M d, Y", strtotime($row['attendance_date'])) ?></td>
							<td>
								<p class="m-0"><?php echo $row['emp_name'] ?></p>
								<small class="text-muted"><?php echo $row['employee_code'] ?></small>
							</td>
							<td class="text-center"><?php echo !empty($row['check_in']) ? date("h:i A", strtotime($row['check_in'])) : '-' ?></td>
							<td class="text-center"><?php echo !empty($row['check_out']) ? date("h:i A", strtotime($row['check_out'])) : '-' ?></td>
							<td class="text-center">
                                <?php if($row['status'] == 'present'): ?>
                                    <span class="badge badge-success px-3 rounded-pill">Present</span>
                                <?php elseif($row['status'] == 'late'): ?>
                                    <span class="badge badge-warning px-3 rounded-pill">Late</span>
                                <?php elseif($row['status'] == 'half_day'): ?>
                                    <span class="badge badge-info px-3 rounded-pill">Half Day</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 rounded-pill">Absent</span>
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
			_conf("Are you sure to delete this attendance record permanently?","delete_attendance",[$(this).attr('data-id')])
		})
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-plus'></i> Record Attendance","attendance/manage_attendance.php")
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Attendance","attendance/manage_attendance.php?id="+$(this).attr('data-id'))
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [5,6] }
			],
			order: [0, 'asc']
		});
	})
	function delete_attendance($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_attendance",
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
