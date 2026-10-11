<?php
require_once('../../config.php');

if(!isset($_GET['id']) || !is_numeric($_GET['id'])){
    echo "<script>alert('Invalid Order ID'); location.replace('./?page=sales_orders');</script>";
    exit;
}

$tenant_id = $_settings->active_tenant_id();
$id = intval($_GET['id']);

$qry = $conn->query("
    SELECT s.*,
           p.party_code, p.name AS party_name, p.email AS party_email, p.phone AS party_phone, p.address AS party_address,
           u.firstname, u.lastname
    FROM `sales_order_list` s
    INNER JOIN `party_list` p ON p.id = s.party_id
    LEFT JOIN `users` u ON u.id = s.created_by
    WHERE s.id = '{$id}' AND s.tenant_id = '{$tenant_id}'
");

if($qry->num_rows <= 0){
    echo "<script>alert('Order not found'); location.replace('./?page=sales_orders');</script>";
    exit;
}

$order = $qry->fetch_assoc();

$items = $conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}' ORDER BY id ASC");

// System info
$sys = [];
$sys_qry = $conn->query("SELECT meta_field, meta_value FROM `system_info`");
while($row = $sys_qry->fetch_assoc()){
    $sys[$row['meta_field']] = $row['meta_value'];
}

$status_class = [
    'draft'                 => 'secondary',
    'proforma'              => 'info',
    'confirmed'             => 'primary',
    'converted_to_invoice'  => 'success',
    'cancelled'             => 'dark'
][$order['status']] ?? 'secondary';

$title_label = $order['type'] === 'proforma' ? 'PROFORMA INVOICE' : 'SALES ORDER';
?>

<style>
    @media print {
        .no-print { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .card-header, .card-footer { display: none !important; }
        body { background: #fff !important; }
    }
    .order-title { font-size: 1.8rem; font-weight: 700; color: #1e3a8a; }
</style>

<div class="card card-outline card-primary">
    <div class="card-header no-print">
        <h3 class="card-title">
            <?= $title_label ?> <strong>#<?= htmlspecialchars($order['order_no']) ?></strong>
        </h3>
        <div class="card-tools">
            <?php if($order['status'] == 'proforma' || $order['status'] == 'draft'): ?>
            <button onclick="confirm_order(<?= $order['id'] ?>)" class="btn btn-sm btn-success">
                <i class="fa fa-check-circle"></i> Confirm Sale & Auto Stock Adjust
            </button>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-sm btn-secondary">
                <i class="fa fa-print"></i> Print Document
            </button>
            <a href="./?page=sales_orders" class="btn btn-sm btn-default">
                <i class="fa fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div class="card-body p-4" id="print-area">
        <!-- Header -->
        <div class="row border-bottom pb-3 mb-4">
            <div class="col-md-6">
                <h3 class="font-weight-bold text-navy mb-1"><?= htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?></h3>
                <p class="mb-0 text-muted small"><?= htmlspecialchars($sys['address'] ?? 'Fiji Islands') ?></p>
                <p class="mb-0 text-muted small">
                    <b>Email:</b> <?= htmlspecialchars($sys['email'] ?? '') ?> | <b>Phone:</b> <?= htmlspecialchars($sys['contact'] ?? '') ?>
                </p>
            </div>
            <div class="col-md-6 text-right">
                <div class="order-title"><?= $title_label ?></div>
                <h4 class="font-weight-bold mb-1">#<?= htmlspecialchars($order['order_no']) ?></h4>
                <p class="mb-1 text-muted small"><b>Order Date:</b> <?= date('d M Y', strtotime($order['order_date'])) ?></p>
                <span class="badge badge-<?= $status_class ?> px-3 py-1 font-weight-normal mt-1">
                    <?= strtoupper(str_replace('_', ' ', $order['status'])) ?>
                </span>
                <?php if($order['stock_adjusted']): ?>
                    <span class="badge badge-success px-2 py-1 font-weight-normal mt-1"><i class="fas fa-cubes"></i> Stock Adjusted</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Customer Info -->
        <div class="row mb-4 bg-light p-3 rounded">
            <div class="col-md-6">
                <h6 class="text-uppercase font-weight-bold text-navy mb-2">Customer Details:</h6>
                <h5 class="font-weight-bold mb-1"><?= htmlspecialchars($order['party_name']) ?></h5>
                <p class="mb-1 text-muted small"><b>Code:</b> <?= htmlspecialchars($order['party_code']) ?></p>
                <?php if($order['party_email']): ?>
                    <p class="mb-1 text-muted small"><b>Email:</b> <?= htmlspecialchars($order['party_email']) ?></p>
                <?php endif; ?>
                <?php if($order['party_phone']): ?>
                    <p class="mb-1 text-muted small"><b>Phone:</b> <?= htmlspecialchars($order['party_phone']) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-right">
                <h6 class="text-uppercase font-weight-bold text-navy mb-2">Delivery Address:</h6>
                <p class="mb-0 text-muted small"><?= nl2br(htmlspecialchars($order['party_address'] ?? 'N/A')) ?></p>
            </div>
        </div>

        <!-- Items Table -->
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-striped table-sm">
                <thead class="bg-navy text-white">
                    <tr>
                        <th>#</th>
                        <th>Item Description</th>
                        <th class="text-center" width="80">Qty</th>
                        <th class="text-right" width="120">Unit Price</th>
                        <th class="text-center" width="90">Tax Rate</th>
                        <th class="text-right" width="130">Total (FJD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while($row = $items->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                            <?php if($row['description']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($row['description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($row['qty'], 2) ?></td>
                        <td class="text-right">$<?= number_format($row['unit_price'], 2) ?></td>
                        <td class="text-center"><?= number_format($row['tax_rate'], 1) ?>%</td>
                        <td class="text-right font-weight-bold">$<?= number_format($row['amount'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals Summary -->
        <div class="row">
            <div class="col-md-7">
                <?php if(!empty($order['notes'])): ?>
                <div class="border p-3 rounded bg-light">
                    <h6 class="font-weight-bold text-navy mb-1"><i class="fas fa-sticky-note"></i> Terms & Delivery Notes:</h6>
                    <p class="mb-0 small text-muted"><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-5">
                <div class="card border shadow-none bg-light p-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Subtotal:</span>
                        <strong class="text-dark">$<?= number_format($order['subtotal'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Tax / VAT Amount:</span>
                        <strong class="text-dark">$<?= number_format($order['tax_amount'], 2) ?></strong>
                    </div>
                    <?php if($order['discount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Discount:</span>
                        <strong class="text-danger">-$<?= number_format($order['discount'], 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between">
                        <span class="font-weight-bold h5 m-0">Total Amount:</span>
                        <strong class="font-weight-bold h4 text-primary m-0">$<?= number_format($order['total'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Signatures -->
        <div class="row mt-5 pt-4">
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Customer Acceptance</p>
            </div>
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Sales Representative</p>
            </div>
        </div>
    </div>

    <div class="card-footer text-center no-print">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa fa-print"></i> Print Document
        </button>
        <a href="./?page=sales_orders" class="btn btn-default">
            <i class="fa fa-arrow-left"></i> Back to Orders
        </a>
    </div>
</div>

<script>
	function confirm_order($id){
		if(confirm("Confirm this sale? Stock quantity will be automatically deducted and a Sales Invoice raised.")){
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=confirm_sales_order",
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
	}
</script>