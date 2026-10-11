<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-success">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-receipt text-success"></i> Payment Receipts & Vouchers</h3>
		<div class="card-tools">
			<a href="./?page=payments/manage_payment" class="btn btn-flat btn-success btn-sm"><span class="fas fa-plus"></span> Record New Receipt</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="12%">
					<col width="20%">
					<col width="12%">
					<col width="14%">
					<col width="10%">
					<col width="15%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Payment No</th>
						<th>Payment Date</th>
						<th>Customer / Party</th>
						<th>Method</th>
						<th>Amount (FJD)</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT p.*, pt.name as party_name, pt.party_code from `payment_list` p inner join `party_list` pt on p.party_id = pt.id where p.tenant_id = '{$tenant_id}' order by p.id desc ");
						while($row = $qry->fetch_assoc()):
							$badge_class = 'success';
							if($row['status'] == 'draft') $badge_class = 'secondary';
							elseif($row['status'] == 'cancelled') $badge_class = 'danger';
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['payment_no'] ?></b></td>
							<td><?php echo date("d M Y", strtotime($row['payment_date'])) ?></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['party_name'] ?></p>
								<small class="text-muted"><?php echo $row['party_code'] ?></small>
							</td>
							<td>
								<span class="badge badge-light border text-dark px-2 py-1">
									<i class="fas fa-wallet mr-1 text-primary"></i> <?php echo ucfirst($row['method']) ?>
								</span>
							</td>
							<td class="text-right font-weight-bold text-success">$<?php echo number_format($row['amount'], 2) ?></td>
							<td class="text-center">
                                <span class="badge badge-<?php echo $badge_class ?> px-2 py-1">
                                    <?php echo strtoupper($row['status']) ?>
                                </span>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item" href="./?page=payments/view&id=<?php echo $row['id'] ?>"><span class="fa fa-receipt text-success"></span> View Receipt</a>
				                    <a class="dropdown-item send_email" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-email="<?php echo htmlspecialchars($row['party_email'] ?? '') ?>"><span class="fa fa-envelope text-warning"></span> Send Email</a>
				                    <a class="dropdown-item" href="./?page=payments/manage_payment&id=<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
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
			_conf("Are you sure to delete this payment receipt permanently? Associated invoice balances and journal entries will be updated.","delete_payment",[$(this).attr('data-id')])
		})
		$('.send_email').click(function(){
			var id = $(this).attr('data-id');
			var email = $(this).attr('data-email');
			var target_email = prompt("Enter customer email address:", email);
			if(target_email){
				start_loader();
				$.ajax({
					url: _base_url_ + "classes/Master.php?f=email_payment_receipt",
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
					{ orderable: false, targets: [7] }
			],
			order: [0, 'asc']
		});
	})
	function delete_payment($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_payment",
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