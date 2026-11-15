<?php
require_once(__DIR__ . '/_employee_layout.php');

// Old payslip links (employee.php?id=N) now live on the payslips page.
if (isset($_GET['id'])) {
    header('Location: ' . base_url . 'portal/employee_payslips.php?id=' . (int)$_GET['id']);
    exit;
}
$balances = $leaveOn ? $leave->balances() : [];
$pending = 0;
$recent = $leaveOn ? $leave->history(5) : [];
foreach ($recent as $r) { if ($r['status'] === 'pending') { $pending++; } }
$latest = $svc->payslips($tenantId, $employeeId)[0] ?? null;
$hour = (int)date('G');
$hello = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

emp_header('Home', 'home');
?>
<h1 class="pp-h1"><?php echo $h($hello) ?>, <?php echo $h($me['firstname'] ?? '') ?></h1>
<p class="pp-sub"><?php echo $h(trim(($me['designation'] ?? '') . (!empty($me['department']) ? ' · ' . $me['department'] : ''))) ?></p>

<div class="pp-grid c3">
<?php if ($leaveOn): ?>
  <?php if ($balances): foreach ($balances as $b): ?>
  <div class="pp-card"><div class="pp-card-h"><span><?php echo $h($b['label']) ?></span><i class="fa fa-calendar-check text-muted"></i></div>
    <div class="pp-card-b"><div class="pp-big"><?php echo $h(rtrim(rtrim(number_format($b['balance'], 2), '0'), '.')) ?> <small>days left</small></div>
    <div class="pp-muted mt-1">Taken <?php echo $h(rtrim(rtrim(number_format($b['taken'], 2), '0'), '.')) ?> this year</div></div></div>
  <?php endforeach; else: ?>
  <div class="pp-card"><div class="pp-card-h">Leave</div><div class="pp-card-b pp-muted">Your leave balances will show here once your company sets up leave entitlements.</div></div>
  <?php endif; ?>
<?php endif; ?>
  <div class="pp-card"><div class="pp-card-h"><span>Latest payslip</span><i class="fa fa-file-invoice-dollar text-muted"></i></div>
    <div class="pp-card-b">
    <?php if ($latest): ?>
      <div class="pp-big"><?php echo $h($m($latest['net_salary'])) ?> <small>net</small></div>
      <div class="pp-muted mt-1"><?php echo $h($latest['salary_month']) ?></div>
      <a class="btn btn-primary btn-sm mt-3" href="employee_payslips.php?id=<?php echo (int)$latest['id'] ?>">View payslip</a>
    <?php else: ?><span class="pp-muted">No payslips yet.</span><?php endif; ?>
    </div></div>
</div>

<?php if ($leaveOn): ?>
<div class="pp-card"><div class="pp-card-h"><span>Recent leave requests</span><a class="btn btn-primary btn-sm" href="employee_leave.php#apply">Apply for leave</a></div>
  <div class="pp-card-b">
  <?php if ($recent): ?><ul class="pp-list">
    <?php foreach ($recent as $r): ?>
    <li><span><?php echo $h($r['type_label']) ?> · <?php echo $h(date('j M', strtotime($r['start_date']))) ?><?php echo $r['end_date'] !== $r['start_date'] ? ' – ' . $h(date('j M', strtotime($r['end_date']))) : '' ?></span><span class="pp-badge <?php echo $h($r['status']) ?>"><?php echo $h(ucfirst($r['status'])) ?></span></li>
    <?php endforeach; ?></ul>
  <?php else: ?><div class="pp-empty">You haven't requested any leave yet.</div><?php endif; ?>
  </div></div>
<?php endif; ?>
<?php emp_footer(); ?>
