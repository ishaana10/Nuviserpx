<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id <= 0){
    echo "<script>alert('Invalid Asset ID'); location.href='./?page=assets';</script>";
    exit;
}

$qry = $conn->query("SELECT * FROM `fixed_assets` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
if($qry->num_rows <= 0){
    echo "<script>alert('Asset not found'); location.href='./?page=assets';</script>";
    exit;
}

$res = $qry->fetch_assoc();
foreach($res as $k => $v){
    $$k = $v;
}
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold">
            <i class="fas fa-boxes text-primary mr-2"></i> Fixed Asset Details - <?= htmlspecialchars($asset_number) ?>
        </h3>
        <div>
            <a href="./?page=assets/manage_asset&id=<?= $id ?>" class="btn btn-sm btn-primary btn-flat"><i class="fas fa-edit mr-1"></i> Edit Asset</a>
            <a href="./?page=assets" class="btn btn-sm btn-default btn-flat ml-1"><i class="fas fa-arrow-left mr-1"></i> Back</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-8">
                <h4 class="font-weight-bold text-dark mb-1"><?= htmlspecialchars($asset_name) ?></h4>
                <p class="text-muted mb-2">Asset Code: <strong><?= htmlspecialchars($asset_number) ?></strong> | Type: <strong><?= htmlspecialchars($asset_type ?: 'N/A') ?></strong></p>
                <span class="badge badge-<?= $status == 'registered' ? 'success' : ($status == 'draft' ? 'secondary' : 'danger') ?> px-3 py-2">
                    <?= ucfirst($status) ?>
                </span>
            </div>
            <div class="col-md-4 text-right">
                <div class="p-3 bg-light rounded border">
                    <small class="text-uppercase text-muted font-weight-bold">Purchase Price</small>
                    <h2 class="font-weight-bold text-primary m-0">$<?= number_format($purchase_price, 2) ?></h2>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Details Column -->
            <div class="col-md-6">
                <div class="card card-secondary card-outline rounded-0 mb-3">
                    <div class="card-header"><h5 class="card-title font-weight-bold">Details</h5></div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <tr>
                                <th style="width: 40%;">Purchase Date</th>
                                <td><?= $purchase_date ? date("d M Y", strtotime($purchase_date)) : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <th>Warranty Expiry</th>
                                <td><?= $warranty_expiry ? date("d M Y", strtotime($warranty_expiry)) : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <th>Serial Number</th>
                                <td><?= htmlspecialchars($serial_number ?: 'N/A') ?></td>
                            </tr>
                            <tr>
                                <th>Attachment</th>
                                <td>
                                    <?php if(!empty($attachment_path)): ?>
                                        <a href="<?= base_url . $attachment_path ?>" target="_blank" class="btn btn-xs btn-outline-primary"><i class="fas fa-paperclip mr-1"></i> View File</a>
                                    <?php else: ?>
                                        <span class="text-muted">No attachment</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Description</th>
                                <td><?= nl2br(htmlspecialchars($description ?: 'No description provided.')) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Book Depreciation Column -->
            <div class="col-md-6">
                <div class="card card-info card-outline rounded-0 mb-3">
                    <div class="card-header"><h5 class="card-title font-weight-bold">Book Depreciation Settings</h5></div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <tr>
                                <th style="width: 45%;">Depreciation Start Date</th>
                                <td><?= $dep_start_date ? date("d M Y", strtotime($dep_start_date)) : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <th>Depreciation Method</th>
                                <td><?= htmlspecialchars($dep_method ?: 'None') ?></td>
                            </tr>
                            <tr>
                                <th>Averaging Method</th>
                                <td><?= htmlspecialchars($averaging_method ?: 'N/A') ?></td>
                            </tr>
                            <tr>
                                <th>Calculated Based On</th>
                                <td><?= $dep_calc_by == 'effective_life' ? 'Effective Life (' . $effective_life_years . ' yrs)' : 'Rate (' . $dep_rate . '%)' ?></td>
                            </tr>
                            <tr>
                                <th>Cost Limit</th>
                                <td>$<?= number_format($cost_limit, 2) ?></td>
                            </tr>
                            <tr>
                                <th>Residual Value</th>
                                <td>$<?= number_format($residual_value, 2) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
