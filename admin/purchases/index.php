<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-shopping-bag text-primary"></i> Purchase Orders</h3>
		<div class="card-tools">
			<a href="./?page=purchases/vendor_bills" class="btn btn-flat btn-outline-secondary btn-sm mr-1"><span class="fas fa-file-invoice-dollar"></span> Vendor Bills</a>
			<a href="./?page=purchases/manage_po" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> New Purchase Order</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="13%">
					<col width="22%">
					<col width="12%">
					<col width="13%">
					<col width="18%">
					<col width="17%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>PO No</th>
						<th>Vendor / Supplier</th>
						<th>Order Date</th>
						<th>Total<?php echo $_settings->currency_tag() ?></th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT po.*, p.name as vendor_name, p.party_code from `purchase_order_list` po inner join `party_list` p on po.vendor_id = p.id where po.tenant_id = '{$tenant_id}' order by po.id desc ");
						while($row = $qry->fetch_assoc()):
							$badge_class = 'secondary';
							switch($row['status']){
								case 'sent': $badge_class = 'info'; break;
								case 'confirmed': $badge_class = 'primary'; break;
								case 'partially_received': $badge_class = 'warning'; break;
								case 'received': $badge_class = 'success'; break;
								case 'billed': $badge_class = 'primary'; break;
								case 'cancelled': $badge_class = 'dark'; break;
							}
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['po_no'] ?></b></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['vendor_name'] ?></p>
								<small class="text-muted"><?php echo $row['party_code'] ?></small>
							</td>
							<td><?php echo date("d M Y", strtotime($row['order_date'])) ?></td>
							<td class="text-right font-weight-bold">$<?php echo number_format($row['total'], 2) ?></td>
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
				                    <a class="dropdown-item" href="./?page=purchases/view&id=<?php echo $row['id'] ?>"><span class="fa fa-eye text-info"></span> View PO</a>
				                    <?php if(!in_array($row['status'], ['received','billed','cancelled'])): ?>
				                    <a class="dropdown-item" href="./?page=purchases/receive&id=<?php echo $row['id'] ?>"><span class="fa fa-truck-loading text-success"></span> Receive Goods</a>
				                    <a class="dropdown-item" href="./?page=purchases/manage_po&id=<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <?php endif; ?>
				                    <?php if($row['status'] != 'cancelled'): ?>
				                    <a class="dropdown-item" href="./?page=purchases/manage_bill&po_id=<?php echo $row['id'] ?>"><span class="fa fa-file-invoice-dollar text-navy"></span> Create Vendor Bill</a>
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
			_conf("Are you sure to delete this purchase order?","delete_purchase_order",[$(this).attr('data-id')])
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [6] }
			],
			order: [0, 'asc']
		});
	})
	function delete_purchase_order($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_purchase_order",
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
					alert_toast(resp.msg || "An error occurred.",'error');
					end_loader();
				}
			}
		})
	}
</script>
