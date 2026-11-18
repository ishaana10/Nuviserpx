<?php
require_once(dirname(__DIR__, 2) . '/config.php');
require_once __DIR__ . '/_lib.php';
$tid = (int)$_settings->active_tenant_id();
$t = $conn->query("SELECT t.*, p.name plan_name, p.price plan_price, p.billing_cycle, p.max_users, p.max_invoices, p.features, p.description plan_desc
                   FROM tenants t LEFT JOIN tenant_plans p ON p.id = t.plan_id WHERE t.id = {$tid}");
$tn = $t ? $t->fetch_assoc() : null;
if (!$tn) { echo '<div class="alert alert-warning">Company not found.</div>'; return; }
$rows = []; $billed = 0; $paid = 0; $pays = [];
foreach (nv_pb_svc()->rows($tid) as $r) {
    if ($r['status'] === 'void') continue;
    $rows[] = $r; $billed += (float)$r['total']; $paid += (float)$r['paid_amount'];
    foreach (nv_pb_svc()->receipts((int)$r['owner_invoice_id']) as $pp) { $pp['invoice_no'] = $r['invoice_no']; $pays[] = $pp; }
}
usort($pays, fn($a, $b) => strcmp($b['pay_date'], $a['pay_date']));
$status = (string)($tn['plan_status'] ?: 'active');
$sc = ['active' => 'success', 'trial' => 'info', 'past_due' => 'warning', 'suspended' => 'danger', 'cancelled' => 'dark'][$status] ?? 'secondary';
$feat = json_decode((string)($tn['features'] ?? ''), true);
?>
<div class="row">
  <div class="col-lg-5">
    <div class="card card-outline card-primary">
      <div class="card-header"><h3 class="card-title">Your plan</h3></div>
      <div class="card-body">
        <?php if (empty($tn['plan_id'])): ?>
          <p class="text-muted mb-0">No plan is assigned to <b><?= htmlspecialchars($tn['name']) ?></b> yet. Contact Nuvis Technologies to choose one.</p>
        <?php else: ?>
          <h4 class="mb-1"><?= htmlspecialchars($tn['plan_name']) ?> <span class="badge badge-<?= $sc ?> align-middle" style="font-size:.5em"><?= ucfirst(str_replace('_', ' ', $status)) ?></span></h4>
          <?php if ($tn['plan_desc']): ?><p class="text-muted"><?= htmlspecialchars($tn['plan_desc']) ?></p><?php endif; ?>
          <table class="table table-sm table-borderless mb-0">
            <tr><th width="40%">Price</th><td><?= nv_pb_money($tn['plan_price']) ?> / <?= htmlspecialchars(str_replace('_', ' ', $tn['billing_cycle'])) ?></td></tr>
            <tr><th>Started</th><td><?= $tn['plan_started_at'] ? date('d M Y', strtotime($tn['plan_started_at'])) : '-' ?></td></tr>
            <tr><th>Renews / expires</th><td><?= $tn['plan_expires_at'] ? date('d M Y', strtotime($tn['plan_expires_at'])) : 'No expiry date' ?></td></tr>
            <tr><th>Users</th><td><?= (int)$tn['max_users'] > 0 ? (int)$tn['max_users'] : 'Unlimited' ?></td></tr>
            <tr><th>Invoices / month</th><td><?= (int)$tn['max_invoices'] > 0 ? (int)$tn['max_invoices'] : 'Unlimited' ?></td></tr>
          </table>
          <?php if (is_array($feat) && $feat): ?>
            <hr><ul class="pl-3 mb-0"><?php foreach ($feat as $f): ?><li><?= htmlspecialchars(is_string($f) ? $f : json_encode($f)) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between"><span>Total billed</span><b><?= nv_pb_money($billed) ?></b></div>
        <div class="d-flex justify-content-between"><span>Paid</span><b class="text-success"><?= nv_pb_money($paid) ?></b></div>
        <div class="d-flex justify-content-between border-top mt-2 pt-2"><span>Outstanding</span><b class="<?= $billed - $paid > 0.004 ? 'text-danger' : '' ?>"><?= nv_pb_money($billed - $paid) ?></b></div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card card-outline card-info">
      <div class="card-header"><h3 class="card-title">Invoices from Nuvis Technologies</h3></div>
      <div class="card-body p-0">
        <table class="table table-sm table-striped mb-0">
          <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="text-right">Total</th><th class="text-right">Balance</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['invoice_no']) ?></td>
              <td><?= date('d M Y', strtotime($r['issue_date'])) ?></td>
              <td><?= $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '-' ?></td>
              <td class="text-right"><?= number_format($r['total'], 2) ?></td>
              <td class="text-right"><?= number_format($r['total'] - $r['paid_amount'], 2) ?></td>
              <td><?= nv_pb_badge($r) ?></td>
              <td class="text-right"><a class="btn btn-xs btn-outline-primary" target="_blank" href="<?= base_url ?>admin/?page=billing/invoice&id=<?= (int)$r['id'] ?>"><i class="fa fa-eye"></i> View / print</a></td>
            </tr>
          <?php endforeach; if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No invoices yet. When Nuvis Technologies bills you, they will appear here.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card card-outline card-success">
      <div class="card-header"><h3 class="card-title">Payments received</h3></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Invoice</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th></tr></thead>
          <tbody>
          <?php $n = 0; foreach ($pays as $p): $n++; ?>
            <tr><td><?= date('d M Y', strtotime($p['pay_date'])) ?></td><td><?= htmlspecialchars($p['invoice_no']) ?></td><td><?= htmlspecialchars((string)$p['method']) ?></td><td><?= htmlspecialchars((string)$p['reference']) ?></td><td class="text-right"><?= number_format($p['amount'], 2) ?></td></tr>
          <?php endforeach; if (!$n): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
