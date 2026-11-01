<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$po = $conn->query("SELECT po.*, p.name as vendor_name, p.party_code, p.email as vendor_email, p.phone as vendor_phone, p.address as vendor_address
	FROM `purchase_order_list` po INNER JOIN `party_list` p ON po.vendor_id = p.id
	WHERE po.id = '{$id}' AND po.tenant_id = '{$tenant_id}'");
if(!$po || $po->num_rows == 0){ echo '<div class="alert alert-danger">Purchase order not found.</div>'; return; }
$po = $po->fetch_assoc();

$items = $conn->query("SELECT * FROM `purchase_order_items` WHERE purchase_order_id = '{$id}' ORDER BY id ASC");

$badge_class = 'secondary';
switch($po['status']){
	case 'sent': $badge_class = 'info'; break;
	case 'confirmed': $badge_class = 'primary'; break;
	case 'partially_received': $badge_class = 'warning'; break;
	case 'received': $badge_class = 'success'; break;
	case 'billed': $badge_class = 'primary'; break;
	case 'cancelled': $badge_class = 'dark'; break;
}

// Linked goods receipts
$grns = $conn->query("SELECT * FROM `goods_receipt_list` WHERE purchase_order_id = '{$id}' AND tenant_id = '{$tenant_id}' ORDER BY id DESC");
// Linked vendor bills
$bills = $conn->query("SELECT * FROM `vendor_bill_list` WHERE purchase_order_id = '{$id}' AND tenant_id = '{$tenant_id}' ORDER BY id DESC");
?>
<div class="card card-outline card-primary shadow-sm">
	<div class="card-header d-flex justify-content-between align-items-center">
		<h3 class="card-title font-weight-bold text-primary m-0"><i class="fas fa-shopping-bag"></i> Purchase Order <?php echo htmlspecialchars($po['po_no']) ?></h3>
		<div>
			<?php if(!in_array($po['status'], ['received','billed','cancelled'])): ?>
			<a href="./?page=purchases/receive&id=<?php echo $id ?>" class="btn btn-sm btn-success btn-flat"><i class="fas fa-truck-loading"></i> Receive Goods</a>
			<?php endif; ?>
			<?php if($po['status'] != 'cancelled'): ?>
			<a href="./?page=purchases/manage_bill&po_id=<?php echo $id ?>" class="btn btn-sm btn-primary bg-navy btn-flat"><i class="fas fa-file-invoice-dollar"></i> Create Bill</a>
			<?php endif; ?>
			<a href="./?page=purchases" class="btn btn-sm btn-default btn-flat"><i class="fas fa-arrow-left"></i> Back</a>
		</div>
	</div>
	<div class="card-body">
		<div class="row mb-3">
			<div class="col-md-6">
				<h6 class="text-muted font-weight-bold">VENDOR</h6>
				<p class="m-0 font-weight-bold"><?php echo htmlspecialchars($po['vendor_name']) ?> <small class="text-muted">(<?php echo htmlspecialchars($po['party_code']) ?>)</small></p>
				<p class="m-0 small"><?php echo htmlspecialchars($po['vendor_email'] ?? '') ?> <?php echo !empty($po['vendor_phone']) ? ' · ' . htmlspecialchars($po['vendor_phone']) : '' ?></p>
				<p class="m-0 small text-muted"><?php echo nl2br(htmlspecialchars($po['vendor_address'] ?? '')) ?></p>
			</div>
			<div class="col-md-6 text-md-right">
				<p class="m-0"><b>Status:</b> <span class="badge badge-<?php echo $badge_class ?> px-2 py-1"><?php echo strtoupper(str_replace('_',' ',$po['status'])) ?></span></p>
				<p class="m-0"><b>Order Date:</b> <?php echo date("d M Y", strtotime($po['order_date'])) ?></p>
				<?php if(!empty($po['expected_date'])): ?><p class="m-0"><b>Expected:</b> <?php echo date("d M Y", strtotime($po['expected_date'])) ?></p><?php endif; ?>
			</div>
		</div>

		<table class="table table-bordered table-sm">
			<thead class="bg-light">
				<tr>
					<th>Item</th>
					<th class="text-center">Ordered</th>
					<th class="text-center">Received</th>
					<th class="text-center">Billed</th>
					<th class="text-right">Unit Cost</th>
					<th class="text-center">Tax %</th>
					<th class="text-right">Amount</th>
				</tr>
			</thead>
			<tbody>
				<?php while($it = $items->fetch_assoc()): ?>
				<tr>
					<td><b><?php echo htmlspecialchars($it['item_name']) ?></b><?php echo !empty($it['description']) ? '<br><small class="text-muted">'.htmlspecialchars($it['description']).'</small>' : '' ?></td>
					<td class="text-center"><?php echo rtrim(rtrim(number_format((float)$it['qty'],2),'0'),'.') ?></td>
					<td class="text-center"><?php echo rtrim(rtrim(number_format((float)$it['qty_received'],2),'0'),'.') ?></td>
					<td class="text-center"><?php echo rtrim(rtrim(number_format((float)$it['qty_billed'],2),'0'),'.') ?></td>
					<td class="text-right">$<?php echo number_format((float)$it['unit_price'],2) ?></td>
					<td class="text-center"><?php echo rtrim(rtrim(number_format((float)$it['tax_rate'],2),'0'),'.') ?>%</td>
					<td class="text-right">$<?php echo number_format((float)$it['amount'],2) ?></td>
				</tr>
				<?php endwhile; ?>
			</tbody>
			<tfoot>
				<tr><th colspan="6" class="text-right">Subtotal</th><th class="text-right">$<?php echo number_format((float)$po['subtotal'],2) ?></th></tr>
				<tr><th colspan="6" class="text-right">Tax / VAT</th><th class="text-right">$<?php echo number_format((float)$po['tax_amount'],2) ?></th></tr>
				<tr><th colspan="6" class="text-right">Discount</th><th class="text-right">-$<?php echo number_format((float)$po['discount'],2) ?></th></tr>
				<tr class="bg-light"><th colspan="6" class="text-right">TOTAL (FJD)</th><th class="text-right text-primary">$<?php echo number_format((float)$po['total'],2) ?></th></tr>
			</tfoot>
		</table>

		<?php if(!empty($po['notes'])): ?>
		<div class="mt-2"><b>Notes:</b> <span class="text-muted"><?php echo nl2br(htmlspecialchars($po['notes'])) ?></span></div>
		<?php endif; ?>

		<div class="row mt-4">
			<div class="col-md-6">
				<h6 class="font-weight-bold text-navy"><i class="fas fa-truck-loading"></i> Goods Receipts</h6>
				<?php if($grns && $grns->num_rows > 0): ?>
				<ul class="list-group list-group-flush">
					<?php while($g = $grns->fetch_assoc()): ?>
					<li class="list-group-item px-0 py-1 d-flex justify-content-between">
						<span><b><?php echo htmlspecialchars($g['grn_no']) ?></b> — <?php echo date("d M Y", strtotime($g['receipt_date'])) ?></span>
						<span class="badge badge-success"><?php echo strtoupper($g['status']) ?></span>
					</li>
					<?php endwhile; ?>
				</ul>
				<?php else: ?><p class="text-muted small">No goods received yet.</p><?php endif; ?>
			</div>
			<div class="col-md-6">
				<h6 class="font-weight-bold text-navy"><i class="fas fa-file-invoice-dollar"></i> Vendor Bills</h6>
				<?php if($bills && $bills->num_rows > 0): ?>
				<ul class="list-group list-group-flush">
					<?php while($b = $bills->fetch_assoc()): ?>
					<li class="list-group-item px-0 py-1 d-flex justify-content-between">
						<a href="./?page=purchases/manage_bill&id=<?php echo $b['id'] ?>"><b><?php echo htmlspecialchars($b['bill_no']) ?></b></a>
						<span>$<?php echo number_format((float)$b['total'],2) ?> <span class="badge badge-light border"><?php echo strtoupper(str_replace('_',' ',$b['status'])) ?></span></span>
					</li>
					<?php endwhile; ?>
				</ul>
				<?php else: ?><p class="text-muted small">No bills recorded yet.</p><?php endif; ?>
			</div>
		</div>
	</div>
</div>
