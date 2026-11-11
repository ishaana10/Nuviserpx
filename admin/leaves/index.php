<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">Leave Applications</h3>
		<?php if($_settings->can_access('leaves_create')): ?>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary"><span class="fas fa-plus"></span> Apply Leave</a>
		</div>
		<?php endif; ?>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="20%">
					<col width="15%">
					<col width="20%">
					<col width="20%">
					<col width="10%">
					<col width="10%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Employee</th>
						<th>Leave Type</th>
						<th>Dates</th>
						<th>Reason</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id   = $_settings->active_tenant_id();
						$my_emp      = (int)$_settings->own_employee_id();
						$is_super    = $_settings->is_super_admin();
						$is_leave_mgr= $_settings->can_access('leaves_edit');   // HR / leave administrator
						$can_del     = $_settings->can_access('leaves_delete');

						// What this user may see:
						//  - super admin / leave manager: every leave in the tenant
						//  - everyone else: their own leaves + leaves of staff who report to them (they are the approver)
						if($is_super || $is_leave_mgr){
							$filter = '';
						} else {
							$filter = " AND (l.employee_id = '{$my_emp}' OR e.approver_id = '{$my_emp}') ";
						}
						$qry = $conn->query("SELECT l.*, e.approver_id, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code from `leave_list` l inner join `employee_list` e on l.employee_id = e.id where l.tenant_id = '{$tenant_id}'{$filter} order by l.id desc ");
						while($row = $qry->fetch_assoc()):
							$status      = strtolower($row['status']);
							$is_pending  = ($status === 'pending');
							$is_owner    = ($my_emp > 0 && (int)$row['employee_id'] === $my_emp);
							$is_approver = ($is_super || ($my_emp > 0 && (int)$row['approver_id'] === $my_emp));

							// Owner may edit/cancel only while still pending; managers may always edit; approver can approve/reject pending.
							$can_edit_row    = $is_leave_mgr || ($is_owner && $is_pending);
							$can_cancel_row  = $is_owner && $is_pending;
							$can_approve_row = $is_approver && $is_pending && !$is_owner; // can't approve your own request
							$can_delete_row  = $is_super || $can_del;
							$has_action = $can_edit_row || $can_cancel_row || $can_approve_row || $can_delete_row;
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td>
								<p class="m-0"><?php echo $row['emp_name'] ?></p>
								<small class="text-muted"><?php echo $row['employee_code'] ?></small>
							</td>
							<td class="text-capitalize"><?php echo $row['leave_type'] ?> Leave</td>
							<td>
								<small><b>From:</b> <?php echo date("M d, Y", strtotime($row['start_date'])) ?></small><br>
								<small><b>To:</b> <?php echo date("M d, Y", strtotime($row['end_date'])) ?></small>
								<?php if(isset($row['day_fraction']) && (float)$row['day_fraction'] == 0.5): ?><small class="d-block text-info">Half day</small><?php endif; ?>
							</td>
							<td><p class="m-0 text-truncate" style="max-width: 200px;"><?php echo $row['reason'] ?></p></td>
							<td class="text-center">
                                <?php if($status == 'approved'): ?>
                                    <span class="badge badge-success px-3 rounded-pill">Approved</span>
                                <?php elseif($status == 'pending'): ?>
                                    <span class="badge badge-warning px-3 rounded-pill">Pending</span>
                                <?php elseif($status == 'cancelled'): ?>
                                    <span class="badge badge-secondary px-3 rounded-pill">Cancelled</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 rounded-pill">Rejected</span>
                                <?php endif; ?>
                            </td>
							<td align="center">
								<?php if($has_action): ?>
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <?php if($can_edit_row): ?>
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <?php endif; ?>
				                    <?php if($can_approve_row): ?>
				                    <a class="dropdown-item update_status" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-status="approved"><span class="fa fa-check text-success"></span> Approve</a>
				                    <a class="dropdown-item update_status" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-status="rejected"><span class="fa fa-times text-danger"></span> Reject</a>
				                    <?php endif; ?>
				                    <?php if($can_cancel_row): ?>
				                    <a class="dropdown-item cancel_leave" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-ban text-warning"></span> Cancel Request</a>
				                    <?php endif; ?>
				                    <?php if($can_delete_row && ($can_edit_row || $can_approve_row || $can_cancel_row)): ?><div class="dropdown-divider"></div><?php endif; ?>
				                    <?php if($can_delete_row): ?>
				                    <a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
				                    <?php endif; ?>
				                  </div>
								<?php else: ?>
								<span class="text-muted">&mdash;</span>
								<?php endif; ?>
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
			_conf("Are you sure to delete this leave application permanently?","delete_leave",[$(this).attr('data-id')])
		})
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-plus'></i> Apply Leave","leaves/manage_leave.php")
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Leave","leaves/manage_leave.php?id="+$(this).attr('data-id'))
		})
		$('.update_status').click(function(){
			update_status($(this).attr('data-id'), $(this).attr('data-status'))
		})
		$('.cancel_leave').click(function(){
			_conf("Cancel this leave request? This cannot be undone.","cancel_leave_req",[$(this).attr('data-id')])
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [5,6] }
			],
			order: [0, 'asc']
		});
	})
	function update_status($id, $status){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=update_leave_status",
			method:"POST",
			data:{id: $id, status: $status},
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
	function delete_leave($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_leave",
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
	function cancel_leave_req($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=cancel_leave",
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
					alert_toast((resp && resp.msg) ? resp.msg : "An error occurred.",'error');
					end_loader();
				}
			}
		})
	}
</script>
