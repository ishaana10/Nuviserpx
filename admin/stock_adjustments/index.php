<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<?php
$tenant_id = $_settings->active_tenant_id();
$product_filter = isset($_GET['product_id']) && is_numeric($_GET['product_id']) ? intval($_GET['product_id']) : 'all';
$prod_where = ($product_filter != 'all' && $product_filter > 0) ? " AND l.product_id = '{$product_filter}' " : "";
?>

<div class="card card-outline card-info">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-cubes text-info"></i> Stock Movement Ledger & Adjustments</h3>
		<div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-info btn-sm"><span class="fas fa-plus"></span> Record Stock Adjustment</a>
		</div>
	</div>
	<div class="card-body">
		<div class="callout border-info shadow rounded-0 mb-3">
			<form action="" id="filter">
				<input type="hidden" name="page" value="stock_adjustments">
				<div class="row align-items-end">
					<div class="col-md-6 form-group">
						<label for="product_id" class="control-label">Filter Product</label>
						<select name="product_id" id="product_id" class="form-control form-control-sm rounded-0 select2">
							<option value="all" <?php echo $product_filter == 'all' ? 'selected' : '' ?>>All Products</option>
							<?php
							$prods = $conn->query("SELECT * FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
							while($p = $prods->fetch_assoc()):
							?>
							<option value="<?php echo $p['id'] ?>" <?php echo $product_filter == $p['id'] ? 'selected' : '' ?>>
								<?php echo $p['product_code'] . ' - ' . $p['name'] ?>
							</option>
							<?php endwhile; ?>
						</select>
					</div>
					<div class="col-md-3 form-group">
						<button class="btn btn-info btn-flat btn-sm"><i class="fa fa-filter"></i> Filter Ledger</button>
						<a href="./?page=stock_adjustments" class="btn btn-default border btn-flat btn-sm">Reset</a>
					</div>
				</div>
			</form>
		</div>

		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="20%">
					<col width="10%">
					<col width="12%">
					<col width="15%">
					<col width="26%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Date</th>
						<th>Product</th>
						<th>Type</th>
						<th>Qty</th>
						<th>Reference</th>
						<th>Notes</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$qry = $conn->query("SELECT l.*, p.name as product_name, p.product_code, p.unit
							FROM `inventory_logs` l
							INNER JOIN `product_list` p ON l.product_id = p.id
							WHERE l.tenant_id = '{$tenant_id}' {$prod_where}
							ORDER BY l.id DESC ");

						while($row = $qry->fetch_assoc()):
							$type_class = 'success';
							if($row['type'] == 'out') $type_class = 'danger';
							elseif($row['type'] == 'adjustment') $type_class = 'warning';
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><?php echo date("d M Y H:i", strtotime($row['date_created'])) ?></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['product_name'] ?></p>
								<small class="text-muted"><?php echo $row['product_code'] ?></small>
							</td>
							<td class="text-center">
								<span class="badge badge-<?php echo $type_class ?> px-2 py-1">
									<?php echo strtoupper($row['type']) ?>
								</span>
							</td>
							<td class="text-right font-weight-bold text-<?php echo $row['type'] == 'out' ? 'danger' : 'success' ?>">
								<?php echo ($row['type'] == 'out' ? '-' : '+') . number_format($row['qty'], 2) . ' ' . $row['unit'] ?>
							</td>
							<td><b><?php echo htmlspecialchars($row['reference'] ?? 'N/A') ?></b></td>
							<td><small class="text-muted"><?php echo htmlspecialchars($row['notes'] ?? '') ?></small></td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<script>
	$(document).ready(function(){
		$('.select2').select2({ width: '100%' });

		$('#create_new').click(function(){
			uni_modal("<i class='fa fa-cubes'></i> Record Manual Stock Adjustment","stock_adjustments/manage_adjustment.php", 'large')
		});

		$('#list').dataTable({
			order: [0, 'asc']
		});
	});
</script>