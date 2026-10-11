<?php
function format_num($number){
	return number_format($number, 2);
}

$tenant_id = $_settings->active_tenant_id();
$as_of = isset($_GET['as_of']) ? $conn->real_escape_string($_GET['as_of']) : date("Y-m-d");

$qry = $conn->query("
    SELECT i.*, p.name AS party_name, p.party_code, p.phone, p.email
    FROM `invoice_list` i
    INNER JOIN `party_list` p ON i.party_id = p.id
    WHERE i.tenant_id = '{$tenant_id}' AND i.status != 'cancelled' AND i.balance > 0 AND date(i.invoice_date) <= '{$as_of}'
    ORDER BY p.name ASC, i.invoice_date ASC
");

$customer_summary = [];
$tot_current = 0;
$tot_30 = 0;
$tot_60 = 0;
$tot_90 = 0;
$tot_over_90 = 0;
$tot_balance = 0;

while($row = $qry->fetch_assoc()){
    $party_id = $row['party_id'];
    $due_date = !empty($row['due_date']) ? $row['due_date'] : $row['invoice_date'];

    // Days overdue
    $days_overdue = (strtotime($as_of) - strtotime($due_date)) / 86400;
    $balance = (float)$row['balance'];

    $c_curr = 0; $c_30 = 0; $c_60 = 0; $c_90 = 0; $c_over_90 = 0;

    if($days_overdue <= 0){
        $c_curr = $balance;
        $tot_current += $balance;
    } elseif($days_overdue <= 30){
        $c_30 = $balance;
        $tot_30 += $balance;
    } elseif($days_overdue <= 60){
        $c_60 = $balance;
        $tot_60 += $balance;
    } elseif($days_overdue <= 90){
        $c_90 = $balance;
        $tot_90 += $balance;
    } else {
        $c_over_90 = $balance;
        $tot_over_90 += $balance;
    }

    $tot_balance += $balance;

    if(!isset($customer_summary[$party_id])){
        $customer_summary[$party_id] = [
            'party_code' => $row['party_code'],
            'party_name' => $row['party_name'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'current' => 0, 'day_30' => 0, 'day_60' => 0, 'day_90' => 0, 'over_90' => 0, 'total' => 0,
            'invoices' => []
        ];
    }

    $customer_summary[$party_id]['current'] += $c_curr;
    $customer_summary[$party_id]['day_30'] += $c_30;
    $customer_summary[$party_id]['day_60'] += $c_60;
    $customer_summary[$party_id]['day_90'] += $c_90;
    $customer_summary[$party_id]['over_90'] += $c_over_90;
    $customer_summary[$party_id]['total'] += $balance;

    $customer_summary[$party_id]['invoices'][] = [
        'invoice_no' => $row['invoice_no'],
        'invoice_date' => $row['invoice_date'],
        'due_date' => $row['due_date'],
        'total' => $row['total'],
        'balance' => $balance,
        'days_overdue' => max(0, floor($days_overdue))
    ];
}
?>

<div class="card card-outline card-warning">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-history text-warning"></i> Accounts Receivable Aging & Customer Ledger</h3>
	</div>
	<div class="card-body">
        <div class="callout border-warning shadow rounded-0">
            <form action="" id="filter">
            <div class="row align-items-end">
                <div class="col-md-6 form-group">
                    <label for="as_of" class="control-label">Aging As of Date</label>
                    <input type="date" id="as_of" name="as_of" value="<?= $as_of ?>" class="form-control form-control-sm rounded-0">
                </div>
                <div class="col-md-6 form-group">
                    <button class="btn btn-warning btn-flat btn-sm"><i class="fa fa-filter"></i> Calculate Aging</button>
			        <button class="btn btn-default border btn-flat btn-sm" id="print" type="button"><i class="fa fa-print"></i> Print Report</button>
                </div>
            </div>
            </form>
        </div>

        <div class="container-fluid" id="outprint">
            <div class="text-center mb-4">
		<h3 class="font-weight-bold m-0"><?= $_settings->info('name') ?></h3>
		<h4 class="font-weight-bold text-warning">ACCOUNTS RECEIVABLE AGING SUMMARY</h4>
		<p class="m-0 text-muted small">As of <?= date("d F Y" , strtotime($as_of)) ?></p>
            </div>
            <hr>

            <div class="table-responsive">
		<table class="table table-sm table-bordered table-striped">
			<thead class="bg-navy text-white text-center">
				<tr>
					<th>Customer</th>
					<th width="12%">Current</th>
					<th width="12%">1 - 30 Days</th>
					<th width="12%">31 - 60 Days</th>
					<th width="12%">61 - 90 Days</th>
					<th width="12%">> 90 Days</th>
					<th width="14%">Total Outstanding</th>
				</tr>
			</thead>
			<tbody>
				<?php if(!empty($customer_summary)): foreach($customer_summary as $cs): ?>
				<tr>
					<td>
						<b><?= htmlspecialchars($cs['party_name']) ?></b>
						<small class="text-muted d-block"><?= $cs['party_code'] ?> | <?= $cs['email'] ?></small>
					</td>
					<td class="text-right">$<?= format_num($cs['current']) ?></td>
					<td class="text-right text-warning">$<?= format_num($cs['day_30']) ?></td>
					<td class="text-right text-orange">$<?= format_num($cs['day_60']) ?></td>
					<td class="text-right text-danger">$<?= format_num($cs['day_90']) ?></td>
					<td class="text-right font-weight-bold text-danger">$<?= format_num($cs['over_90']) ?></td>
					<td class="text-right font-weight-bold">$<?= format_num($cs['total']) ?></td>
				</tr>
				<?php endforeach; else: ?>
				<tr><td colspan="7" class="text-center text-muted">No outstanding accounts receivable found as of this date.</td></tr>
				<?php endif; ?>
			</tbody>
			<tfoot class="bg-light font-weight-bold h6">
				<tr>
					<td class="text-right">TOTAL AR AGING:</td>
					<td class="text-right">$<?= format_num($tot_current) ?></td>
					<td class="text-right text-warning">$<?= format_num($tot_30) ?></td>
					<td class="text-right text-orange">$<?= format_num($tot_60) ?></td>
					<td class="text-right text-danger">$<?= format_num($tot_90) ?></td>
					<td class="text-right text-danger">$<?= format_num($tot_over_90) ?></td>
					<td class="text-right text-primary h5 font-weight-bold">$<?= format_num($tot_balance) ?></td>
				</tr>
			</tfoot>
		</table>
            </div>
        </div>
	</div>
</div>

<script>
	$(document).ready(function(){
        $('#filter').submit(function(e){
            e.preventDefault();
            location.href="./?page=reports/ar_aging&"+$(this).serialize();
        });

        $('#print').click(function(){
            start_loader();
            var _h = $('head').clone();
            var _p = $('#outprint').clone();
            var el = $('<div>');
            _h.find('title').text('Accounts Receivable Aging Report')
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