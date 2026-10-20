<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$where = "WHERE tenant_id = '{$tenant_id}'";
if($status == 'draft'){
    $where .= " AND status = 'draft'";
}elseif($status == 'registered'){
    $where .= " AND status = 'registered'";
}elseif($status == 'disposed'){
    $where .= " AND status = 'disposed'";
}
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-boxes text-primary mr-2"></i> Fixed Assets</h3>
        <div class="card-tools">
            <a href="./?page=assets/manage_asset" class="btn btn-sm btn-primary btn-flat"><i class="fas fa-plus mr-1"></i> New Asset</a>
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $status == 'all' ? 'active font-weight-bold' : '' ?>" href="./?page=assets&status=all">All Assets</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'registered' ? 'active font-weight-bold' : '' ?>" href="./?page=assets&status=registered">Registered</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'draft' ? 'active font-weight-bold' : '' ?>" href="./?page=assets&status=draft">Drafts</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'disposed' ? 'active font-weight-bold' : '' ?>" href="./?page=assets&status=disposed">Disposed</a>
            </li>
        </ul>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="asset-list-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Asset Number</th>
                        <th>Asset Name</th>
                        <th>Asset Type</th>
                        <th>Purchase Date</th>
                        <th class="text-right">Purchase Price</th>
                        <th>Depreciation Method</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width: 10%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    $qry = $conn->query("SELECT * FROM `fixed_assets` {$where} ORDER BY id DESC");
                    while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['asset_number']) ?></td>
                        <td class="align-middle"><?= htmlspecialchars($row['asset_name']) ?></td>
                        <td class="align-middle"><?= htmlspecialchars($row['asset_type'] ?: 'N/A') ?></td>
                        <td class="align-middle"><?= $row['purchase_date'] ? date("d M Y", strtotime($row['purchase_date'])) : 'N/A' ?></td>
                        <td class="align-middle text-right font-weight-bold">$<?= number_format($row['purchase_price'], 2) ?></td>
                        <td class="align-middle"><?= htmlspecialchars($row['dep_method'] ?: 'None') ?></td>
                        <td class="text-center align-middle">
                            <?php if($row['status'] == 'registered'): ?>
                                <span class="badge badge-success px-2 py-1">Registered</span>
                            <?php elseif($row['status'] == 'draft'): ?>
                                <span class="badge badge-secondary px-2 py-1">Draft</span>
                            <?php else: ?>
                                <span class="badge badge-danger px-2 py-1">Disposed</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-flat btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
                                Action <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <div class="dropdown-menu" role="menu">
                                <a class="dropdown-menu-item dropdown-item" href="./?page=assets/view_asset&id=<?= $row['id'] ?>"><i class="fas fa-eye text-info mr-2"></i> View</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-menu-item dropdown-item" href="./?page=assets/manage_asset&id=<?= $row['id'] ?>"><i class="fas fa-edit text-primary mr-2"></i> Edit</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-menu-item dropdown-item delete_asset" href="javascript:void(0)" data-id="<?= $row['id'] ?>"><i class="fas fa-trash text-danger mr-2"></i> Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($qry->num_rows <= 0): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">No fixed assets found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#asset-list-table').dataTable();
    $('.delete_asset').click(function(){
        _conf("Are you sure to delete this fixed asset?", "delete_asset", [$(this).attr('data-id')])
    });
});

function delete_asset($id){
    start_loader();
    $.ajax({
        url: _base_url_+"classes/Master.php?f=delete_asset",
        method: "POST",
        data: {id: $id},
        dataType: "json",
        error: err => {
            console.log(err);
            alert_toast("An error occurred.", "error");
            end_loader();
        },
        success: resp => {
            if(typeof resp == 'object' && resp.status == 'success'){
                location.reload();
            } else {
                alert_toast("An error occurred.", "error");
                end_loader();
            }
        }
    });
}
</script>
