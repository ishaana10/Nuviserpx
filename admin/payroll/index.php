<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-file-invoice-dollar text-primary"></i> Fiji Payroll Management & Processing</h3>
		<div class="card-tools">
			<div class="btn-group mr-2">
				<button type="button" class="btn btn-flat btn-info btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
					<i class="fas fa-file-export"></i> Compliance & Bank Exports
				</button>
				<div class="dropdown-menu dropdown-menu-right">
					<h6 class="dropdown-header">Direct Bank File Formats</h6>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=bsp" target="_blank"><i class="fas fa-university text-primary"></i> BSP Fiji CSV</a>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=anz" target="_blank"><i class="fas fa-university text-info"></i> ANZ Fiji CSV</a>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=hfc" target="_blank"><i class="fas fa-university text-warning"></i> HFC Bank CSV</a>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=bred" target="_blank"><i class="fas fa-university text-danger"></i> BRED Bank CSV</a>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=generic" target="_blank"><i class="fas fa-file-csv text-secondary"></i> Generic Bank CSV</a>
					<div class="dropdown-divider"></div>
					<h6 class="dropdown-header">Fiji Tax & Superannuation</h6>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=frcs" target="_blank"><i class="fas fa-calculator text-success"></i> FRCS TPOS PAYE Return CSV</a>
					<a class="dropdown-item" href="<?php echo base_url ?>admin/payroll/export.php?type=fnpf" target="_blank"><i class="fas fa-shield-alt text-primary"></i> FNPF Schedule CSV</a>
				</div>
			</div>
			<a href="javascript:void(0)" id="process_batch" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-users"></span> Process Batch Payroll</a>
			<a href="javascript:void(0)" id="create_single" class="btn btn-flat btn-success btn-sm"><span class="fas fa-plus"></span> Single Employee Run</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="18%">
					<col width="10%">
					<col width="10%">
					<col width="10%">
					<col width="10%">
					<col width="12%">
					<col width="13%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Run Ref / Month</th>
						<th>Employee</th>
						<th>Gross Earnings</th>
						<th>FNPF (8%)</th>
						<th>PAYE Tax</th>
						<th>Deductions</th>
						<th>Net Salary</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code from `payroll_list` p inner join `employee_list` e on p.employee_id = e.id where p.tenant_id = '{$tenant_id}' order by p.id desc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td>
								<span class="badge badge-light border text-dark"><?php echo !empty($row['payroll_run_ref']) ? $row['payroll_run_ref'] : 'RUN-'.$row['id'] ?></span>
								<p class="m-0 small font-weight-bold"><?php echo $row['salary_month'] ?></p>
							</td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['emp_name'] ?></p>
								<small class="text-muted"><?php echo $row['employee_code'] ?> | TIN: <?php echo !empty($row['tin']) ? $row['tin'] : 'N/A' ?></small>
							</td>
							<td class="text-right"><?php echo number_format($row['gross_salary'] > 0 ? $row['gross_salary'] : ($row['basic_salary'] + $row['allowances']), 2) ?></td>
							<td class="text-right text-info">-<?php echo number_format($row['employee_fnpf'], 2) ?></td>
							<td class="text-right text-warning">-<?php echo number_format($row['paye_tax'], 2) ?></td>
							<td class="text-right text-danger">-<?php echo number_format($row['deductions'], 2) ?></td>
							<td class="text-right font-weight-bold text-success"><?php echo number_format($row['net_salary'], 2) ?> FJD</td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item view_payslip" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-file-invoice-dollar text-info"></span> Fiji Payslip</a>
				                    <a class="dropdown-item email_payslip" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-envelope text-warning"></span> Email Payslip</a>
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
			_conf("Are you sure to delete this payroll record? Associated journal entries will also be removed.","delete_payroll",[$(this).attr('data-id')])
		})
		$('#process_batch').click(function(){
			uni_modal("<i class='fa fa-users'></i> Process Batch Fiji Payroll","payroll/manage_payroll.php?batch=1", 'extra-large')
		})
		$('#create_single').click(function(){
			uni_modal("<i class='fa fa-plus'></i> Single Employee Payroll Run","payroll/manage_payroll.php", 'large')
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Payroll Record","payroll/manage_payroll.php?id="+$(this).attr('data-id'), 'large')
		})
		$('.view_payslip').click(function(){
			uni_modal("<i class='fa fa-file-alt'></i> Employee Fiji Payslip","payroll/view_payslip.php?id="+$(this).attr('data-id'), 'large')
		})
		$('.email_payslip').click(function(){
			var id = $(this).attr('data-id');
			var email = prompt("Enter employee email address:");
			if(email){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/Master.php?f=email_payslip",
					method: "POST",
					data: { id: id, custom_email: email },
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
							alert_toast(resp.msg || "Failed to send email.", 'error');
						}
					}
				});
			}
		});
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [8] }
			],
			order: [0, 'asc']
		});
	})
	function delete_payroll($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_payroll",
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