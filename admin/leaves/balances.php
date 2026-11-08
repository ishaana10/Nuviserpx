<?php
use App\Services\LeaveBalanceService;

$svc = new LeaveBalanceService($conn, $_settings);
$scope = $_settings->employee_scope('leaves');          // null = HR / manager, otherwise own employee only
$can_manage = $_settings->can_access('leaves_edit');
$asOf = (isset($_GET['as_of']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['as_of']) && strtotime($_GET['as_of'])) ? $_GET['as_of'] : date('Y-m-d');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n = fn($v) => number_format((float)$v, 2);
$policies = $svc->policies();
$employees = $svc->employees($scope === null ? null : (int)$scope);
?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">Leave Balances</h3>
		<div class="card-tools">
			<form class="form-inline" method="get" action="">
				<input type="hidden" name="page" value="leaves/balances">
				<label class="mr-2 text-sm">As of</label>
				<input type="date" name="as_of" value="<?= $h($asOf) ?>" class="form-control form-control-sm mr-2">
				<button class="btn btn-sm btn-default">Show</button>
				<a href="?page=leaves" class="btn btn-sm btn-default ml-2">Leave requests</a>
			</form>
		</div>
	</div>
	<div class="card-body">
		<?php if (!$policies): ?>
			<div class="alert alert-info">No leave type is tracked yet.<?= $can_manage ? ' Set an annual entitlement below and balances will appear here.' : ' Ask HR to set up leave entitlements.' ?></div>
		<?php else: ?>
		<div class="table-responsive">
		<table class="table table-bordered table-sm" id="bal-list">
			<thead class="bg-light"><tr><th>Employee</th><?php foreach ($policies as $type => $p): ?><th class="text-right"><?= $h(LeaveBalanceService::TYPES[$type]) ?><br><small class="text-muted font-weight-normal">carried + accrued + adj - taken</small></th><?php endforeach; ?></tr></thead>
			<tbody>
			<?php foreach ($employees as $e): $bals = $svc->forEmployee((int)$e['id'], $asOf); ?>
				<tr>
					<td><b><?= $h($e['code']) ?></b> <?= $h($e['name']) ?></td>
					<?php foreach ($policies as $type => $p): $b = $bals[$type] ?? null; ?>
					<td class="text-right">
						<?php if ($b): ?>
						<b class="<?= $b['balance'] < 0 ? 'text-danger' : '' ?>"><?= $n($b['balance']) ?></b>
						<small class="d-block text-muted"><?= $n($b['opening']) ?> + <?= $n($b['accrued']) ?> <?= $b['adjusted'] != 0 ? ($b['adjusted'] > 0 ? '+ ' : '- ') . $n(abs($b['adjusted'])) : '' ?> - <?= $n($b['taken']) ?><?= $b['lapsed'] > 0 ? ' (lapsed ' . $n($b['lapsed']) . ')' : '' ?></small>
						<?php endif; ?>
					</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; if (!$employees): ?>
				<tr><td colspan="<?= count($policies) + 1 ?>" class="text-center text-muted">No employees.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<small class="text-muted">Leave years are calendar years. Days accrue daily from the joining date (set it on the employee record) and stop on the last day of employment. Only approved leave counts as taken.</small>
		<?php endif; ?>
	</div>
</div>

<?php if ($can_manage): ?>
<div class="row">
	<div class="col-lg-7">
		<div class="card card-outline card-info">
			<div class="card-header"><h3 class="card-title">Leave policies</h3></div>
			<div class="card-body p-0">
				<table class="table table-sm mb-0">
					<thead class="bg-light"><tr><th>Type</th><th>Tracked</th><th>Days / year</th><th>Carry-over max</th><th>Max balance</th><th>Allow negative</th><th>Paid on exit</th><th></th></tr></thead>
					<tbody>
					<?php foreach (LeaveBalanceService::TYPES as $type => $label): $p = $policies[$type] ?? null; ?>
					<tr class="policy-row" data-type="<?= $h($type) ?>">
						<td><?= $h($label) ?></td>
						<td><input type="checkbox" class="f-tracked" <?= $p ? 'checked' : '' ?>></td>
						<td><input type="number" step="0.01" min="0" max="366" class="form-control form-control-sm f-annual" style="width:85px" value="<?= $p ? $h($p['annual_days']) : '' ?>"></td>
						<td><input type="number" step="0.01" min="0" class="form-control form-control-sm f-carry" style="width:85px" placeholder="all" value="<?= $p && $p['carry_over_max'] !== null ? $h($p['carry_over_max']) : '' ?>"></td>
						<td><input type="number" step="0.01" min="0" class="form-control form-control-sm f-max" style="width:85px" placeholder="none" value="<?= $p && $p['max_balance'] !== null ? $h($p['max_balance']) : '' ?>"></td>
						<td><input type="checkbox" class="f-neg" <?= $p && $p['allow_negative'] ? 'checked' : '' ?>></td>
						<td><input type="checkbox" class="f-pay" <?= $p && $p['payable_on_exit'] ? 'checked' : '' ?>></td>
						<td><button class="btn btn-sm btn-primary save-policy">Save</button></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="card-footer text-sm text-muted">Carry-over: blank carries every unused day into next year, 0 carries none. Unpaid leave is never limited.</div>
		</div>
	</div>
	<div class="col-lg-5">
		<div class="card card-outline card-warning">
			<div class="card-header"><h3 class="card-title">Adjust a balance</h3></div>
			<div class="card-body">
				<form id="adj-form">
					<div class="form-group"><label>Employee</label><select name="employee_id" class="form-control form-control-sm" required>
						<option value="">Select</option>
						<?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= $h($e['code'] . ' - ' . $e['name']) ?></option><?php endforeach; ?>
					</select></div>
					<div class="form-group"><label>Leave type</label><select name="leave_type" class="form-control form-control-sm" required>
						<?php foreach (LeaveBalanceService::TYPES as $type => $label): ?><option value="<?= $h($type) ?>"><?= $h($label) ?></option><?php endforeach; ?>
					</select></div>
					<div class="row">
						<div class="col-6 form-group"><label>Days (+ add / - remove)</label><input type="number" step="0.01" name="days" class="form-control form-control-sm" required></div>
						<div class="col-6 form-group"><label>Effective date</label><input type="date" name="effective_date" value="<?= $h(date('Y-m-d')) ?>" class="form-control form-control-sm" required></div>
					</div>
					<div class="form-group"><label>Reason</label><input type="text" name="reason" maxlength="255" class="form-control form-control-sm" placeholder="e.g. Opening balance from previous system" required></div>
					<button class="btn btn-warning btn-sm">Save adjustment</button>
				</form>
			</div>
		</div>
	</div>
</div>
<script>
$(function(){
	$('.save-policy').click(function(){
		var r = $(this).closest('tr');
		start_loader();
		$.post(_base_url_+"classes/Master.php?f=save_leave_policy", {
			leave_type: r.data('type'), tracked: r.find('.f-tracked').is(':checked') ? 1 : 0, annual_days: r.find('.f-annual').val(),
			carry_over_max: r.find('.f-carry').val(), max_balance: r.find('.f-max').val(),
			allow_negative: r.find('.f-neg').is(':checked') ? 1 : 0, payable_on_exit: r.find('.f-pay').is(':checked') ? 1 : 0
		}, function(resp){
			end_loader();
			if(resp.status == 'success'){ alert_toast(resp.msg, 'success'); setTimeout(function(){ location.reload(); }, 600); }
			else { alert_toast(resp.msg || 'An error occurred', 'error'); }
		}, 'json').fail(function(){ end_loader(); alert_toast('An error occurred', 'error'); });
	});
	$('#adj-form').submit(function(e){
		e.preventDefault();
		start_loader();
		$.post(_base_url_+"classes/Master.php?f=save_leave_adjustment", $(this).serialize(), function(resp){
			end_loader();
			if(resp.status == 'success'){ alert_toast(resp.msg, 'success'); setTimeout(function(){ location.reload(); }, 600); }
			else { alert_toast(resp.msg || 'An error occurred', 'error'); }
		}, 'json').fail(function(){ end_loader(); alert_toast('An error occurred', 'error'); });
	});
});
</script>
<?php endif; ?>
