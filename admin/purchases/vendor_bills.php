<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-navy">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-file-invoice-dollar text-navy"></i> Vendor Bills (Accounts Payable)</h3>
		<div class="card-tools">
			<a href="./?page=purchases" class="btn btn-flat btn-outline-secondary btn-sm mr-1"><span class="fas fa-shopping-bag"></span> Purchase Orders</a>
			<a href="./?page=purchases/bill_payments" class="btn btn-flat btn-outline-success btn-sm mr-1"><span class="fas fa-money-check-alt"></span> Supplier Payments</a>
			<a href="./?page=purchases/manage_bill" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> New Vendor Bill</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<thead>
					<tr>
						<th>#</th>
						<th>Bill No</th>
						<th>Vendor</th>
						<th>Bill Date</th>
						<th>Due Date</th>
						<th>Total<?php echo $_settings->currency_tag() ?></th>
						<th>Balance</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT b.*, p.name as vendor_name, p.party_code from `vendor_bill_list` b inner join `party_list` p on b.vendor_id = p.id where b.tenant_id = '{$tenant_id}' order by b.id desc ");
						while($row = $qry->fetch_assoc()):
							$badge_class = 'secondary';
							switch($row['status']){
								case 'unpaid': $badge_class = 'warning'; break;
								case 'partially_paid': $badge_class = 'info'; break;
								case 'paid': $badge_class = 'success'; break;
								case 'cancelled': $badge_class = 'dark'; break;
							}
							$overdue = ($row['status'] != 'paid' && $row['status'] != 'cancelled' && strtotime($row['due_date']) < strtotime(date('Y-m-d')));
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['bill_no'] ?></b><?php echo !empty($row['vendor_ref']) ? '<br><small class="text-muted">Ref: '.htmlspecialchars($row['vendor_ref']).'</small>' : '' ?></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['vendor_name'] ?></p>
								<small class="text-muted"><?php echo $row['party_code'] ?></small>
							</td>
							<td><?php echo date("d M Y", strtotime($row['bill_date'])) ?></td>
							<td><?php echo date("d M Y", strtotime($row['due_date'])) ?> <?php echo $overdue ? '<span class="badge badge-danger">OVERDUE</span>' : '' ?></td>
							<td class="text-right font-weight-bold">$<?php echo number_format($row['total'], 2) ?></td>
							<td class="text-right">$<?php echo number_format($row['balance'], 2) ?></td>
							<td class="text-center">
								<span class="badge badge-<?php echo $badge_class ?> px-2 py-1"><?php echo strtoupper(str_replace('_',' ',$row['status'])) ?></span>
							</td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">Action</button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item" href="./?page=purchases/manage_bill&id=<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> View / Edit</a>
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
			_conf("Delete this vendor bill? Its journal posting will be reversed.","delete_vendor_bill",[$(this).attr('data-id')])
		})
		$('#list').dataTable({ columnDefs: [{ orderable: false, targets: [8] }], order: [0, 'asc'] });
	})
	function delete_vendor_bill($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_vendor_bill",
			method:"POST", data:{id: $id}, dataType:"json",
			error:err=>{ console.log(err); alert_toast("An error occurred.",'error'); end_loader(); },
			success:function(resp){
				if(typeof resp== 'object' && resp.status == 'success'){ location.reload(); }
				else{ alert_toast(resp.msg || "An error occurred.",'error'); end_loader(); }
			}
		})
	}
</script>
