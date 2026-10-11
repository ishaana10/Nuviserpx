<?php
function format_num($number){
	return number_format($number, 2);
}

$tenant_id = $_settings->active_tenant_id();
$from = isset($_GET['from']) ? $conn->real_escape_string($_GET['from']) : date("Y-m-01");
$to = isset($_GET['to']) ? $conn->real_escape_string($_GET['to']) : date("Y-m-t");
$account_id = isset($_GET['account_id']) ? $conn->real_escape_string($_GET['account_id']) : 'all';

$account_where = "";
if($account_id != 'all' && is_numeric($account_id)){
	$acc_id_clean = intval($account_id);
	$account_where = " AND a.id = '{$acc_id_clean}' ";
}
?>

<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-book-open text-primary"></i> General Ledger Report</h3>
	</div>
	<div class="card-body">
        <div class="callout border-primary shadow rounded-0">
            <h5 class="text-navy font-weight-bold"><i class="fas fa-filter"></i> Filter Options</h5>
            <form action="" id="filter">
            <div class="row align-items-end">
                <div class="col-md-3 form-group">
                    <label for="from" class="control-label">Date From</label>
                    <input type="date" id="from" name="from" value="<?= $from ?>" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-3 form-group">
                    <label for="to" class="control-label">Date To</label>
                    <input type="date" id="to" name="to" value="<?= $to ?>" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-4 form-group">
                    <label for="account_id" class="control-label">Account</label>
                    <select id="account_id" name="account_id" class="form-control form-control-sm rounded-0 select2">
			<option value="all" <?= $account_id == 'all' ? 'selected' : '' ?>>All Accounts</option>
			<?php
			$accounts = $conn->query("SELECT * FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
			while($acc = $accounts->fetch_assoc()):
			?>
			<option value="<?= $acc['id'] ?>" <?= $account_id == $acc['id'] ? 'selected' : '' ?>><?= $acc['name'] ?></option>
			<?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2 form-group">
                    <button class="btn btn-navy bg-gradient-navy btn-flat btn-sm"><i class="fa fa-filter"></i> Filter</button>
			        <button class="btn btn-default border btn-flat btn-sm" id="print" type="button"><i class="fa fa-print"></i> Print</button>
                </div>
            </div>
            </form>
        </div>

        <div class="container-fluid" id="outprint">
            <div class="text-center mb-3">
		<h3 class="font-weight-bold m-0"><?= $_settings->info('name') ?></h3>
		<h4 class="font-weight-bold text-navy">GENERAL LEDGER REPORT</h4>
		<p class="m-0 text-muted small"><?= date("d M Y" , strtotime($from)) . ' to ' . date("d M Y" , strtotime($to)) ?></p>
            </div>
            <hr>

            <?php
            $acc_qry = $conn->query("SELECT a.*, g.name as group_name, g.type as group_type
		FROM `account_list` a
		LEFT JOIN `group_list` g ON a.group_id = g.id
		WHERE a.tenant_id = '{$tenant_id}' AND a.delete_flag = 0 {$account_where}
		ORDER BY a.name ASC");

            while($acc = $acc_qry->fetch_assoc()):
		$current_acc_id = $acc['id'];

		// Get transactions
		$items_qry = $conn->query("SELECT j.journal_date, j.code, j.description as journal_desc, ji.amount, g.type as entry_type
			FROM `journal_items` ji
			INNER JOIN `journal_entries` j ON ji.journal_id = j.id
			INNER JOIN `group_list` g ON ji.group_id = g.id
			WHERE ji.account_id = '{$current_acc_id}' AND j.tenant_id = '{$tenant_id}'
			AND date(j.journal_date) BETWEEN '{$from}' AND '{$to}'
			ORDER BY date(j.journal_date) ASC, j.id ASC");

		if($items_qry->num_rows == 0 && $account_id != 'all') continue;
            ?>

            <div class="card card-outline card-navy mb-4 shadow-none border">
		<div class="card-header bg-navy text-white py-1">
			<h6 class="card-title m-0 font-weight-bold"><?= $acc['name'] ?> <small class="text-light">(<?= $acc['group_name'] ?? 'Account' ?>)</small></h6>
		</div>
		<div class="card-body p-0">
			<table class="table table-sm table-bordered table-striped m-0">
				<thead class="bg-light">
					<tr>
						<th width="12%">Date</th>
						<th width="15%">Journal Ref</th>
						<th>Description</th>
						<th width="15%" class="text-right">Debit (FJD)</th>
						<th width="15%" class="text-right">Credit (FJD)</th>
						<th width="15%" class="text-right">Running Balance</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$running_bal = 0;
					$tot_debit = 0;
					$tot_credit = 0;

					if($items_qry->num_rows > 0):
						while($item = $items_qry->fetch_assoc()):
							$debit = ($item['entry_type'] == 1) ? (float)$item['amount'] : 0;
							$credit = ($item['entry_type'] == 2) ? (float)$item['amount'] : 0;
							$tot_debit += $debit;
							$tot_credit += $credit;

							// Assets/Expenses: Balance = Debit - Credit; Liabilities/Equity/Revenue: Balance = Credit - Debit
							if(isset($acc['group_type']) && $acc['group_type'] == 2){
								$running_bal += ($credit - $debit);
							} else {
								$running_bal += ($debit - $credit);
							}
					?>
					<tr>
						<td><?= date('d M Y', strtotime($item['journal_date'])) ?></td>
						<td><b><?= $item['code'] ?></b></td>
						<td><?= htmlspecialchars($item['journal_desc']) ?></td>
						<td class="text-right"><?= $debit > 0 ? format_num($debit) : '—' ?></td>
						<td class="text-right"><?= $credit > 0 ? format_num($credit) : '—' ?></td>
						<td class="text-right font-weight-bold"><?= format_num($running_bal) ?></td>
					</tr>
					<?php
						endwhile;
					else:
					?>
					<tr>
						<td colspan="6" class="text-center text-muted">No transactions found for this period.</td>
					</tr>
					<?php endif; ?>
				</tbody>
				<tfoot class="bg-light font-weight-bold">
					<tr>
						<td colspan="3" class="text-right">Period Totals:</td>
						<td class="text-right text-primary"><?= format_num($tot_debit) ?></td>
						<td class="text-right text-success"><?= format_num($tot_credit) ?></td>
						<td class="text-right text-navy h6 mb-0"><?= format_num($running_bal) ?></td>
					</tr>
				</tfoot>
			</table>
		</div>
            </div>
            <?php endwhile; ?>
        </div>
	</div>
</div>

<script>
	$(document).ready(function(){
		$('.select2').select2({ width: '100%' });

        $('#filter').submit(function(e){
            e.preventDefault();
            location.href="./?page=reports/general_ledger&"+$(this).serialize();
        });

        $('#print').click(function(){
            start_loader();
            var _h = $('head').clone();
            var _p = $('#outprint').clone();
            var el = $('<div>');
            _h.find('title').text('General Ledger Report')
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