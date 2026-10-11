<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-boxes text-primary"></i> Product Catalog & Commercial Stock</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Add New Product</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="23%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>SKU Code</th>
						<th>Product Name</th>
						<th>Category</th>
						<th>Cost Price</th>
						<th>Selling Price</th>
						<th>Stock on Hand</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT * from `product_list` where tenant_id = '{$tenant_id}' and delete_flag = 0 order by name asc ");
						while($row = $qry->fetch_assoc()):
							$is_low = (float)$row['stock_quantity'] <= (float)$row['reorder_level'];
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo $row['product_code'] ?></b></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['name'] ?></p>
								<small class="text-muted"><?php echo htmlspecialchars($row['description'] ?? '') ?></small>
							</td>
							<td>
								<span class="badge badge-light border text-dark px-2 py-1">
									<?php echo !empty($row['category']) ? $row['category'] : 'General' ?>
								</span>
							</td>
							<td class="text-right">$<?php echo number_format($row['cost_price'], 2) ?></td>
							<td class="text-right font-weight-bold text-success">$<?php echo number_format($row['selling_price'], 2) ?></td>
							<td class="text-center">
                                <span class="badge badge-<?php echo $is_low ? 'danger' : 'success' ?> px-3 py-1 font-weight-bold">
                                    <?php echo number_format($row['stock_quantity'], 2) . ' ' . $row['unit'] ?>
                                </span>
                                <?php if($is_low): ?>
					<small class="d-block text-danger font-weight-bold"><i class="fas fa-exclamation-triangle"></i> Low Stock</small>
                                <?php endif; ?>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <a class="dropdown-item" href="./?page=stock_adjustments&product_id=<?php echo $row['id'] ?>"><span class="fa fa-cubes text-info"></span> Stock History</a>
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
			_conf("Are you sure to delete this product permanently?","delete_product",[$(this).attr('data-id')])
		})
		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-plus'></i> Add New Product","products/manage_product.php", 'large')
		})
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Product Details","products/manage_product.php?id="+$(this).attr('data-id'), 'large')
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [7] }
			],
			order: [2, 'asc']
		});
	})
	function delete_product($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_product",
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