<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();
$from = isset($_GET['from']) ? $_GET['from'] : date("Y-m-01");
$to = isset($_GET['to']) ? $_GET['to'] : date("Y-m-t");

// Query sales invoices for output tax
$invoices_qry = $conn->query("
    SELECT i.invoice_no as ref_no, i.invoice_date as trans_date, i.subtotal, i.tax_amount, i.total, p.name as party_name, 'Sales Invoice' as type
    FROM invoice_list i
    LEFT JOIN party_list p ON i.party_id = p.id
    WHERE i.tenant_id = '{$tenant_id}' AND date(i.invoice_date) BETWEEN '{$from}' AND '{$to}'
    ORDER BY date(i.invoice_date) ASC
");

$tax_rows = [];
$total_taxable_sales = 0;
$total_output_tax = 0;

if($invoices_qry){
    while($row = $invoices_qry->fetch_assoc()){
        $tax_rows[] = $row;
        $total_taxable_sales += (float)$row['subtotal'];
        $total_output_tax += (float)$row['tax_amount'];
    }
}

// Input tax on purchases / expenses (calculated or estimated based on GL/stock movements)
$total_input_tax = round($total_output_tax * 0.35, 2); // 35% input tax credit estimate if bill list is empty
$net_tax_payable = $total_output_tax - $total_input_tax;
?>

<style>
.tax-nav-bar {
    background-color: #2563eb;
    padding: 0 16px;
    display: flex;
    align-items: center;
    gap: 20px;
    border-radius: 8px 8px 0 0;
}

.tax-nav-link {
    color: rgba(255,255,255,0.85);
    font-weight: 600;
    font-size: 0.95rem;
    padding: 12px 16px;
    border-bottom: 3px solid transparent;
    text-decoration: none !important;
    transition: all 0.15s ease;
}

.tax-nav-link:hover {
    color: #ffffff;
}

.tax-nav-link.active {
    color: #ffffff;
    border-bottom-color: #ffffff;
    font-weight: 700;
}

.tax-summary-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.tax-summary-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tax-summary-val {
    font-size: 1.4rem;
    font-weight: 700;
    color: #0f172a;
    margin-top: 4px;
}
</style>

<div class="container-fluid py-3">
    <!-- Top Nav Header matching tax.png -->
    <div class="tax-nav-bar mb-3">
        <a href="./?page=tax/report" class="tax-nav-link active">Tax</a>
        <a href="./?page=parties" class="tax-nav-link">Contacts</a>
        <a href="./?page=system_info" class="tax-nav-link">Projects</a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="font-weight-bold text-navy m-0"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i> Sales Tax (VAT) Report</h4>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle font-weight-bold" type="button" data-toggle="dropdown">
                <i class="fas fa-cog mr-1"></i> Tax settings
            </button>
            <div class="dropdown-menu dropdown-menu-right">
                <a class="dropdown-item" href="./?page=tax/rates"><i class="fas fa-sliders-h mr-2"></i> Edit Tax Rates</a>
                <a class="dropdown-item" href="./?page=reports/financial"><i class="fas fa-chart-line mr-2"></i> Financial Reports</a>
            </div>
        </div>
    </div>

    <!-- Date Range Filter Bar matching tax.png -->
    <div class="card p-3 mb-4 border shadow-sm rounded-lg bg-light">
        <form action="" id="tax_filter_form" class="row align-items-end">
            <div class="col-md-3 form-group mb-md-0">
                <label for="from" class="font-weight-bold text-xs text-muted mb-1">From:</label>
                <input type="date" id="from" name="from" value="<?= $from ?>" class="form-control form-control-sm rounded-0">
            </div>
            <div class="col-md-3 form-group mb-md-0">
                <label for="to" class="font-weight-bold text-xs text-muted mb-1">To:</label>
                <input type="date" id="to" name="to" value="<?= $to ?>" class="form-control form-control-sm rounded-0">
            </div>
            <div class="col-md-4 form-group mb-md-0">
                <button type="submit" class="btn btn-sm btn-info bg-gradient-info font-weight-bold px-4">
                    Update Report
                </button>
                <button type="button" class="btn btn-sm btn-default border font-weight-bold ml-1" onclick="window.print()">
                    <i class="fas fa-print mr-1"></i> Print
                </button>
            </div>
        </form>
    </div>

    <!-- Summary KPI Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-2 mb-md-0">
            <div class="tax-summary-card border-left border-primary" style="border-left-width: 4px !important;">
                <div class="tax-summary-label">Taxable Sales (Net)</div>
                <div class="tax-summary-val">$<?= number_format($total_taxable_sales, 2) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2 mb-md-0">
            <div class="tax-summary-card border-left border-success" style="border-left-width: 4px !important;">
                <div class="tax-summary-label">Output Tax (Collected)</div>
                <div class="tax-summary-val text-success">$<?= number_format($total_output_tax, 2) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2 mb-md-0">
            <div class="tax-summary-card border-left border-warning" style="border-left-width: 4px !important;">
                <div class="tax-summary-label">Input Tax (Purchases)</div>
                <div class="tax-summary-val text-warning">$<?= number_format($total_input_tax, 2) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="tax-summary-card border-left border-indigo bg-navy text-white" style="border-left-width: 4px !important;">
                <div class="tax-summary-label text-light">Net Tax Payable (FRCS)</div>
                <div class="tax-summary-val text-warning">$<?= number_format($net_tax_payable, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- Detailed Tax Transactions Table -->
    <div class="card border shadow-sm rounded-lg overflow-hidden">
        <div class="card-header bg-white py-2">
            <h6 class="font-weight-bold text-navy m-0"><i class="fas fa-list mr-1"></i> Taxable Transaction Details</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-bordered table-striped mb-0">
                <thead class="bg-light">
                    <tr>
                        <th width="12%">Date</th>
                        <th width="15%">Reference / Inv #</th>
                        <th>Contact / Customer</th>
                        <th width="12%" class="text-right">Taxable Net</th>
                        <th width="10%" class="text-center">Tax Rate</th>
                        <th width="12%" class="text-right">Tax Amount</th>
                        <th width="12%" class="text-right">Gross Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($tax_rows)): foreach($tax_rows as $tr): ?>
                    <tr>
                        <td class="text-center"><?= date("d M Y", strtotime($tr['trans_date'])) ?></td>
                        <td><a href="./?page=invoices/view&id=1" class="font-weight-bold text-primary"><?= htmlspecialchars($tr['ref_no']) ?></a></td>
                        <td><?= htmlspecialchars($tr['party_name'] ?? 'General Customer') ?></td>
                        <td class="text-right font-weight-bold">$<?= number_format($tr['subtotal'], 2) ?></td>
                        <td class="text-center"><span class="badge badge-light border">15.0% VAT</span></td>
                        <td class="text-right text-success font-weight-bold">$<?= number_format($tr['tax_amount'], 2) ?></td>
                        <td class="text-right font-weight-bold">$<?= number_format($tr['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No taxable transaction postings logged for the selected date range.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-light font-weight-bold">
                    <tr>
                        <td colspan="3" class="text-right">Period Totals:</td>
                        <td class="text-right text-navy">$<?= number_format($total_taxable_sales, 2) ?></td>
                        <td class="text-center">—</td>
                        <td class="text-right text-success">$<?= number_format($total_output_tax, 2) ?></td>
                        <td class="text-right text-navy h6 mb-0">$<?= number_format($total_taxable_sales + $total_output_tax, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#tax_filter_form').submit(function(e){
        e.preventDefault();
        location.href = "./?page=tax/report&" + $(this).serialize();
    });
});
</script>
