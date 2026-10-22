<?php
$tenant_id = $_settings->active_tenant_id();

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

$qry = $conn->query("
    SELECT p.*, e.firstname, e.lastname, e.employee_code, e.tin
    FROM `payroll_list` p
    LEFT JOIN `employee_list` e ON p.employee_id = e.id
    WHERE p.tenant_id = '{$tenant_id}' AND YEAR(p.pay_date) = '{$year}'
    ORDER BY p.pay_date DESC
");

$tot_family_care = 0;
$tot_paternity = 0;
$tot_fnpf_vol = 0;
$tot_rebate_base = 0;
$tot_rebate_claim = 0;
?>

<div class="card card-outline card-success shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-hand-holding-usd text-success mr-2"></i> FRCS 150% Corporate Income Tax Rebate Summary Report</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Rebate Summary</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-success border-success text-sm mb-4">
            <i class="fas fa-percentage mr-2"></i> Under the Fiji Employment Relations Act & Income Tax Act, employers can claim a <strong>150% Corporate Income Tax Deduction</strong> on wages paid for Family Care Leave, Paternity Leave, and Voluntary Employer FNPF contributions above 8%.
        </div>

        <!-- Year Filter -->
        <form action="" method="GET" class="mb-4">
            <input type="hidden" name="page" value="payroll/tax_rebate_report">
            <div class="row align-items-end">
                <div class="col-md-3 form-group mb-0">
                    <label class="font-weight-bold text-xs text-uppercase text-muted">Tax Year</label>
                    <select name="year" class="form-control form-control-sm rounded-0">
                        <?php for($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2 form-group mb-0">
                    <button type="submit" class="btn btn-sm btn-success btn-flat font-weight-bold"><i class="fas fa-filter mr-1"></i> Load Rebate Summary</button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="rebate-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Employee Name</th>
                        <th>Salary Month / Date</th>
                        <th class="text-right">Family Care Pay ($)</th>
                        <th class="text-right">Paternity Leave Pay ($)</th>
                        <th class="text-right">Voluntary FNPF Excess ($)</th>
                        <th class="text-right">Rebate Base (100%)</th>
                        <th class="text-right">150% FRCS Tax Deduction</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                            $family = floatval($row['family_care_leave_pay'] ?? 0);
                            $paternity = floatval($row['paternity_leave_pay'] ?? 0);
                            $fnpfVol = floatval($row['fnpf_er_voluntary_excess'] ?? 0);
                            $base = $family + $paternity + $fnpfVol;
                            $rebate150 = $base * 1.5;

                            if($base > 0):
                                $tot_family_care += $family;
                                $tot_paternity += $paternity;
                                $tot_fnpf_vol += $fnpfVol;
                                $tot_rebate_base += $base;
                                $tot_rebate_claim += $rebate150;
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold">
                            <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                            <small class="text-muted d-block"><?= htmlspecialchars($row['employee_code']) ?></small>
                        </td>
                        <td class="align-middle"><?= htmlspecialchars($row['salary_month']) ?> (<?= date("d M Y", strtotime($row['pay_date'])) ?>)</td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($family, 2) ?></td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($paternity, 2) ?></td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($fnpfVol, 2) ?></td>
                        <td class="text-right align-middle font-weight-bold text-dark">$<?= number_format($base, 2) ?></td>
                        <td class="text-right align-middle font-weight-bold text-success font-monospace">$<?= number_format($rebate150, 2) ?></td>
                    </tr>
                    <?php
                            endif;
                        endwhile;
                    endif;
                    ?>
                    <?php if($tot_rebate_base <= 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No 150% tax rebate eligible leave or FNPF excess wages recorded for year <?= $year ?>.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if($tot_rebate_base > 0): ?>
                <tfoot class="bg-light font-weight-bold">
                    <tr>
                        <td colspan="3" class="text-right">ANNUAL REBATE TOTALS:</td>
                        <td class="text-right text-dark">$<?= number_format($tot_family_care, 2) ?></td>
                        <td class="text-right text-dark">$<?= number_format($tot_paternity, 2) ?></td>
                        <td class="text-right text-dark">$<?= number_format($tot_fnpf_vol, 2) ?></td>
                        <td class="text-right text-dark">$<?= number_format($tot_rebate_base, 2) ?></td>
                        <td class="text-right text-success h6 mb-0 font-weight-bold">$<?= number_format($tot_rebate_claim, 2) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#rebate-table').dataTable();
});
</script>
