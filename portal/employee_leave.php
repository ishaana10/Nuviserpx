<?php
require_once(__DIR__ . '/_employee_layout.php');
if (!$leaveOn) {
    header('Location: ' . base_url . 'portal/employee.php');
    exit;
}
$balances = $leave->balances();
$history = $leave->history(50);
$cal = $leave->calendar();
$n = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
emp_header('My Leave', 'leave');
?>
<h1 class="pp-h1">My Leave</h1>
<p class="pp-sub">Your requests go to your approver<?php echo !empty($me['approver_name']) ? ' (' . $h($me['approver_name']) . ')' : '' ?> and you are emailed when one is decided.</p>

<ul class="pp-tabs" id="ppTabs" role="tablist">
  <li><a href="#balances" data-tab="balances">Balances</a></li>
  <li><a href="#apply" data-tab="apply">New Request</a></li>
  <li><a href="#requests" data-tab="requests">View Requests<?php echo $history ? ' <span class="pp-count">' . count($history) . '</span>' : '' ?></a></li>
  <li><a href="#calendar" data-tab="calendar">Calendar</a></li>
</ul>

<section data-pane="balances">
<?php if ($balances): ?>
  <div class="pp-grid c3">
  <?php foreach ($balances as $b): ?>
    <div class="pp-card"><div class="pp-card-h"><?php echo $h($b['label']) ?></div>
      <div class="pp-card-b"><div class="pp-big"><?php echo $h($n($b['balance'])) ?> <small>days left</small></div>
      <div class="pp-muted mt-1">Earned <?php echo $h($n($b['accrued'])) ?> · taken <?php echo $h($n($b['taken'])) ?> this year</div></div></div>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="pp-card"><div class="pp-card-b pp-muted">No leave balances are tracked for you yet. You can still request leave; your approver will decide it.</div></div>
<?php endif; ?>
</section>

<section data-pane="apply">
<div class="pp-two">
  <div class="pp-card"><div class="pp-card-b">
  <form id="leaveFrm" novalidate enctype="multipart/form-data">
    <div class="form-group"><label for="df">Length of leave <span class="text-danger">*</span></label>
      <select class="form-control" id="df" name="day_fraction"><option value="1">1 or more full days</option><option value="0.5">Half day</option></select></div>
    <div class="form-group"><label for="lt">Leave type <span class="text-danger">*</span></label>
      <select class="form-control" id="lt" name="leave_type" required>
        <?php foreach (\App\Services\PortalLeaveService::TYPE_LABELS as $k => $l): ?><option value="<?php echo $h($k) ?>"><?php echo $h($l) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-row">
      <div class="form-group col-6"><label for="sd">Start date <span class="text-danger">*</span></label><input type="date" class="form-control" id="sd" name="start_date" required min="<?php echo $h(date('Y-m-d', strtotime('-60 days'))) ?>"></div>
      <div class="form-group col-6"><label for="ed">End date <span class="text-danger">*</span></label><input type="date" class="form-control" id="ed" name="end_date" required></div>
    </div>
    <div class="pp-check" id="ppCheck" aria-live="polite">
      <div><span class="pp-muted">Units required</span><b id="ppDays">0</b> <span class="pp-muted">days</span></div>
      <div id="ppBalRow" style="display:none"><span class="pp-muted">Balance</span><b id="ppBal">0</b> <span class="pp-muted">→ after this request</span> <b id="ppAfter">0</b></div>
      <div id="ppMsg" class="pp-msg"></div>
    </div>
    <div class="form-group"><label for="rs">Additional info</label><textarea class="form-control" id="rs" name="reason" rows="3" maxlength="500"></textarea></div>
    <div class="form-group" id="docBox">
      <label for="doc" id="docLbl">Attachment <span class="text-muted">(optional)</span></label>
      <input type="file" class="form-control-file" id="doc" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
      <small class="form-text text-muted" id="docHint">For sick leave, attach your medical certificate. PDF, JPG or PNG, up to 2 MB.</small>
    </div>
    <button type="submit" class="btn btn-primary" id="leaveBtn" disabled><i class="fa fa-paper-plane"></i> Submit request</button>
    <span class="text-danger ml-2" id="leaveErr" role="alert"></span>
  </form>
  </div></div>
  <div class="pp-card"><div class="pp-card-b"><div class="pp-calbox" id="calApply"></div><?php /* calendar for picking dates */ ?></div></div>
</div>
</section>

<section data-pane="requests">
<div class="pp-card">
<?php if ($history): ?>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Type</th><th>Dates</th><th class="text-right">Days</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($history as $r): ?>
    <tr>
      <td><?php echo $h($r['type_label']) ?><?php echo !empty($r['reason']) ? '<div class="pp-muted">' . $h($r['reason']) . '</div>' : '' ?>
        <?php if (!empty($r['attachment_name'])): ?><div><a class="small" href="leave_doc.php?id=<?php echo (int)$r['id'] ?>" target="_blank" rel="noopener"><i class="fa fa-paperclip"></i> <?php echo $h($r['attachment_name']) ?></a></div><?php endif; ?></td>
      <td><?php echo $h(date('j M Y', strtotime($r['start_date']))) ?><?php echo $r['end_date'] !== $r['start_date'] ? ' – ' . $h(date('j M Y', strtotime($r['end_date']))) : '' ?></td>
      <td class="text-right"><?php echo $h($n($r['days'])) ?></td>
      <td><span class="pp-badge <?php echo $h($r['status']) ?>"><?php echo $h(ucfirst($r['status'])) ?></span></td>
      <td class="text-right"><?php if ($r['status'] === 'pending'): ?><button type="button" class="btn btn-sm btn-outline-danger cancel-leave" data-id="<?php echo (int)$r['id'] ?>">Cancel</button><?php endif; ?></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="pp-empty">No leave requests yet.</div><?php endif; ?>
</div>
</section>

<section data-pane="calendar">
  <div class="pp-card"><div class="pp-card-b"><div class="pp-calbox" id="calView"></div></div></div>
</section>

<?php
$data = json_encode(['cal' => $cal], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
emp_footer("<script>window.PP_CAL = {$data}.cal;</script><script src=\"" . base_url . "dist/js/nuvis-portal-leave.js?v=" . date('Ymd') . "\"></script>");
