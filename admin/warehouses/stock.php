<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\WarehouseStockService;

$tenant_id = $_settings->active_tenant_id();
$svc = new WarehouseStockService($conn, $_settings);
$wh_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$wh_name = $svc->warehouseName($wh_id);

// Products held at this location (non-zero), valued at cost.
$rows = [];
$stmt = $conn->prepare("SELECT s.quantity, p.id, p.product_code, p.name, p.unit, p.cost_price
    FROM product_stock s INNER JOIN product_list p ON p.id = s.product_id
    WHERE s.tenant_id = ? AND s.warehouse_id = ? AND p.delete_flag = 0 AND s.quantity <> 0
    ORDER BY p.name");
if ($stmt) {
    $stmt->bind_param('ii', $tenant_id, $wh_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
$total_units = 0.0; $total_val = 0.0;
foreach ($rows as $r) { $total_units += (float)$r['quantity']; $total_val += (float)$r['quantity'] * (float)$r['cost_price']; }
$qfmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title font-weight-bold"><i class="fas fa-warehouse text-primary mr-2"></i> Stock at <?= htmlspecialchars($wh_name) ?></h3>
        <a href="./?page=warehouses" class="btn btn-sm btn-default btn-flat"><i class="fas fa-arrow-left"></i> Warehouses</a>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4"><div class="info-box bg-light border"><span class="info-box-icon bg-info"><i class="fas fa-boxes"></i></span><div class="info-box-content"><span class="info-box-text text-muted font-weight-bold">Products</span><span class="info-box-number h5 mb-0"><?= count($rows) ?></span></div></div></div>
            <div class="col-md-4"><div class="info-box bg-light border"><span class="info-box-icon bg-primary"><i class="fas fa-cubes"></i></span><div class="info-box-content"><span class="info-box-text text-muted font-weight-bold">Total units</span><span class="info-box-number h5 mb-0"><?= $qfmt($total_units) ?></span></div></div></div>
            <div class="col-md-4"><div class="info-box bg-light border"><span class="info-box-icon bg-success"><i class="fas fa-dollar-sign"></i></span><div class="info-box-content"><span class="info-box-text text-muted font-weight-bold">Value at cost</span><span class="info-box-number h5 mb-0">$<?= number_format($total_val, 2) ?></span></div></div></div>
        </div>

        <?php if (empty($rows)): ?>
            <p class="text-muted text-center py-4">No stock held at this location.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead><tr><th>Product</th><th class="text-right">On hand</th><th class="text-right">Unit cost</th><th class="text-right">Value</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="./?page=stock_adjustments&product_id=<?= (int)$r['id'] ?>"><b><?= htmlspecialchars($r['product_code']) ?></b></a> — <?= htmlspecialchars($r['name']) ?></td>
                        <td class="text-right"><?= $qfmt($r['quantity']) ?> <span class="text-muted text-xs"><?= htmlspecialchars((string)$r['unit']) ?></span></td>
                        <td class="text-right">$<?= number_format((float)$r['cost_price'], 2) ?></td>
                        <td class="text-right font-weight-bold">$<?= number_format((float)$r['quantity'] * (float)$r['cost_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="bg-light"><th>Total</th><th class="text-right"><?= $qfmt($total_units) ?></th><th></th><th class="text-right">$<?= number_format($total_val, 2) ?></th></tr></tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
