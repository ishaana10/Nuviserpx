<?php
$tenant_id = $_settings->active_tenant_id();
$__svc = new \App\Services\PayrollLoanService($conn, $_settings);
$__loans = $__svc->all();
$__emps = $conn->query("SELECT id, employee_code, concat(firstname, ' ', lastname) name FROM `employee_list` WHERE tenant_id = '{$tenant_id}' AND status = 1 AND delete_flag = 0 ORDER BY firstname");
$__banks = $conn->query("SELECT id, name FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 AND type = 'Bank' ORDER BY name");
$__cur = htmlspecialchars($_settings->base_currency());
$can_pay = $_settings->can_access('payroll_pay');
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-hand-holding-usd text-primary mr-2"></i> Staff Loans &amp; Advances</h3></div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm">
            Paying out a loan or advance posts <b>Dr Staff Loans Receivable, Cr Bank</b>. Each payroll then recovers the instalment from net pay (never more than the balance, and never more than leaves the employee with net pay), and the payroll journal credits Staff Loans Receivable. Voiding a payroll puts its instalment back on the loan.
        </div>
        <?php if($can_pay): ?>
        <form id="loan-form" class="form-row align-items-end mb-3">
            <div class="col-md-3 form-group"><label class="text-sm font-weight-bold">Employee</label>
                <select name="employee_id" class="form-control form-control-sm" required><option value="">Select...</option>
                    <?php while($__emps && $e = $__emps->fetch_assoc()): ?><option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['employee_code'] . ' - ' . $e['name']) ?></option><?php endwhile; ?>
                </select></div>
            <div class="col-md-2 form-group"><label class="text-sm font-weight-bold">Type</label>
                <select name="kind" class="form-control form-control-sm"><option value="loan">Loan</option><option value="advance">Salary advance</option></select></div>
            <div class="col-md-3 form-group"><label class="text-sm font-weight-bold">Description</label><input type="text" name="description" maxlength="200" class="form-control form-control-sm" placeholder="e.g. School fees"></div>
            <div class="col-md-2 form-group"><label class="text-sm font-weight-bold">Amount (<?= $__cur ?>)</label><input type="number" step="0.01" min="0.01" name="principal" class="form-control form-control-sm text-right" required></div>
            <div class="col-md-2 form-group"><label class="text-sm font-weight-bold">Recover per payroll</label><input type="number" step="0.01" min="0.01" name="installment" class="form-control form-control-sm text-right" required></div>
            <div class="col-md-2 form-group"><label class="text-sm font-weight-bold">Paid out on</label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-3 form-group"><label class="text-sm font-weight-bold">Paid from bank account</label>
                <select name="bank_account_id" class="form-control form-control-sm" required><option value="">Select...</option>
                    <?php while($__banks && $b = $__banks->fetch_assoc()): ?><option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option><?php endwhile; ?>
                </select></div>
            <div class="col-md-2 form-group"><button type="submit" class="btn btn-sm btn-primary btn-flat"><i class="fas fa-plus mr-1"></i> Pay out loan</button></div>
        </form>
        <?php endif; ?>
        <table class="table table-sm table-bordered table-striped">
            <thead><tr><th>#</th><th>Employee</th><th>Type</th><th>Description</th><th class="text-right">Amount</th><th class="text-right">Per payroll</th><th>From</th><th class="text-right">Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach($__loans as $l): ?>
                <tr>
                    <td><?= (int)$l['id'] ?></td>
                    <td><?= htmlspecialchars($l['employee_code'] . ' - ' . $l['emp_name']) ?></td>
                    <td><?= $l['kind'] === 'advance' ? 'Advance' : 'Loan' ?></td>
                    <td><?= htmlspecialchars((string)$l['description']) ?></td>
                    <td class="text-right"><?= number_format((float)$l['principal'], 2) ?></td>
                    <td class="text-right"><?= number_format((float)$l['installment'], 2) ?></td>
                    <td><?= htmlspecialchars($l['start_date']) ?></td>
                    <td class="text-right font-weight-bold"><?= number_format((float)$l['balance'], 2) ?></td>
                    <td><span class="badge badge-<?= $l['status'] === 'active' ? 'warning' : ($l['status'] === 'paid' ? 'success' : 'secondary') ?>"><?= htmlspecialchars(ucfirst($l['status'])) ?></span></td>
                    <td class="text-center"><?php if($can_pay && $l['status'] === 'active'): ?><button type="button" class="btn btn-xs btn-outline-danger btn-flat cancel_loan" data-id="<?= (int)$l['id'] ?>" title="Only possible before any recovery">Cancel</button><?php endif; ?></td>
                </tr>
            <?php endforeach; if(!$__loans): ?><tr><td colspan="10" class="text-center text-muted py-3">No loans or advances yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$(document).ready(function(){
    function post(fn, data){
        start_loader();
        $.ajax({url: _base_url_ + "classes/Master.php?f=" + fn, method: "POST", data: data, dataType: "json",
            error: function(){ alert_toast("An error occurred.", "error"); end_loader(); },
            success: function(resp){ if(resp.status == 'success'){ location.reload(); } else { alert_toast(resp.msg || "Failed.", "error"); end_loader(); } }});
    }
    $('#loan-form').submit(function(e){ e.preventDefault(); post('issue_staff_loan', $(this).serialize()); });
    $('.cancel_loan').click(function(){ var id = $(this).data('id'); _conf("Cancel this loan? Its payout is reversed.", "cancel_loan_do", [id]); });
    window.cancel_loan_do = function(id){ post('cancel_staff_loan', {id: id}); };
});
</script>
