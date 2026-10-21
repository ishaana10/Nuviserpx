<?php
$tenant_id = $_settings->active_tenant_id();

$month = isset($_GET['month']) ? $_GET['month'] : date('F Y');
if(isset($_GET['export_csv']) && $_GET['export_csv'] == 1){
    $qry = $conn->query("
        SELECT p.*, e.firstname, e.lastname, e.fnpf_no, e.tin
        FROM `payroll_list` p
        LEFT JOIN `employee_list` e ON p.employee_id = e.id
        WHERE p.tenant_id = '{$tenant_id}' AND p.salary_month = '{$conn->real_escape_string($month)}'
        ORDER BY e.firstname ASC
    ");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fnpf_cs3_schedule_' . strtolower(str_replace(' ', '_', $month)) . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['FNPF_MEMBER_NO', 'EMPLOYEE_NAME', 'TIN_NUMBER', 'GROSS_WAGES', 'EMPLOYEE_CONTRIB_8', 'EMPLOYER_CONTRIB_8', 'TOTAL_CONTRIB_16']);

    while($row = $qry->fetch_assoc()){
        $emp_fnpf = floatval($row['employee_fnpf']);
        $empr_fnpf = floatval($row['employer_fnpf']);
        $tot_fnpf = $emp_fnpf + $empr_fnpf;

        fputcsv($output, [
            $row['fnpf_no'] ?: 'N/A',
            $row['firstname'] . ' ' . $row['lastname'],
            $row['tin'] ?: 'N/A',
            number_format($row['gross_salary'], 2, '.', ''),
            number_format($emp_fnpf, 2, '.', ''),
            number_format($empr_fnpf, 2, '.', ''),
            number_format($tot_fnpf, 2, '.', '')
        ]);
    }
    fclose($output);
    exit;
}

$qry = $conn->query("
    SELECT p.*, e.firstname, e.lastname, e.fnpf_no, e.tin, e.employee_code
    FROM `payroll_list` p
    LEFT JOIN `employee_list` e ON p.employee_id = e.id
    WHERE p.tenant_id = '{$tenant_id}' AND p.salary_month = '{$conn->real_escape_string($month)}'
    ORDER BY e.firstname ASC
");

$tot_gross = 0;
$tot_emp_fnpf = 0;
$tot_empr_fnpf = 0;
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-file-contract text-primary mr-2"></i> Fiji National Provident Fund (FNPF) Schedule CS3</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <a href="./?page=payroll/fnpf_schedule&month=<?= urlencode($month) ?>&export_csv=1" class="btn btn-sm btn-success btn-flat font-weight-bold"><i class="fas fa-file-excel mr-1"></i> Export FNPF CS3 CSV</a>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Month Filter -->
        <form action="" method="GET" class="mb-4">
            <input type="hidden" name="page" value="payroll/fnpf_schedule">
            <div class="row align-items-end">
                <div class="col-md-4 form-group mb-0">
                    <label class="font-weight-bold text-xs text-uppercase text-muted">Payroll Month / Period</label>
                    <input type="text" name="month" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($month) ?>" placeholder="e.g. October 2024">
                </div>
                <div class="col-md-2 form-group mb-0">
                    <button type="submit" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-filter mr-1"></i> Load Schedule</button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>FNPF Member No</th>
                        <th>Employee Name & Code</th>
                        <th>TIN Number</th>
                        <th class="text-right">Gross Wages ($)</th>
                        <th class="text-right">Employee (8%)</th>
                        <th class="text-right">Employer (8%)</th>
                        <th class="text-right">Total FNPF (16%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                            $emp_fnpf = floatval($row['employee_fnpf']);
                            $empr_fnpf = floatval($row['employer_fnpf']);
                            $tot_fnpf = $emp_fnpf + $empr_fnpf;

                            $tot_gross += floatval($row['gross_salary']);
                            $tot_emp_fnpf += $emp_fnpf;
                            $tot_empr_fnpf += $empr_fnpf;
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold font-monospace text-primary"><?= htmlspecialchars($row['fnpf_no'] ?: 'N/A') ?></td>
                        <td class="align-middle font-weight-bold">
                            <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                            <small class="text-muted d-block"><?= htmlspecialchars($row['employee_code']) ?></small>
                        </td>
                        <td class="align-middle font-monospace"><?= htmlspecialchars($row['tin'] ?: 'N/A') ?></td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($row['gross_salary'], 2) ?></td>
                        <td class="text-right align-middle text-info font-weight-bold">$<?= number_format($emp_fnpf, 2) ?></td>
                        <td class="text-right align-middle text-primary font-weight-bold">$<?= number_format($empr_fnpf, 2) ?></td>
                        <td class="text-right align-middle text-success font-weight-bold font-monospace">$<?= number_format($tot_fnpf, 2) ?></td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No payroll records found for salary period: <?= htmlspecialchars($month) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if($tot_gross > 0): ?>
                <tfoot class="bg-light font-weight-bold">
                    <tr>
                        <td colspan="4" class="text-right">PERIOD TOTALS:</td>
                        <td class="text-right text-dark">$<?= number_format($tot_gross, 2) ?></td>
                        <td class="text-right text-info">$<?= number_format($tot_emp_fnpf, 2) ?></td>
                        <td class="text-right text-primary">$<?= number_format($tot_empr_fnpf, 2) ?></td>
                        <td class="text-right text-success h6 mb-0 font-weight-bold">$<?= number_format($tot_emp_fnpf + $tot_empr_fnpf, 2) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
