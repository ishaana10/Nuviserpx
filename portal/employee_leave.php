<?php
require_once(__DIR__ . '/_employee_layout.php');
if (!$leaveOn) {
    header('Location: ' . base_url . 'portal/employee.php');
    exit;
}
$balances = $leave->balances();
$history = $leave->history(50);
$n = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
emp_header('My Leave', 'leave');
?>
<h1 class="pp-h1">My Leave</h1>
<p class="pp-sub">Your balances and requests. A request goes to your approver<?php echo !empty($me['approver_name']) ? ' (' . $h($me['approver_name']) . ')' : '' ?> and you are emailed when it is decided.</p>

<?php if ($balances): ?>
<div class="pp-grid c3">
<?php foreach ($balances as $b): ?>
  <div class="pp-card"><div class="pp-card-h"><?php echo $h($b['label']) ?></div>
    <div class="pp-card-b"><div class="pp-big"><?php echo $h($n($b['balance'])) ?> <small>days left</small></div>
    <div class="pp-muted mt-1">Earned <?php echo $h($n($b['accrued'])) ?> · taken <?php echo $h($n($b['taken'])) ?> this year</div></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="pp-card mb-3" id="apply"><div class="pp-card-h">Apply for leave</div><div class="pp-card-b">
  <form id="leaveFrm" novalidate>
    <div class="form-row">
      <div class="form-group col-md-4"><label for="lt">Leave type</label>
        <select class="form-control" id="lt" name="leave_type" required>
          <?php foreach (\App\Services\PortalLeaveService::TYPE_LABELS as $k => $l): ?><option value="<?php echo $h($k) ?>"><?php echo $h($l) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-group col-6 col-md-3"><label for="sd">From</label><input type="date" class="form-control" id="sd" name="start_date" required min="<?php echo $h(date('Y-m-d', strtotime('-60 days'))) ?>"></div>
      <div class="form-group col-6 col-md-3"><label for="ed">To</label><input type="date" class="form-control" id="ed" name="end_date" required></div>
      <div class="form-group col-md-2"><label for="df">Length</label>
        <select class="form-control" id="df" name="day_fraction"><option value="1">Full day(s)</option><option value="0.5">Half day</option></select></div>
    </div>
    <div class="form-group"><label for="rs">Reason <span class="text-muted">(optional)</span></label><textarea class="form-control" id="rs" name="reason" rows="2" maxlength="500"></textarea></div>
    <button type="submit" class="btn btn-primary" id="leaveBtn"><i class="fa fa-paper-plane"></i> Submit request</button>
    <span class="text-danger ml-2" id="leaveErr" role="alert"></span>
  </form>
</div></div>

<div class="pp-card"><div class="pp-card-h">My requests</div>
<?php if ($history): ?>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Type</th><th>Dates</th><th class="text-right">Days</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($history as $r): ?>
    <tr>
      <td><?php echo $h($r['type_label']) ?><?php echo !empty($r['reason']) ? '<div class="pp-muted">' . $h($r['reason']) . '</div>' : '' ?></td>
      <td><?php echo $h(date('j M Y', strtotime($r['start_date']))) ?><?php echo $r['end_date'] !== $r['start_date'] ? ' – ' . $h(date('j M Y', strtotime($r['end_date']))) : '' ?></td>
      <td class="text-right"><?php echo $h($n($r['days'])) ?></td>
      <td><span class="pp-badge <?php echo $h($r['status']) ?>"><?php echo $h(ucfirst($r['status'])) ?></span></td>
      <td class="text-right"><?php if ($r['status'] === 'pending'): ?><button type="button" class="btn btn-sm btn-outline-danger cancel-leave" data-id="<?php echo (int)$r['id'] ?>">Cancel</button><?php endif; ?></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="pp-empty">No leave requests yet.</div><?php endif; ?>
</div>
<?php
emp_footer("<script>
(function(){
  function post(fn, data, done){
    \$.ajax({url: _base_url_ + 'classes/Master.php?f=' + fn, method: 'POST', data: data, dataType: 'json',
      error: function(){ done({status: 'failed', msg: 'Something went wrong. Please try again.'}); }, success: done });
  }
  \$('#sd').on('change', function(){ var e = \$('#ed'); if(!e.val() || e.val() < this.value){ e.val(this.value); } e.attr('min', this.value); });
  \$('#df').on('change', function(){ if(this.value === '0.5'){ \$('#ed').val(\$('#sd').val()); } });
  \$('#leaveFrm').on('submit', function(e){
    e.preventDefault();
    var \$b = \$('#leaveBtn').prop('disabled', true); \$('#leaveErr').text('');
    post('employee_portal_leave_apply', \$(this).serialize(), function(r){
      if(r.status === 'success'){ location.href = 'employee_leave.php?sent=1'; }
      else { \$('#leaveErr').text(r.msg || 'Could not submit.'); \$b.prop('disabled', false); }
    });
  });
  \$('.cancel-leave').on('click', function(){
    if(!confirm('Cancel this leave request?')) return;
    var \$b = \$(this).prop('disabled', true);
    post('employee_portal_leave_cancel', {id: \$b.data('id')}, function(r){
      if(r.status === 'success'){ location.reload(); } else { alert(r.msg || 'Could not cancel.'); \$b.prop('disabled', false); }
    });
  });
  if(location.search.indexOf('sent=1') > -1){ \$('<div class=\"alert alert-success\">Your request was sent to your approver.</div>').insertBefore('.pp-h1').delay(5000).fadeOut(); }
})();
</script>");
