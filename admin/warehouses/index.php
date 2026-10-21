<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$warehouses = $conn->query("SELECT * FROM `warehouses` WHERE tenant_id = '{$tenant_id}' ORDER BY name ASC");

$transfers = $conn->query("
    SELECT wt.*, fw.name as from_wh, tw.name as to_wh, p.name as product_name, p.product_code
    FROM `warehouse_transfers` wt
    LEFT JOIN `warehouses` fw ON wt.from_warehouse_id = fw.id
    LEFT JOIN `warehouses` tw ON wt.to_warehouse_id = tw.id
    LEFT JOIN `product_list` p ON wt.product_id = p.id
    WHERE wt.tenant_id = '{$tenant_id}'
    ORDER BY wt.id DESC
    LIMIT 10
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-warehouse text-primary mr-2"></i> Multi-Warehouse & Stock Transfers</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-primary btn-flat mr-2" id="btn_new_warehouse" onclick="uni_modal('Add Warehouse Location', 'warehouses/manage_warehouse.php', 'mid-large')"><i class="fas fa-plus mr-1"></i> Add Warehouse</button>
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_transfer_stock" onclick="uni_modal('Inter-Warehouse Stock Transfer', 'warehouses/transfer.php', 'mid-large')"><i class="fas fa-exchange-alt mr-1"></i> Transfer Stock</button>
        </div>
    </div>
    <div class="card-body">
        <h5 class="font-weight-bold text-dark mb-3"><i class="fas fa-building text-secondary mr-1"></i> Physical Warehouses & Locations</h5>
        <div class="row mb-4">
            <?php while($wh = $warehouses->fetch_assoc()): ?>
                <div class="col-md-4">
                    <div class="card border shadow-none">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h5 class="font-weight-bold text-dark m-0"><i class="fas fa-warehouse text-primary mr-2"></i> <?= htmlspecialchars($wh['name']) ?></h5>
                                <span class="badge badge-<?= $wh['status'] == 1 ? 'success' : 'secondary' ?>"><?= $wh['status'] == 1 ? 'Active' : 'Inactive' ?></span>
                            </div>
                            <p class="text-muted text-xs mb-0"><i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars($wh['location'] ?: 'Primary Facility') ?></p>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
            <?php if($warehouses->num_rows <= 0): ?>
                <div class="col-md-12 text-muted py-3">No custom warehouses configured yet. Main warehouse is active.</div>
            <?php endif; ?>
        </div>

        <h5 class="font-weight-bold text-dark border-top pt-3 mb-3"><i class="fas fa-history text-secondary mr-1"></i> Recent Inter-Warehouse Stock Transfers</h5>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Product Code & Name</th>
                        <th>From Warehouse</th>
                        <th>To Warehouse</th>
                        <th class="text-right">Qty Transferred</th>
                        <th>Transfer Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while($t = $transfers->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle">
                            <strong class="text-primary"><?= htmlspecialchars($t['product_code']) ?></strong> - <?= htmlspecialchars($t['product_name']) ?>
                        </td>
                        <td class="align-middle"><?= htmlspecialchars($t['from_wh'] ?: 'Main Warehouse') ?></td>
                        <td class="align-middle font-weight-bold text-success"><?= htmlspecialchars($t['to_wh'] ?: 'Main Warehouse') ?></td>
                        <td class="align-middle text-right font-weight-bold"><?= number_format($t['qty'], 2) ?></td>
                        <td class="align-middle text-xs"><?= date("d M Y H:i", strtotime($t['date_created'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($transfers->num_rows <= 0): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No stock transfers recorded.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
