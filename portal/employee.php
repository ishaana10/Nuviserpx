<?php
require_once('../config.php');
use App\Services\EmployeePortalService;

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['employee_portal']);
    header('Location: ' . base_url . 'portal/employee_login.php');
    exit;
}
if (empty($_SESSION['employee_portal']['employee_id'])) {
    header('Location: ' . base_url . 'portal/employee_login.php');
    exit;
}
// Employee and company come from the session only, never from the request.
$tenantId = (int)$_SESSION['employee_portal']['tenant_id'];
$employeeId = (int)$_SESSION['employee_portal']['employee_id'];
$svc = new EmployeePortalService($conn, $_settings);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$m = fn($v) => number_format((float)$v, 2);

$slip = null;
if (isset($_GET['id'])) {
    $slip = $svc->payslip($tenantId, $employeeId, (int)$_GET['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));
}
$company = '';
$cq = $conn->prepare("SELECT meta_value FROM system_info WHERE meta_field = 'name' LIMIT 1");
if ($cq) { $cq->execute(); $company = (string)($cq->get_result()->fetch_assoc()['meta_value'] ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition layout-top-nav">
<div class="wrapper">
  <nav class="main-header navbar navbar-expand navbar-white navbar-light d-print-none">
    <div class="container">
      <span class="navbar-brand font-weight-bold"><?php echo $h($company) ?> &middot; My Payslips</span>
      <ul class="navbar-nav ml-auto"><li class="nav-item"><a class="nav-link" href="?action=logout"><i class="fa fa-sign-out-alt"></i> Sign out</a></li></ul>
    </div>
  </nav>
  <div class="content-wrapper"><div class="content pt-3"><div class="container">
<?php if ($slip): ?>
    <div class="d-print-none mb-2">
      <a href="employee.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> All payslips</a>
      <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
    </div>
    <div class="card"><div class="card-body">
      <h4 class="mb-0"><?php echo $h($company) ?></h4>
      <p class="text-muted">Payslip &middot; <?php echo $h($slip['salary_month']) ?><?php echo !empty($slip['pay_date']) ? ' &middot; paid ' . $h(date('M d, Y', strtotime($slip['pay_date']))) : '' ?></p>
      <p class="mb-3"><b><?php echo $h($slip['emp_name']) ?></b> (<?php echo $h($slip['employee_code']) ?>)<br><small class="text-muted"><?php echo $h($slip['designation']) ?> <?php echo $h($slip['department']) ?></small></p>
      <div class="row">
        <div class="col-md-6"><h6>Earnings</h6><table class="table table-sm">
          <tr><td>Basic salary</td><td class="text-right"><?php echo $m($slip['basic_salary']) ?></td></tr>
          <tr><td>Overtime</td><td class="text-right"><?php echo $m($slip['overtime']) ?></td></tr>
          <tr><td>Allowances</td><td class="text-right"><?php echo $m($slip['allowances']) ?></td></tr>
          <?php foreach ([['retro_pay','Retroactive pay'],['other_earnings','Other earnings'],['leave_payout','Leave payout'],['redundancy_pay','Redundancy / severance']] as [$k,$l]): if ((float)($slip[$k] ?? 0) != 0): ?>
          <tr><td><?php echo $h($l) ?></td><td class="text-right"><?php echo $m($slip[$k]) ?></td></tr>
          <?php endif; endforeach; ?>
          <?php foreach ($slip['components'] as $c): if ($c['type'] !== 'earning') continue; ?>
          <tr><td><?php echo $h($c['name']) ?></td><td class="text-right"><?php echo $m($c['amount']) ?></td></tr>
          <?php endforeach; ?>
          <?php if ((float)($slip['unpaid_deduction'] ?? 0) > 0): ?>
          <tr><td>Unpaid days (<?php echo $h(rtrim(rtrim(number_format((float)$slip['unpaid_days'], 2), '0'), '.')) ?>)</td><td class="text-right text-danger">-<?php echo $m($slip['unpaid_deduction']) ?></td></tr>
          <?php endif; ?>
          <tr class="font-weight-bold"><td>Gross pay</td><td class="text-right"><?php echo $m($slip['gross_salary']) ?></td></tr>
        </table></div>
        <div class="col-md-6"><h6>Deductions</h6><table class="table table-sm">
          <tr><td>Employee pension contribution</td><td class="text-right"><?php echo $m($slip['employee_fnpf']) ?></td></tr>
          <tr><td>Income tax</td><td class="text-right"><?php echo $m($slip['paye_tax']) ?></td></tr>
          <?php if ((float)($slip['srt_tax'] ?? 0) != 0): ?><tr><td>Social responsibility tax</td><td class="text-right"><?php echo $m($slip['srt_tax']) ?></td></tr><?php endif; ?>
          <?php $other = max(0, (float)$slip['deductions'] - (float)($slip['component_deductions'] ?? 0)); if ($other > 0): ?>
          <tr><td>Other deductions</td><td class="text-right"><?php echo $m($other) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($slip['components'] as $c): if ($c['type'] !== 'deduction') continue; ?>
          <tr><td><?php echo $h($c['name']) ?></td><td class="text-right"><?php echo $m($c['amount']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
      </div>
      <div class="bg-dark text-white p-3 d-flex justify-content-between"><b>Net pay</b><b><?php echo $m($slip['net_salary']) ?></b></div>
    </div></div>
<?php else: ?>
    <?php if (isset($_GET['id'])): ?><div class="alert alert-warning">That payslip was not found.</div><?php endif; ?>
    <div class="card"><div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead><tr><th>Month</th><th>Pay date</th><th class="text-right">Gross</th><th class="text-right">Net</th><th></th></tr></thead>
        <tbody>
        <?php $rows = $svc->payslips($tenantId, $employeeId); foreach ($rows as $r): ?>
          <tr>
            <td><?php echo $h($r['salary_month']) ?></td>
            <td><?php echo !empty($r['pay_date']) ? $h(date('M d, Y', strtotime($r['pay_date']))) : '&mdash;' ?></td>
            <td class="text-right"><?php echo $m($r['gross_salary']) ?></td>
            <td class="text-right font-weight-bold"><?php echo $m($r['net_salary']) ?></td>
            <td class="text-right"><a class="btn btn-sm btn-primary" href="?id=<?php echo (int)$r['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No payslips yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div></div>
<?php endif; ?>
  </div></div></div>
</div>
<script src="<?php echo base_url ?>plugins/jquery/jquery.min.js"></script>
<script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
