<?php
function format_num($number){
	return number_format($number, 2);
}

$tenant_id = $_settings->active_tenant_id();
$from = isset($_GET['from']) ? $conn->real_escape_string($_GET['from']) : date("Y-01-01");
$to = isset($_GET['to']) ? $conn->real_escape_string($_GET['to']) : date("Y-m-d");

// Query Revenue & Expense accounts
$qry = $conn->query("
    SELECT a.id, a.name AS account_name, g.name AS group_name,
           SUM(CASE WHEN g2.type = 1 THEN ji.amount ELSE 0 END) AS total_debit,
           SUM(CASE WHEN g2.type = 2 THEN ji.amount ELSE 0 END) AS total_credit
    FROM `account_list` a
    INNER JOIN `group_list` g ON a.group_id = g.id
    LEFT JOIN `journal_items` ji ON ji.account_id = a.id
    LEFT JOIN `group_list` g2 ON ji.group_id = g2.id
    INNER JOIN `journal_entries` j ON ji.journal_id = j.id
    WHERE a.tenant_id = '{$tenant_id}' AND j.tenant_id = '{$tenant_id}' AND a.delete_flag = 0
    AND date(j.journal_date) BETWEEN '{$from}' AND '{$to}' AND g.name IN ('Revenue', 'Expenses')
    GROUP BY a.id, a.name, g.name
    ORDER BY g.name ASC, a.name ASC
");

$revenue_items = [];
$expense_items = [];
$tot_revenue = 0;
$tot_expense = 0;

while($row = $qry->fetch_assoc()){
    $debit = (float)$row['total_debit'];
    $credit = (float)$row['total_credit'];

    if($row['group_name'] == 'Revenue'){
        $bal = $credit - $debit;
        if($bal != 0){
            $revenue_items[] = ['name' => $row['account_name'], 'amount' => $bal];
            $tot_revenue += $bal;
        }
    } elseif($row['group_name'] == 'Expenses'){
        $bal = $debit - $credit;
        if($bal != 0){
            $expense_items[] = ['name' => $row['account_name'], 'amount' => $bal];
            $tot_expense += $bal;
        }
    }
}

$net_profit = $tot_revenue - $tot_expense;
?>

<div class="card card-outline card-info">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-file-invoice-dollar text-info"></i> Income Statement (Profit & Loss)</h3>
	</div>
	<div class="card-body">
        <div class="callout border-info shadow rounded-0">
            <form action="" id="filter">
            <div class="row align-items-end">
                <div class="col-md-4 form-group">
                    <label for="from" class="control-label">Period From</label>
                    <input type="date" id="from" name="from" value="<?= $from ?>" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-4 form-group">
                    <label for="to" class="control-label">Period To</label>
                    <input type="date" id="to" name="to" value="<?= $to ?>" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-4 form-group">
                    <button class="btn btn-info btn-flat btn-sm"><i class="fa fa-filter"></i> Apply Filter</button>
			        <button class="btn btn-default border btn-flat btn-sm" id="print" type="button"><i class="fa fa-print"></i> Print Statement</button>
                </div>
            </div>
            </form>
        </div>

        <div class="container-fluid" id="outprint">
            <div class="text-center mb-4">
		<h3 class="font-weight-bold m-0"><?= $_settings->info('name') ?></h3>
		<h4 class="font-weight-bold text-info">INCOME STATEMENT (PROFIT & LOSS)</h4>
		<p class="m-0 text-muted small"><?= date("d M Y" , strtotime($from)) . ' to ' . date("d M Y" , strtotime($to)) ?></p>
            </div>
            <hr>

            <div class="card card-outline card-success mb-4 shadow-none border">
		<div class="card-header bg-success text-white py-1">
			<h6 class="card-title font-weight-bold m-0"><i class="fas fa-arrow-down mr-1"></i> OPERATING REVENUE</h6>
		</div>
		<div class="card-body p-0">
			<table class="table table-sm table-striped m-0">
				<tbody>
					<?php if(!empty($revenue_items)): foreach($revenue_items as $r): ?>
					<tr>
						<td><?= htmlspecialchars($r['name']) ?></td>
						<td class="text-right font-weight-bold" width="200">$<?= format_num($r['amount']) ?></td>
					</tr>
					<?php endforeach; else: ?>
					<tr><td colspan="2" class="text-center text-muted">No revenue entries recorded for this period.</td></tr>
					<?php endif; ?>
				</tbody>
				<tfoot class="bg-light">
					<tr class="h6">
						<th class="font-weight-bold text-navy">TOTAL OPERATING REVENUE</th>
						<th class="text-right font-weight-bold text-success">$<?= format_num($tot_revenue) ?></th>
					</tr>
				</tfoot>
			</table>
		</div>
            </div>

            <div class="card card-outline card-danger mb-4 shadow-none border">
		<div class="card-header bg-danger text-white py-1">
			<h6 class="card-title font-weight-bold m-0"><i class="fas fa-arrow-up mr-1"></i> OPERATING EXPENSES & COST OF SALES</h6>
		</div>
		<div class="card-body p-0">
			<table class="table table-sm table-striped m-0">
				<tbody>
					<?php if(!empty($expense_items)): foreach($expense_items as $e): ?>
					<tr>
						<td><?= htmlspecialchars($e['name']) ?></td>
						<td class="text-right font-weight-bold" width="200">$<?= format_num($e['amount']) ?></td>
					</tr>
					<?php endforeach; else: ?>
					<tr><td colspan="2" class="text-center text-muted">No expense entries recorded for this period.</td></tr>
					<?php endif; ?>
				</tbody>
				<tfoot class="bg-light">
					<tr class="h6">
						<th class="font-weight-bold text-navy">TOTAL OPERATING EXPENSES</th>
						<th class="text-right font-weight-bold text-danger">$<?= format_num($tot_expense) ?></th>
					</tr>
				</tfoot>
			</table>
		</div>
            </div>

            <!-- Net Profit / Loss Banner -->
            <div class="card bg-navy text-white p-3 shadow-none">
		<div class="row align-items-center">
			<div class="col-8">
				<h5 class="m-0 font-weight-bold text-uppercase"><i class="fas fa-calculator text-warning mr-2"></i> NET OPERATING PROFIT / (LOSS)</h5>
				<small class="text-light">Revenue minus Expenses for period</small>
			</div>
			<div class="col-4 text-right">
				<h3 class="m-0 font-weight-bold text-<?= $net_profit >= 0 ? 'warning' : 'danger' ?>">$<?= format_num($net_profit) ?></h3>
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
            location.href="./?page=reports/income_statement&"+$(this).serialize();
        });

        $('#print').click(function(){
            start_loader();
            var _h = $('head').clone();
            var _p = $('#outprint').clone();
            var el = $('<div>');
            _h.find('title').text('Income Statement (Profit & Loss)')
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