<?php
require_once(__DIR__ . '/_employee_layout.php');
$slip = isset($_GET['id']) ? $svc->payslip($tenantId, $employeeId, (int)$_GET['id'], (string)($_SERVER['REMOTE_ADDR'] ?? '')) : null;
emp_header('My Payslips', 'payslips');
?>
<?php if ($slip): ?>
    <div class="d-print-none mb-2"><a href="employee_payslips.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> All payslips</a></div>
    <style>.pp-slip [data-dismiss="modal"] { display: none; }</style>
    <div class="pp-slip"><?php emp_render_payslip((int)$slip['id']); ?></div>
<?php else: ?>
    <h1 class="pp-h1">My Payslips</h1>
    <?php if (isset($_GET['id'])): ?><div class="alert alert-warning">That payslip was not found.</div><?php endif; ?>
    <div class="pp-card"><div class="table-responsive">
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
<?php emp_footer(); ?>
