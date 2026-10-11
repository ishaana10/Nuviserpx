<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-file-invoice text-primary"></i> Invoices Management</h3>
		<div class="card-tools">
			<a href="./?page=invoices/manage_invoice" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Create New Invoice</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="20%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
					<col width="10%">
					<col width="5%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Invoice No</th>
						<th>Customer / Party</th>
						<th>Invoice Date</th>
						<th>Due Date</th>
						<th>Total</th>
						<th>Balance Due</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT i.*, p.name as party_name, p.party_code from `invoice_list` i inner join `party_list` p on i.party_id = p.id where i.tenant_id = '{$tenant_id}' order by i.id desc ");
						while($row = $qry->fetch_assoc()):
							$badge_class = 'secondary';
							if($row['status'] == 'paid') $badge_class = 'success';
							elseif($row['status'] == 'unpaid') $badge_class = 'danger';
							elseif($row['status'] == 'partially_paid') $badge_class = 'warning';
							elseif($row['status'] == 'cancelled') $badge_class = 'dark';
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['invoice_no'] ?></b></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['party_name'] ?></p>
								<small class="text-muted"><?php echo $row['party_code'] ?></small>
							</td>
							<td><?php echo date("d M Y", strtotime($row['invoice_date'])) ?></td>
							<td><?php echo !empty($row['due_date']) ? date("d M Y", strtotime($row['due_date'])) : '—' ?></td>
							<td class="text-right font-weight-bold"><?php echo number_format($row['total'], 2) ?></td>
							<td class="text-right font-weight-bold text-<?php echo $row['balance'] > 0 ? 'danger' : 'success' ?>"><?php echo number_format($row['balance'], 2) ?></td>
							<td class="text-center">
                                <span class="badge badge-<?php echo $badge_class ?> px-2 py-1">
                                    <?php echo strtoupper(str_replace('_', ' ', $row['status'])) ?>
                                </span>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item" href="./?page=invoices/view&id=<?php echo $row['id'] ?>"><span class="fa fa-eye text-info"></span> View Invoice</a>
				                    <a class="dropdown-item send_email" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-email="<?php echo htmlspecialchars($row['party_email'] ?? '') ?>"><span class="fa fa-envelope text-warning"></span> Send Email</a>
				                    <a class="dropdown-item" href="./?page=invoices/manage_invoice&id=<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <?php if($row['balance'] > 0): ?>
				                    <a class="dropdown-item" href="./?page=payments/manage_payment&party_id=<?php echo $row['party_id'] ?>&invoice_id=<?php echo $row['id'] ?>"><span class="fa fa-money-bill-wave text-success"></span> Record Receipt</a>
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
			_conf("Are you sure to delete this invoice permanently? Associated journal entry will also be removed.","delete_invoice",[$(this).attr('data-id')])
		})
		$('.send_email').click(function(){
			var id = $(this).attr('data-id');
			var email = $(this).attr('data-email');
			var target_email = prompt("Enter customer email address:", email);
			if(target_email){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/Master.php?f=email_invoice",
					method: "POST",
					data: { id: id, custom_email: target_email },
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
	function delete_invoice($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_invoice",
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