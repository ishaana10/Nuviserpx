<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$qry = $conn->query("
    SELECT c.*, p.name as party_name
    FROM `contracts` c
    LEFT JOIN `party_list` p ON c.party_id = p.id
    WHERE c.tenant_id = '{$tenant_id}'
    ORDER BY c.id DESC
");

$total_mrr = 0;
$active_contracts_count = 0;

$contracts_list = [];
while($row = $qry->fetch_assoc()){
    $contracts_list[] = $row;
    if($row['status'] == 'active'){
        $active_contracts_count++;
        if($row['billing_frequency'] == 'annual'){
            $total_mrr += (floatval($row['amount']) / 12);
        } elseif($row['billing_frequency'] == 'quarterly'){
            $total_mrr += (floatval($row['amount']) / 3);
        } else {
            $total_mrr += floatval($row['amount']);
        }
    }
}
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-file-contract text-primary mr-2"></i> Contracts & Subscriptions</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_new_contract" onclick="uni_modal('New Service Contract', 'contracts/manage_contract.php', 'mid-large')"><i class="fas fa-plus mr-1"></i> New Contract</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Summary Info Boxes -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-sync-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Estimated Monthly Recurring Revenue (MRR)</span>
                        <span class="info-box-number text-success h4 mb-0">$<?= number_format($total_mrr, 2) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-info elevation-1"><i class="fas fa-file-signature"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Active Subscriptions</span>
                        <span class="info-box-number text-navy h4 mb-0"><?= $active_contracts_count ?> Contracts</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="contracts-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Contract No</th>
                        <th>Title / Service</th>
                        <th>Client / Customer</th>
                        <th>Billing Cycle</th>
                        <th class="text-right">Recurring Rate</th>
                        <th>Start / End Date</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width: 10%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    foreach($contracts_list as $row):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['contract_no']) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['title']) ?></td>
                        <td class="align-middle"><?= htmlspecialchars($row['party_name']) ?></td>
                        <td class="align-middle text-capitalize"><?= htmlspecialchars($row['billing_frequency']) ?></td>
                        <td class="align-middle text-right font-weight-bold">$<?= number_format($row['amount'], 2) ?></td>
                        <td class="align-middle text-xs">
                            <?= date("d M Y", strtotime($row['start_date'])) ?>
                            <?= $row['end_date'] ? ' to ' . date("d M Y", strtotime($row['end_date'])) : ' (Ongoing)' ?>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge badge-<?= $row['status'] == 'active' ? 'success' : 'secondary' ?> px-2 py-1 text-uppercase">
                                <?= $row['status'] ?>
                            </span>
                        </td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-xs btn-outline-primary btn-flat edit_contract" data-id="<?= $row['id'] ?>" onclick="uni_modal('Edit Contract Details', 'contracts/manage_contract.php?id=<?= $row['id'] ?>', 'mid-large')"><i class="fas fa-edit mr-1"></i> Edit</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($contracts_list)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">No contracts or subscriptions found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if ($.fn.DataTable.isDataTable('#contracts-table')) {
        $('#contracts-table').DataTable().destroy();
    }
    $('#contracts-table').dataTable();

});
</script>
