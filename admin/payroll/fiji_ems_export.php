<?php
$tenant_id = $_settings->active_tenant_id();

$month = isset($_GET['month']) ? $_GET['month'] : date('F Y');
if(isset($_GET['export_csv']) && $_GET['export_csv'] == 1){
    $qry = $conn->query("
        SELECT p.*, e.firstname, e.lastname, e.tin, e.tax_code, e.is_resident
        FROM `payroll_list` p
        LEFT JOIN `employee_list` e ON p.employee_id = e.id
        WHERE p.tenant_id = '{$tenant_id}' AND p.salary_month = '{$conn->real_escape_string($month)}'
        ORDER BY e.firstname ASC
    ");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="frcs_ems_export_' . strtolower(str_replace(' ', '_', $month)) . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['TIN_NUMBER', 'EMPLOYEE_NAME', 'TAX_CODE', 'RESIDENT_STATUS', 'GROSS_PAY', 'PAYE_TAX', 'SRT_TAX', 'NET_PAY']);

    while($row = $qry->fetch_assoc()){
        fputcsv($output, [
            $row['tin'] ?: 'N/A',
            $row['firstname'] . ' ' . $row['lastname'],
            $row['tax_code'] ?: 'P',
            $row['is_resident'] == 1 ? 'Resident' : 'Non-Resident',
            number_format($row['gross_salary'], 2, '.', ''),
            number_format($row['paye_tax'], 2, '.', ''),
            number_format($row['srt_tax'], 2, '.', ''),
            number_format($row['net_salary'], 2, '.', '')
        ]);
    }
    fclose($output);
    exit;
}

$qry = $conn->query("
    SELECT p.*, e.firstname, e.lastname, e.tin, e.tax_code, e.is_resident, e.employee_code
    FROM `payroll_list` p
    LEFT JOIN `employee_list` e ON p.employee_id = e.id
    WHERE p.tenant_id = '{$tenant_id}' AND p.salary_month = '{$conn->real_escape_string($month)}'
    ORDER BY e.firstname ASC
");

$tot_gross = 0;
$tot_paye = 0;
$tot_srt = 0;
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i> Fiji FRCS Employer Monthly Summary (EMS) PAYE Export</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <a href="./?page=payroll/fiji_ems_export&month=<?= urlencode($month) ?>&export_csv=1" class="btn btn-sm btn-success btn-flat font-weight-bold"><i class="fas fa-file-csv mr-1"></i> Export FRCS EMS CSV</a>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Month Filter -->
        <form action="" method="GET" class="mb-4">
            <input type="hidden" name="page" value="payroll/fiji_ems_export">
            <div class="row align-items-end">
                <div class="col-md-4 form-group mb-0">
                    <label class="font-weight-bold text-xs text-uppercase text-muted">Payroll Month / Period</label>
                    <input type="text" name="month" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($month) ?>" placeholder="e.g. October 2024">
                </div>
                <div class="col-md-2 form-group mb-0">
                    <button type="submit" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-filter mr-1"></i> Load Report</button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Taxpayer TIN</th>
                        <th>Employee Name</th>
                        <th class="text-center">Tax Code</th>
                        <th class="text-center">Residency</th>
                        <th class="text-right">Gross Earnings ($)</th>
                        <th class="text-right">PAYE Tax Deducted ($)</th>
                        <th class="text-right">SRT Tax ($)</th>
                        <th class="text-right">Net Payable ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                            $tot_gross += floatval($row['gross_salary']);
                            $tot_paye += floatval($row['paye_tax']);
                            $tot_srt += floatval($row['srt_tax']);
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold font-monospace text-primary"><?= htmlspecialchars($row['tin'] ?: 'N/A') ?></td>
                        <td class="align-middle font-weight-bold">
                            <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                            <small class="text-muted d-block"><?= htmlspecialchars($row['employee_code']) ?></small>
                        </td>
                        <td class="text-center align-middle font-weight-bold"><span class="badge badge-light border"><?= htmlspecialchars($row['tax_code'] ?: 'P') ?></span></td>
                        <td class="text-center align-middle">
                            <span class="badge badge-<?= $row['is_resident'] == 1 ? 'success' : 'warning' ?> px-2 py-1">
                                <?= $row['is_resident'] == 1 ? 'Resident' : 'Non-Resident' ?>
                            </span>
                        </td>
                        <td class="text-right align-middle font-weight-bold">$<?= number_format($row['gross_salary'], 2) ?></td>
                        <td class="text-right align-middle text-danger font-weight-bold">$<?= number_format($row['paye_tax'], 2) ?></td>
                        <td class="text-right align-middle text-warning font-weight-bold">$<?= number_format($row['srt_tax'], 2) ?></td>
                        <td class="text-right align-middle text-success font-weight-bold font-monospace">$<?= number_format($row['net_salary'], 2) ?></td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">No payroll records found for salary period: <?= htmlspecialchars($month) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if($tot_gross > 0): ?>
                <tfoot class="bg-light font-weight-bold">
                    <tr>
                        <td colspan="5" class="text-right">MONTHLY EMS TOTALS:</td>
                        <td class="text-right text-dark">$<?= number_format($tot_gross, 2) ?></td>
                        <td class="text-right text-danger">$<?= number_format($tot_paye, 2) ?></td>
                        <td class="text-right text-warning">$<?= number_format($tot_srt, 2) ?></td>
                        <td class="text-right text-success h6 mb-0 font-weight-bold">$<?= number_format($tot_gross - $tot_paye - $tot_srt, 2) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
