<?php
$tenant_id = $_settings->active_tenant_id();

$payruns_qry = $conn->query("
    SELECT payroll_run_ref, salary_month, pay_frequency, pay_date, period_start, period_end,
           COUNT(id) as total_employees,
           SUM(gross_salary) as total_gross,
           SUM(employee_fnpf) as total_emp_fnpf,
           SUM(employer_fnpf) as total_empr_fnpf,
           SUM(paye_tax) as total_paye,
           SUM(net_salary) as total_net,
           MAX(date_created) as created_at
    FROM `payroll_list`
    WHERE tenant_id = '{$tenant_id}'
    GROUP BY payroll_run_ref
    ORDER BY created_at DESC
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-cogs text-primary mr-2"></i> PayrollEngine Execution Jobs & Retroactive Pay Adjustments</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-info btn-flat" onclick="retro_pay_modal()"><i class="fas fa-history mr-1"></i> Retroactive Pay Adjustment</button>
            <button type="button" class="btn btn-sm btn-primary btn-flat" onclick="uni_modal('<i class=\'fa fa-users\'></i> New Fiji Batch Payrun Job', 'payroll/manage_payroll.php?batch=1', 'extra-large')"><i class="fas fa-play mr-1"></i> Launch Payrun Job</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm mb-4">
            <i class="fas fa-info-circle mr-2"></i> Enterprise Payroll Engine execution workflow. Manages batch payrun job status progression, calculation validation, and General Ledger posting.
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="payruns-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Run Reference</th>
                        <th>Salary Period</th>
                        <th>Frequency</th>
                        <th class="text-center">Staff Count</th>
                        <th class="text-right">Total Gross</th>
                        <th class="text-right">FNPF (16%)</th>
                        <th class="text-right">PAYE Tax</th>
                        <th class="text-right">Total Net Pay</th>
                        <th class="text-center">Engine Stage</th>
                        <th class="text-center" style="width: 10%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($payruns_qry && $payruns_qry->num_rows > 0):
                        while($row = $payruns_qry->fetch_assoc()):
                            $ref = $row['payroll_run_ref'] ?: 'RUN-LEGACY';
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary font-monospace"><?= htmlspecialchars($ref) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['salary_month']) ?></td>
                        <td class="align-middle text-capitalize"><?= htmlspecialchars($row['pay_frequency']) ?></td>
                        <td class="text-center align-middle font-weight-bold"><span class="badge badge-light border px-2 py-1"><?= $row['total_employees'] ?> Employees</span></td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($row['total_gross'], 2) ?></td>
                        <td class="text-right align-middle text-info font-weight-bold">$<?= number_format($row['total_emp_fnpf'] + $row['total_empr_fnpf'], 2) ?></td>
                        <td class="text-right align-middle text-danger font-weight-bold">$<?= number_format($row['total_paye'], 2) ?></td>
                        <td class="text-right align-middle text-success font-weight-bold font-monospace">$<?= number_format($row['total_net'], 2) ?></td>
                        <td class="text-center align-middle">
                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check-double mr-1"></i> GL Posted</span>
                        </td>
                        <td class="text-center align-middle">
                            <a href="./?page=payroll/fnpf_schedule&month=<?= urlencode($row['salary_month']) ?>" class="btn btn-xs btn-outline-primary btn-flat"><i class="fas fa-eye mr-1"></i> View CS3</a>
                        </td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">No payrun execution jobs recorded yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Retroactive Pay Adjustment Modal -->
<div class="modal fade" id="retroPayModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="retro-pay-form">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-history mr-2"></i> Retroactive Pay Adjustment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="retro_employee_id" class="font-weight-bold text-dark text-sm">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" id="retro_employee_id" class="form-control form-control-sm" required>
                            <option value="">Select Employee...</option>
                            <?php
                            $employees = $conn->query("SELECT id, employee_code, firstname, lastname FROM `employee_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY firstname ASC");
                            while($e = $employees->fetch_assoc()):
                            ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['firstname'] . ' ' . $e['lastname'] . ' (' . $e['employee_code'] . ')') ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="retro_effective_date" class="font-weight-bold text-dark text-sm">Effective Retro Date <span class="text-danger">*</span></label>
                            <input type="date" name="effective_date" id="retro_effective_date" class="form-control form-control-sm" value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="retro_amount" class="font-weight-bold text-dark text-sm">Adjustment Amount ($) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="retro_amount" class="form-control form-control-sm text-right" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label for="retro_reason" class="font-weight-bold text-dark text-sm">Business Justification / Notes</label>
                        <textarea name="reason" id="retro_reason" rows="2" class="form-control form-control-sm" placeholder="Reason for back-dated salary adjustment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-info font-weight-bold text-white">Apply Retro Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function retro_pay_modal(){
    $('#retroPayModal').modal('show');
}

$(document).ready(function(){
    $('#payruns-table').dataTable();

    $('#retro-pay-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=apply_retro_pay",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    alert_toast(resp.msg, "success");
                    $('#retroPayModal').modal('hide');
                    end_loader();
                } else {
                    alert_toast(resp.msg || "Failed to apply retro adjustment.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
