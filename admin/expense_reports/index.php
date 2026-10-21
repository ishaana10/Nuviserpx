<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$where = "WHERE er.tenant_id = '{$tenant_id}'";
if(!empty($status) && $status != 'all'){
    $where .= " AND er.status = '{$conn->real_escape_string($status)}'";
}

$qry = $conn->query("
    SELECT er.*, e.firstname, e.lastname, e.employee_code
    FROM `expense_reports` er
    LEFT JOIN `employee_list` e ON er.employee_id = e.id
    {$where}
    ORDER BY er.id DESC
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i> Employee Expense Claims</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_new_expense"><i class="fas fa-plus mr-1"></i> Submit Expense Claim</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $status == 'all' ? 'active font-weight-bold' : '' ?>" href="./?page=expense_reports&status=all">All Claims</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'pending' ? 'active font-weight-bold' : '' ?>" href="./?page=expense_reports&status=pending">Pending Approval</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'approved' ? 'active font-weight-bold' : '' ?>" href="./?page=expense_reports&status=approved">Approved</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'reimbursed' ? 'active font-weight-bold' : '' ?>" href="./?page=expense_reports&status=reimbursed">Reimbursed</a>
            </li>
        </ul>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="expenses-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Claim No</th>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th class="text-right">Claim Amount</th>
                        <th>Receipt</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width: 12%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['claim_no']) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?> (<?= htmlspecialchars($row['employee_code']) ?>)</td>
                        <td class="align-middle"><?= date("d M Y", strtotime($row['claim_date'])) ?></td>
                        <td class="align-middle"><?= htmlspecialchars($row['category']) ?></td>
                        <td class="align-middle text-right font-weight-bold">$<?= number_format($row['amount'], 2) ?></td>
                        <td class="align-middle text-center">
                            <?php if(!empty($row['attachment_path'])): ?>
                                <a href="<?= base_url . $row['attachment_path'] ?>" target="_blank" class="btn btn-xs btn-outline-info"><i class="fas fa-paperclip mr-1"></i> Receipt</a>
                            <?php else: ?>
                                <span class="text-muted text-xs">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge badge-<?= $row['status'] == 'approved' || $row['status'] == 'reimbursed' ? 'success' : ($row['status'] == 'pending' ? 'warning' : 'danger') ?> px-2 py-1 text-uppercase">
                                <?= $row['status'] ?>
                            </span>
                        </td>
                        <td class="text-center align-middle">
                            <?php if($row['status'] == 'pending'): ?>
                                <button type="button" class="btn btn-xs btn-success btn-flat approve_claim" data-id="<?= $row['id'] ?>"><i class="fas fa-check mr-1"></i> Approve</button>
                            <?php else: ?>
                                <span class="text-muted text-xs">Processed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($qry->num_rows <= 0): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">No expense claims found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if ($.fn.DataTable.isDataTable('#expenses-table')) {
        $('#expenses-table').DataTable().destroy();
    }
    $('#expenses-table').dataTable();

    $(document).on('click', '#btn_new_expense', function(e){
        e.preventDefault();
        uni_modal("Submit Expense Claim", "expense_reports/manage_claim.php", "mid-large");
    });

    $(document).on('click', '.approve_claim', function(e){
        e.preventDefault();
        var claimId = $(this).data('id');
        if(confirm("Approve this employee expense claim?")){
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=approve_expense_claim",
                method: "POST",
                data: {id: claimId},
                dataType: "json",
                error: err => {
                    console.log(err);
                    alert_toast("An error occurred.", "error");
                    end_loader();
                },
                success: function(resp){
                    if(resp.status == 'success'){
                        location.reload();
                    } else {
                        alert_toast(resp.msg || "Failed to approve claim.", "error");
                        end_loader();
                    }
                }
            });
        }
    });
});
</script>
