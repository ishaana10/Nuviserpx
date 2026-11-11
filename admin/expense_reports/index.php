<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\ExpenseClaimService;

$tenant_id = (int)$_settings->active_tenant_id();
$scope = $_settings->employee_scope('expenses');       // null = can manage claims, otherwise own claims only
$isMgr = $scope === null;
$tag = $_settings->currency_tag();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$status = in_array($_GET['status'] ?? 'all', ['all', 'pending', 'approved', 'reimbursed', 'rejected'], true) ? $_GET['status'] : 'all';
$sql = "SELECT er.*, e.firstname, e.lastname, e.employee_code FROM `expense_reports` er LEFT JOIN `employee_list` e ON er.employee_id = e.id WHERE er.tenant_id = ?";
$types = 'i'; $args = [$tenant_id];
if (!$isMgr) { $sql .= " AND er.employee_id = ?"; $types .= 'i'; $args[] = (int)$scope; }
if ($status === 'rejected') { $sql .= " AND er.status IN ('rejected','cancelled','voided')"; }
elseif ($status !== 'all') { $sql .= " AND er.status = ?"; $types .= 's'; $args[] = $status; }
$sql .= " ORDER BY er.id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$args);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$owed = 0.0;
foreach ($rows as $r) { if ($r['status'] === 'approved') { $owed += (float)$r['amount']; } }
$tabs = ['all' => 'All Claims', 'pending' => 'Pending Approval', 'approved' => 'Approved (to pay)', 'reimbursed' => 'Reimbursed', 'rejected' => 'Rejected / Closed'];
$badge = ['pending' => 'warning', 'approved' => 'info', 'reimbursed' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary', 'voided' => 'secondary'];
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i> Employee Expense Claims</h3>
        <div class="card-tools">
            <?php if ($isMgr): ?>
            <button type="button" class="btn btn-sm btn-default btn-flat" onclick="uni_modal('Expense categories', 'expense_reports/categories.php')"><i class="fas fa-tags mr-1"></i> Categories</button>
            <button type="button" class="btn btn-sm btn-success btn-flat" id="btn_reimburse" disabled><i class="fas fa-money-check-alt mr-1"></i> Reimburse selected</button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-primary btn-flat" onclick="uni_modal('Submit Expense Claim', 'expense_reports/manage_claim.php', 'mid-large')"><i class="fas fa-plus mr-1"></i> Submit Expense Claim</button>
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3">
            <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $status === $k ? 'active font-weight-bold' : '' ?>" href="./?page=expense_reports&status=<?= $h($k) ?>"><?= $h($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($isMgr && $owed > 0): ?>
        <div class="alert alert-info py-2 text-sm">Approved and waiting to be paid back: <b><?= $h($tag) ?><?= number_format($owed, 2) ?></b></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="expenses-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <?php if ($isMgr): ?><th style="width:3%"></th><?php endif; ?>
                        <th>Claim No</th><th>Employee</th><th>Date</th><th>Category</th>
                        <th class="text-right">Amount<?= $h($tag) ?></th><th>Receipt</th><th class="text-center">Status</th><th class="text-center" style="width:16%">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): $st = $row['status']; ?>
                    <tr>
                        <?php if ($isMgr): ?>
                        <td class="text-center align-middle"><?php if ($st === 'approved'): ?><input type="checkbox" class="pick" value="<?= (int)$row['id'] ?>" data-amount="<?= $h($row['amount']) ?>"><?php endif; ?></td>
                        <?php endif; ?>
                        <td class="align-middle font-weight-bold text-primary"><?= $h($row['claim_no']) ?></td>
                        <td class="align-middle"><?= $h($row['firstname'] . ' ' . $row['lastname']) ?> <small class="text-muted">(<?= $h($row['employee_code']) ?>)</small></td>
                        <td class="align-middle"><?= $h(date('d M Y', strtotime($row['claim_date']))) ?></td>
                        <td class="align-middle"><?= $h($row['category']) ?></td>
                        <td class="align-middle text-right font-weight-bold"><?= number_format((float)$row['amount'], 2) ?></td>
                        <td class="align-middle text-center">
                            <?php if (!empty($row['attachment_path'])): ?>
                                <a href="<?= $h(base_url . $row['attachment_path']) ?>" target="_blank" rel="noopener" class="btn btn-xs btn-outline-info"><i class="fas fa-paperclip mr-1"></i> Receipt</a>
                            <?php else: ?><span class="text-muted text-xs">None</span><?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge badge-<?= $badge[$st] ?? 'secondary' ?> px-2 py-1 text-uppercase"><?= $h($st) ?></span>
                            <?php if (!empty($row['approval_note']) && in_array($st, ['rejected', 'voided'], true)): ?><small class="d-block text-muted"><?= $h($row['approval_note']) ?></small><?php endif; ?>
                            <?php if ($st === 'reimbursed' && !empty($row['payment_date'])): ?><small class="d-block text-muted">paid <?= $h(date('d M Y', strtotime($row['payment_date']))) ?></small><?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if ($st === 'pending'): ?>
                                <?php if ($isMgr): ?>
                                <button type="button" class="btn btn-xs btn-success btn-flat approve_claim" data-id="<?= (int)$row['id'] ?>"><i class="fas fa-check"></i> Approve</button>
                                <button type="button" class="btn btn-xs btn-outline-danger btn-flat reject_claim" data-id="<?= (int)$row['id'] ?>">Reject</button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-xs btn-outline-secondary btn-flat cancel_claim" data-id="<?= (int)$row['id'] ?>">Withdraw</button>
                            <?php elseif ($st === 'approved' && $isMgr): ?>
                                <button type="button" class="btn btn-xs btn-outline-secondary btn-flat void_claim" data-id="<?= (int)$row['id'] ?>">Void</button>
                            <?php elseif ($st === 'reimbursed' && $isMgr): ?>
                                <button type="button" class="btn btn-xs btn-outline-warning btn-flat reverse_claim" data-id="<?= (int)$row['id'] ?>">Reverse payment</button>
                            <?php else: ?><span class="text-muted text-xs">&mdash;</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $isMgr ? 9 : 8 ?>" class="text-center py-4 text-muted">No expense claims found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if ($.fn.DataTable.isDataTable('#expenses-table')) { $('#expenses-table').DataTable().destroy(); }
    $('#expenses-table').dataTable({ columnDefs: [{ orderable: false, targets: [<?= $isMgr ? '0,6,8' : '5,7' ?>] }] });

    function act(f, data){
        start_loader();
        $.ajax({ url: _base_url_ + "classes/Master.php?f=" + f, method: "POST", data: data, dataType: "json",
            error: function(){ alert_toast("An error occurred.", "error"); end_loader(); },
            success: function(resp){
                if (resp.status == 'success') { alert_toast(resp.msg, 'success'); setTimeout(function(){ location.reload(); }, 700); }
                else { alert_toast(resp.msg || "That did not work.", "error"); end_loader(); }
            }
        });
    }
    $(document).on('click', '.approve_claim', function(){ uni_modal('Approve expense claim', 'expense_reports/approve_claim.php?id=' + $(this).data('id')); });
    $(document).on('click', '.reject_claim', function(){
        var r = prompt('Why is this claim being rejected? The employee will see this.');
        if (r !== null) act('reject_expense_claim', {id: $(this).data('id'), reason: r});
    });
    $(document).on('click', '.cancel_claim', function(){ if (confirm('Withdraw this claim?')) act('cancel_expense_claim', {id: $(this).data('id')}); });
    $(document).on('click', '.void_claim', function(){
        var r = prompt('Void this approved claim and reverse its posting. Reason:');
        if (r !== null) act('void_expense_claim', {id: $(this).data('id'), reason: r});
    });
    $(document).on('click', '.reverse_claim', function(){ if (confirm('Reverse the payment? Every claim paid together with this one becomes owed again.')) act('reverse_expense_reimbursement', {id: $(this).data('id')}); });

    function picked(){ return $('.pick:checked').map(function(){ return $(this).val(); }).get(); }
    $(document).on('change', '.pick', function(){ $('#btn_reimburse').prop('disabled', picked().length === 0); });
    $('#btn_reimburse').click(function(){ uni_modal('Reimburse claims', 'expense_reports/reimburse.php?ids=' + picked().join(',')); });
});
</script>
