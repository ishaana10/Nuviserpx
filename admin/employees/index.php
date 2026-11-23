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

$portal_status = [];
try {
    $portal_status = (new \App\Services\EmployeePortalService($conn, $_settings))->statusMap();
} catch (\Throwable $e) {
    $portal_status = [];
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
			<table class="table table-hover table-striped table-bordered" id="list" data-nv-cards>
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
						<th>Annual Gross</th>
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
							<td><b><?php echo htmlspecialchars((string)($row['employee_code']), ENT_QUOTES) ?></b></td>
							<td><?php echo htmlspecialchars((string)($row['name']), ENT_QUOTES) ?></td>
							<td>
								<small class="d-block"><b>TIN:</b> <?php echo !empty($row['tin']) ? htmlspecialchars((string)($row['tin']), ENT_QUOTES) : 'N/A' ?></small>
								<small class="d-block text-muted"><b>FNPF:</b> <?php echo !empty($row['fnpf_no']) ? htmlspecialchars((string)($row['fnpf_no']), ENT_QUOTES) : 'N/A' ?></small>
							</td>
							<td>
								<p class="m-0"><?php echo htmlspecialchars((string)($row['department']), ENT_QUOTES) ?></p>
								<small class="text-muted"><?php echo htmlspecialchars((string)($row['designation']), ENT_QUOTES) ?></small>
							</td>
							<td class="text-right font-weight-bold"><?php echo number_format((float)($row['annual_salary'] ?? 0) > 0 ? (float)$row['annual_salary'] : (float)$row['salary'] * 12, 2) ?> <?php echo htmlspecialchars($_settings->base_currency()) ?></td>
							<td class="text-center">
                                <?php if($row['status'] == 1): ?>
                                    <span class="badge badge-success px-3 rounded-pill">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 rounded-pill">Inactive</span>
                                <?php endif; ?>
                                <?php $ps = $portal_status[(int)$row['id']] ?? ''; ?>
                                <?php if($ps === 'active'): ?>
                                    <small class="d-block text-success"><i class="fa fa-user-check"></i> Self-service on</small>
                                <?php elseif($ps === 'invited'): ?>
                                    <small class="d-block text-warning"><i class="fa fa-envelope"></i> Invited</small>
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
				                    <a class="dropdown-item invite_portal" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-envelope text-info"></span> <?php echo ($portal_status[(int)$row['id']] ?? '') === '' ? 'Invite to self-service' : 'Resend invitation' ?></a>
				                    <?php if(isset($portal_status[(int)$row['id']])): ?>
				                    <a class="dropdown-item revoke_portal" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-user-slash text-warning"></span> Remove self-service access</a>
				                    <?php endif; ?>
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
		// The email could not be sent: show the link in a box HR can copy (a browser alert() cannot be copied from on a phone).
		function show_invite_link(resp){
			$('#inviteLinkModal').remove();
			var $m = $('<div class="modal fade" id="inviteLinkModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">' +
				'<div class="modal-header"><h5 class="modal-title">Share the sign-up link</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button></div>' +
				'<div class="modal-body"><p class="text-danger mb-2"><i class="fa fa-exclamation-triangle"></i> The email could not be sent, so give this link to the employee yourself. It is valid for 7 days and works once.</p>' +
				'<div class="input-group mb-2"><input type="text" class="form-control" id="inviteLinkInput" readonly><div class="input-group-append"><button type="button" class="btn btn-primary" id="inviteLinkCopy"><i class="fa fa-copy"></i> Copy</button></div></div>' +
				'<p class="mb-0 text-sm text-muted">Their username for signing in is their email address: <strong id="inviteLinkUser"></strong></p></div>' +
				'<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Done</button></div></div></div></div>');
			$m.find('#inviteLinkInput').val(resp.link);
			$m.find('#inviteLinkUser').text(resp.username || '');
			$('body').append($m);
			$m.on('hidden.bs.modal', function(){ location.reload(); });
			$m.find('#inviteLinkCopy').on('click', function(){
				var el = $m.find('#inviteLinkInput')[0]; el.focus(); el.select(); el.setSelectionRange(0, 99999);
				var done = function(){ alert_toast('Link copied','success'); };
				if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(resp.link).then(done, function(){ document.execCommand('copy'); done(); }); }
				else { try { document.execCommand('copy'); done(); } catch(e){} }
			});
			$m.modal('show');
			setTimeout(function(){ $m.find('#inviteLinkInput').trigger('focus').select(); }, 400);
		}
		function portal_call(f, id, ask){
			if(ask && !confirm(ask)) return;
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f="+f, method:"POST", data:{employee_id:id}, dataType:"json",
				error:function(){ alert_toast("An error occurred.",'error'); end_loader(); },
				success:function(resp){
					end_loader();
					if(resp.status=='success' && resp.link){ show_invite_link(resp); }
					else if(resp.status=='success'){ alert_toast(resp.msg,'success'); setTimeout(function(){ location.reload() }, 1200); }
					else { alert(resp.msg || "An error occurred."); }
				}
			})
		}
		$('.invite_portal').click(function(){ portal_call('invite_employee_portal', $(this).attr('data-id'), null) })
		$('.revoke_portal').click(function(){ portal_call('revoke_employee_portal', $(this).attr('data-id'), 'Remove this employee\'s self-service access?') })
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