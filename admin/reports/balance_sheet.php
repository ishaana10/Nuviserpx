<?php
function format_num($number){
	return number_format($number, 2);
}

$tenant_id = $_settings->active_tenant_id();
$as_of = isset($_GET['as_of']) ? $conn->real_escape_string($_GET['as_of']) : date("Y-m-d");

// Fetch accounts categorized by group (Assets, Liabilities, Equity)
$qry = $conn->query("
    SELECT a.id, a.name AS account_name, g.name AS group_name, g.type AS group_type,
           SUM(CASE WHEN g2.type = 1 THEN ji.amount ELSE 0 END) AS total_debit,
           SUM(CASE WHEN g2.type = 2 THEN ji.amount ELSE 0 END) AS total_credit
    FROM `account_list` a
    INNER JOIN `group_list` g ON a.group_id = g.id
    LEFT JOIN `journal_items` ji ON ji.account_id = a.id
    LEFT JOIN `group_list` g2 ON ji.group_id = g2.id
    INNER JOIN `journal_entries` j ON ji.journal_id = j.id
    WHERE a.tenant_id = '{$tenant_id}' AND j.tenant_id = '{$tenant_id}' AND a.delete_flag = 0 AND date(j.journal_date) <= '{$as_of}' AND g.name IN ('Assets', 'Liabilities', 'Equity')
    GROUP BY a.id, a.name, g.name, g.type
    ORDER BY g.name ASC, a.name ASC
");

$assets = [];
$liabilities = [];
$equity = [];

$tot_assets = 0;
$tot_liabilities = 0;
$tot_equity = 0;

while($row = $qry->fetch_assoc()){
    $debit = (float)$row['total_debit'];
    $credit = (float)$row['total_credit'];

    if($row['group_name'] == 'Assets'){
        $bal = $debit - $credit; // Asset balance = Dr - Cr
        $assets[] = ['name' => $row['account_name'], 'balance' => $bal];
        $tot_assets += $bal;
    } elseif($row['group_name'] == 'Liabilities'){
        $bal = $credit - $debit; // Liability balance = Cr - Dr
        $liabilities[] = ['name' => $row['account_name'], 'balance' => $bal];
        $tot_liabilities += $bal;
    } elseif($row['group_name'] == 'Equity'){
        $bal = $credit - $debit; // Equity balance = Cr - Dr
        $equity[] = ['name' => $row['account_name'], 'balance' => $bal];
        $tot_equity += $bal;
    }
}

// Calculate Net Income (Profit) up to as_of date to include in Retained Earnings/Equity
$pnl_qry = $conn->query("
    SELECT g.name AS group_name,
           SUM(CASE WHEN g2.type = 1 THEN ji.amount ELSE 0 END) AS total_debit,
           SUM(CASE WHEN g2.type = 2 THEN ji.amount ELSE 0 END) AS total_credit
    FROM `account_list` a
    INNER JOIN `group_list` g ON a.group_id = g.id
    LEFT JOIN `journal_items` ji ON ji.account_id = a.id
    LEFT JOIN `group_list` g2 ON ji.group_id = g2.id
    INNER JOIN `journal_entries` j ON ji.journal_id = j.id
    WHERE a.tenant_id = '{$tenant_id}' AND j.tenant_id = '{$tenant_id}' AND a.delete_flag = 0 AND date(j.journal_date) <= '{$as_of}' AND g.name IN ('Revenue', 'Expenses')
    GROUP BY g.name
");

$revenue_tot = 0;
$expense_tot = 0;
while($prow = $pnl_qry->fetch_assoc()){
    if($prow['group_name'] == 'Revenue'){
        $revenue_tot = (float)$prow['total_credit'] - (float)$prow['total_debit'];
    } elseif($prow['group_name'] == 'Expenses'){
        $expense_tot = (float)$prow['total_debit'] - (float)$prow['total_credit'];
    }
}
$net_income = $revenue_tot - $expense_tot;
$tot_equity += $net_income;
$tot_liab_equity = $tot_liabilities + $tot_equity;
?>

<div class="card card-outline card-success">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-balance-scale text-success"></i> Balance Sheet (Statement of Financial Position)</h3>
	</div>
	<div class="card-body">
        <div class="callout border-success shadow rounded-0">
            <form action="" id="filter">
            <div class="row align-items-end">
                <div class="col-md-6 form-group">
                    <label for="as_of" class="control-label">As of Date</label>
                    <input type="date" id="as_of" name="as_of" value="<?= $as_of ?>" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-6 form-group">
                    <button class="btn btn-success btn-flat btn-sm"><i class="fa fa-filter"></i> Apply Filter</button>
			        <button class="btn btn-default border btn-flat btn-sm" id="print" type="button"><i class="fa fa-print"></i> Print</button>
                </div>
            </div>
            </form>
        </div>

        <div class="container-fluid" id="outprint">
            <div class="text-center mb-4">
		<h3 class="font-weight-bold m-0"><?= $_settings->info('name') ?></h3>
		<h4 class="font-weight-bold text-success">BALANCE SHEET STATEMENT</h4>
		<p class="m-0 text-muted small">As of <?= date("d F Y" , strtotime($as_of)) ?></p>
            </div>
            <hr>

            <div class="row">
		<!-- Assets Column -->
		<div class="col-md-6 border-right">
			<h5 class="font-weight-bold text-navy border-bottom pb-2"><i class="fas fa-university"></i> ASSETS</h5>
			<table class="table table-sm table-striped">
				<tbody>
					<?php foreach($assets as $a): ?>
					<tr>
						<td><?= htmlspecialchars($a['name']) ?></td>
						<td class="text-right font-weight-bold">$<?= format_num($a['balance']) ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot class="bg-light">
					<tr class="h6">
						<th class="font-weight-bold text-navy">TOTAL ASSETS</th>
						<th class="text-right font-weight-bold text-success">$<?= format_num($tot_assets) ?></th>
					</tr>
				</tfoot>
			</table>
		</div>

		<!-- Liabilities & Equity Column -->
		<div class="col-md-6">
			<h5 class="font-weight-bold text-navy border-bottom pb-2"><i class="fas fa-file-invoice-dollar"></i> LIABILITIES</h5>
			<table class="table table-sm table-striped mb-4">
				<tbody>
					<?php foreach($liabilities as $l): ?>
					<tr>
						<td><?= htmlspecialchars($l['name']) ?></td>
						<td class="text-right font-weight-bold">$<?= format_num($l['balance']) ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot class="bg-light">
					<tr>
						<th class="font-weight-bold">Total Liabilities</th>
						<th class="text-right font-weight-bold">$<?= format_num($tot_liabilities) ?></th>
					</tr>
				</tfoot>
			</table>

			<h5 class="font-weight-bold text-navy border-bottom pb-2"><i class="fas fa-chart-line"></i> EQUITY</h5>
			<table class="table table-sm table-striped">
				<tbody>
					<?php foreach($equity as $e): ?>
					<tr>
						<td><?= htmlspecialchars($e['name']) ?></td>
						<td class="text-right font-weight-bold">$<?= format_num($e['balance']) ?></td>
					</tr>
					<?php endforeach; ?>
					<tr>
						<td>Current Retained Earnings / Net Income</td>
						<td class="text-right font-weight-bold text-<?= $net_income >= 0 ? 'success' : 'danger' ?>">$<?= format_num($net_income) ?></td>
					</tr>
				</tbody>
				<tfoot class="bg-light">
					<tr>
						<th class="font-weight-bold">Total Equity</th>
						<th class="text-right font-weight-bold">$<?= format_num($tot_equity) ?></th>
					</tr>
					<tr class="h6 bg-navy text-white">
						<th class="font-weight-bold">TOTAL LIABILITIES & EQUITY</th>
						<th class="text-right font-weight-bold text-warning">$<?= format_num($tot_liab_equity) ?></th>
					</tr>
				</tfoot>
			</table>
		</div>
            </div>

            <!-- Balanced Check Badge -->
            <div class="mt-4 p-3 text-center <?= abs($tot_assets - $tot_liab_equity) < 0.01 ? 'bg-success' : 'bg-danger' ?> text-white rounded">
		<h6 class="m-0 font-weight-bold">
			<?php if(abs($tot_assets - $tot_liab_equity) < 0.01): ?>
				<i class="fas fa-check-circle mr-1"></i> Balance Sheet is Balanced (Assets = Liabilities + Equity: $<?= format_num($tot_assets) ?>)
			<?php else: ?>
				<i class="fas fa-exclamation-triangle mr-1"></i> Balance Sheet Unbalanced Difference: $<?= format_num(abs($tot_assets - $tot_liab_equity)) ?>
			<?php endif; ?>
		</h6>
            </div>
        </div>
	</div>
</div>

<script>
	$(document).ready(function(){
        $('#filter').submit(function(e){
            e.preventDefault();
            location.href="./?page=reports/balance_sheet&"+$(this).serialize();
        });

        $('#print').click(function(){
            start_loader();
            var _h = $('head').clone();
            var _p = $('#outprint').clone();
            var el = $('<div>');
            _h.find('title').text('Balance Sheet Statement')
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