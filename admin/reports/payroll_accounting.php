<?php
function format_num($number){
	return number_format($number, 2);
}

$tenant_id = $_settings->active_tenant_id();
$month = isset($_GET['month']) ? $conn->real_escape_string($_GET['month']) : date("F Y");

$qry = $conn->query("
    SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.employee_code, e.tin, e.fnpf_no
    FROM `payroll_list` p
    INNER JOIN `employee_list` e ON p.employee_id = e.id
    WHERE p.tenant_id = '{$tenant_id}' AND p.salary_month = '" . $conn->real_escape_string($month) . "'
    ORDER BY e.firstname ASC
");

$tot_gross = 0;
$tot_emp_fnpf = 0;
$tot_paye = 0;
$tot_srt = 0;
$tot_deductions = 0;
$tot_net = 0;
$tot_empr_fnpf = 0;
$tot_workcare = 0;
$tot_employer_cost = 0;

$rows = [];
while($r = $qry->fetch_assoc()){
    $rows[] = $r;
    $tot_gross += (float)$r['gross_salary'];
    $tot_emp_fnpf += (float)$r['employee_fnpf'];
    $tot_paye += (float)$r['paye_tax'];
    $tot_srt += (float)$r['srt_tax'];
    $tot_deductions += (float)$r['deductions'];
    $tot_net += (float)$r['net_salary'];
    $tot_empr_fnpf += (float)$r['employer_fnpf'];
    $tot_workcare += (float)$r['workcare_levy'];
    $tot_employer_cost += (float)$r['employer_cost'];
}
?>

<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-file-invoice-dollar text-primary"></i> Payroll Accounting & Statutory Liabilities Summary</h3>
	</div>
	<div class="card-body">
        <div class="callout border-primary shadow rounded-0">
            <form action="" id="filter">
            <div class="row align-items-end">
                <div class="col-md-6 form-group">
                    <label for="month" class="control-label">Salary Period / Month</label>
                    <input type="text" id="month" name="month" value="<?= htmlspecialchars($month) ?>" placeholder="e.g. September 2024" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-6 form-group">
                    <button class="btn btn-primary btn-flat btn-sm"><i class="fa fa-filter"></i> Generate Report</button>
			        <button class="btn btn-default border btn-flat btn-sm" id="print" type="button"><i class="fa fa-print"></i> Print Report</button>
                </div>
            </div>
            </form>
        </div>

        <div class="container-fluid" id="outprint">
            <div class="text-center mb-4">
		<h3 class="font-weight-bold m-0"><?= $_settings->info('name') ?></h3>
		<h4 class="font-weight-bold text-navy">PAYROLL ACCOUNTING & STATUTORY LIABILITIES SUMMARY</h4>
		<p class="m-0 text-muted small">Period: <?= htmlspecialchars($month) ?></p>
            </div>
            <hr>

            <div class="table-responsive">
		<table class="table table-sm table-bordered table-striped">
			<thead class="bg-navy text-white text-center">
				<tr>
					<th>#</th>
					<th>Employee</th>
					<th>Gross Salary</th>
					<th>FNPF Employee (8%)</th>
					<th>PAYE Tax</th>
					<th>Net Salary</th>
					<th>FNPF Employer (8%)</th>
					<th>Workcare Levy (1%)</th>
					<th>Total Employer Cost</th>
				</tr>
			</thead>
			<tbody>
				<?php if(!empty($rows)): $i=1; foreach($rows as $row): ?>
				<tr>
					<td class="text-center"><?= $i++ ?></td>
					<td>
						<b><?= htmlspecialchars($row['emp_name']) ?></b>
						<small class="text-muted d-block"><?= $row['employee_code'] ?> | TIN: <?= $row['tin'] ?></small>
					</td>
					<td class="text-right">$<?= format_num($row['gross_salary']) ?></td>
					<td class="text-right text-info">$<?= format_num($row['employee_fnpf']) ?></td>
					<td class="text-right text-warning">$<?= format_num($row['paye_tax']) ?></td>
					<td class="text-right font-weight-bold text-success">$<?= format_num($row['net_salary']) ?></td>
					<td class="text-right text-primary">$<?= format_num($row['employer_fnpf']) ?></td>
					<td class="text-right">$<?= format_num($row['workcare_levy']) ?></td>
					<td class="text-right font-weight-bold">$<?= format_num($row['employer_cost']) ?></td>
				</tr>
				<?php endforeach; else: ?>
				<tr><td colspan="9" class="text-center text-muted">No payroll entries found for <?= htmlspecialchars($month) ?>.</td></tr>
				<?php endif; ?>
			</tbody>
			<tfoot class="bg-light font-weight-bold h6">
				<tr>
					<td colspan="2" class="text-right">TOTALS:</td>
					<td class="text-right">$<?= format_num($tot_gross) ?></td>
					<td class="text-right text-info">$<?= format_num($tot_emp_fnpf) ?></td>
					<td class="text-right text-warning">$<?= format_num($tot_paye) ?></td>
					<td class="text-right text-success">$<?= format_num($tot_net) ?></td>
					<td class="text-right text-primary">$<?= format_num($tot_empr_fnpf) ?></td>
					<td class="text-right">$<?= format_num($tot_workcare) ?></td>
					<td class="text-right text-navy font-weight-bold">$<?= format_num($tot_employer_cost) ?></td>
				</tr>
			</tfoot>
		</table>
            </div>

            <!-- Statutory Remittance Box -->
            <div class="row mt-4">
		<div class="col-md-6">
			<div class="card border p-3 bg-light">
				<h6 class="font-weight-bold text-navy mb-2"><i class="fas fa-university"></i> FNPF Remittance Summary</h6>
				<p class="m-0 small">Employee Contribution (8%): <b>$<?= format_num($tot_emp_fnpf) ?></b></p>
				<p class="m-0 small">Employer Contribution (8%): <b>$<?= format_num($tot_empr_fnpf) ?></b></p>
				<hr class="my-1">
				<p class="m-0 font-weight-bold text-primary">Total FNPF Payable to FNPF Board: $<?= format_num($tot_emp_fnpf + $tot_empr_fnpf) ?></p>
			</div>
		</div>
		<div class="col-md-6">
			<div class="card border p-3 bg-light">
				<h6 class="font-weight-bold text-navy mb-2"><i class="fas fa-calculator"></i> FRCS Tax Remittance Summary</h6>
				<p class="m-0 small">PAYE Income Tax Withheld: <b>$<?= format_num($tot_paye) ?></b></p>
				<p class="m-0 small">Social Responsibility Tax (SRT): <b>$<?= format_num($tot_srt) ?></b></p>
				<hr class="my-1">
				<p class="m-0 font-weight-bold text-warning">Total PAYE Tax Payable to FRCS: $<?= format_num($tot_paye + $tot_srt) ?></p>
			</div>
		</div>
            </div>
        </div>
	</div>
</div>

<script>
	$(document).ready(function(){
        $('#filter').submit(function(e){
            e.preventDefault();
            location.href="./?page=reports/payroll_accounting&"+$(this).serialize();
        });

        $('#print').click(function(){
            start_loader();
            var _h = $('head').clone();
            var _p = $('#outprint').clone();
            var el = $('<div>');
            _h.find('title').text('Payroll Accounting Summary')
            el.append(_h);
            el.append(_p);
            var nw = window.open("","_blank","width=900,height=700");
            nw.document.write(el.html());
            nw.document.close();
            setTimeout(() => {
                nw.print();
                setTimeout(() => {
                    nw.close();
                    end_loader();
                }, 200);
            }, 500);
        });
	});
</script>