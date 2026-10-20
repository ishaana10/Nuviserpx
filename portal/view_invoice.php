<?php
require_once('../config.php');

if (!isset($_SESSION['customer_portal']) && !isset($_SESSION['customer_id'])) {
    redirect('portal/login.php');
}

$id = intval($_GET['id'] ?? 0);
$party_id = $_SESSION['customer_portal']['party_id'] ?? $_SESSION['customer_party_id'] ?? $_SESSION['customer_client_id'] ?? 0;

$qry = $conn->query("SELECT i.*, p.party_code, p.name as customer_name, p.email as customer_email, p.phone as customer_phone, p.tin as customer_tin, p.address as customer_address FROM `invoice_list` i LEFT JOIN `party_list` p ON i.party_id = p.id WHERE i.id = '{$id}' AND i.party_id = '{$party_id}'");

if (!$qry || $qry->num_rows == 0) {
    die("Invoice not found or access denied.");
}

$inv = $qry->fetch_assoc();
$items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$id}' ORDER BY id ASC");

// System Info
$sys = [];
$sys_qry = $conn->query("SELECT meta_field, meta_value FROM `system_info`");
if ($sys_qry) {
    while ($r = $sys_qry->fetch_assoc()) {
        $sys[$r['meta_field']] = $r['meta_value'];
    }
}

$status_class = [
    'draft' => 'secondary',
    'unpaid' => 'danger',
    'partially_paid' => 'warning',
    'paid' => 'success',
    'cancelled' => 'dark'
][$inv['status']] ?? 'secondary';
?>
<!DOCTYPE html>
<html lang="en">
<?php require_once('../admin/inc/header.php') ?>
<style>
  body { background: #ffffff !important; }
  @media print {
    .no-print { display: none !important; }
    body { padding: 0 !important; background: #ffffff !important; }
    .container { max-width: 100% !important; width: 100% !important; padding: 0 !important; }
  }
</style>
<body onload="<?php echo isset($_GET['print']) ? 'window.print()' : '' ?>">
<div class="container py-4">
    <div class="no-print mb-3 text-right">
        <button onclick="window.print()" class="btn btn-primary btn-sm font-weight-bold"><i class="fas fa-print mr-1"></i> Print / Save as PDF</button>
        <button onclick="window.close()" class="btn btn-secondary btn-sm ml-1"><i class="fas fa-times mr-1"></i> Close Window</button>
    </div>

    <!-- Header Banner -->
    <div class="row border-bottom pb-3 mb-4 align-items-center">
      <div class="col-7 d-flex align-items-center">
        <?php if(!empty($_settings->info('logo'))): ?>
          <img src="<?php echo validate_image($_settings->info('logo')) ?>" alt="Logo" class="mr-3 rounded" style="max-height: 75px; width: auto; object-fit: contain;">
        <?php endif; ?>
        <div>
          <h3 class="font-weight-bold text-navy mb-1"><?php echo htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?></h3>
          <p class="mb-0 text-muted small"><?php echo htmlspecialchars($sys['address'] ?? 'Fiji Islands') ?></p>
          <p class="mb-0 text-muted small">
            <b>Email:</b> <?php echo htmlspecialchars($sys['email'] ?? '') ?> | <b>Phone:</b> <?php echo htmlspecialchars($sys['contact'] ?? '') ?>
          </p>
        </div>
      </div>
      <div class="col-5 text-right">
        <h2 class="font-weight-bold text-dark mb-0">TAX INVOICE</h2>
        <h4 class="font-weight-bold text-primary mb-1">#<?php echo htmlspecialchars($inv['invoice_no']) ?></h4>
        <p class="mb-1 text-muted small"><b>Invoice Date:</b> <?php echo date('d M Y', strtotime($inv['invoice_date'] ?? $inv['date_created'])) ?></p>
        <p class="mb-1 text-muted small"><b>Due Date:</b> <?php echo !empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></p>
        <span class="badge badge-<?php echo $status_class ?> px-3 py-1 font-weight-bold text-uppercase mt-1 border">
          <?php echo strtoupper(str_replace('_', ' ', $inv['status'])) ?>
        </span>
      </div>
    </div>

    <!-- Billed To Section -->
    <div class="row mb-4 p-3 rounded bg-light border">
      <div class="col-6">
        <h6 class="text-uppercase font-weight-bold text-navy mb-2">BILLED TO:</h6>
        <h5 class="font-weight-bold mb-1"><?php echo htmlspecialchars($inv['customer_name']) ?></h5>
        <p class="mb-1 text-muted small"><b>Code:</b> <?php echo htmlspecialchars($inv['party_code'] ?? 'CUST-' . $inv['party_id']) ?></p>
        <?php if (!empty($inv['customer_email'])): ?>
          <p class="mb-1 text-muted small"><b>Email:</b> <?php echo htmlspecialchars($inv['customer_email']) ?></p>
        <?php endif; ?>
        <?php if (!empty($inv['customer_phone'])): ?>
          <p class="mb-1 text-muted small"><b>Phone:</b> <?php echo htmlspecialchars($inv['customer_phone']) ?></p>
        <?php endif; ?>
      </div>
      <div class="col-6 text-right">
        <h6 class="text-uppercase font-weight-bold text-navy mb-2">BILLING ADDRESS:</h6>
        <p class="mb-0 text-muted small"><?php echo nl2br(htmlspecialchars($inv['customer_address'] ?: 'N/A')) ?></p>
      </div>
    </div>

    <!-- Items Table -->
    <div class="table-responsive mb-4">
      <table class="table table-bordered table-striped table-sm">
        <thead class="bg-navy text-white">
          <tr>
            <th class="text-center" style="width: 50px;">#</th>
            <th>ITEM & DESCRIPTION</th>
            <th class="text-center" style="width: 90px;">QTY</th>
            <th class="text-right" style="width: 130px;">UNIT PRICE</th>
            <th class="text-center" style="width: 100px;">TAX RATE</th>
            <th class="text-right" style="width: 140px;">TOTAL (FJD)</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $i = 1;
          while($row = $items->fetch_assoc()):
          ?>
          <tr>
            <td class="text-center"><?php echo $i++ ?></td>
            <td>
              <strong><?php echo htmlspecialchars($row['item_name']) ?></strong>
              <?php if($row['description']): ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($row['description']) ?></small>
              <?php endif; ?>
            </td>
            <td class="text-center"><?php echo number_format($row['qty'], 2) ?></td>
            <td class="text-right"><?php echo number_format($row['unit_price'], 2) ?></td>
            <td class="text-center"><?php echo number_format($row['tax_rate'], 1) ?>%</td>
            <td class="text-right font-weight-bold"><?php echo number_format($row['amount'], 2) ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <!-- Totals Summary -->
    <div class="row">
      <div class="col-7">
        <?php if(!empty($inv['notes'])): ?>
        <div class="border p-3 rounded mb-3 bg-light">
          <h6 class="font-weight-bold text-navy mb-1"><i class="fas fa-sticky-note mr-1"></i> Notes & Terms:</h6>
          <p class="mb-0 small text-muted"><?php echo nl2br(htmlspecialchars($inv['notes'])) ?></p>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-5">
        <div class="card border shadow-none bg-light p-3">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Subtotal:</span>
            <strong class="text-dark">$<?php echo number_format($inv['subtotal'], 2) ?></strong>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Tax / VAT Amount:</span>
            <strong class="text-dark">$<?php echo number_format($inv['tax_amount'], 2) ?></strong>
          </div>
          <?php if($inv['discount'] > 0): ?>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Discount:</span>
            <strong class="text-danger">-$<?php echo number_format($inv['discount'], 2) ?></strong>
          </div>
          <?php endif; ?>
          <hr class="my-2">
          <div class="d-flex justify-content-between mb-2">
            <span class="font-weight-bold h6">Grand Total:</span>
            <strong class="font-weight-bold h5 text-navy">$<?php echo number_format($inv['total'], 2) ?></strong>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Amount Paid:</span>
            <strong class="text-success">$<?php echo number_format($inv['paid_amount'], 2) ?></strong>
          </div>
          <div class="d-flex justify-content-between pt-2 border-top">
            <span class="font-weight-bold h6 text-danger">Balance Due:</span>
            <strong class="font-weight-bold h5 text-danger">$<?php echo number_format($inv['balance'], 2) ?></strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Signatures Row -->
    <div class="row mt-5 pt-4">
      <div class="col-6 text-center">
        <p class="border-top pt-2 mb-0 font-weight-bold">Customer Signature</p>
      </div>
      <div class="col-6 text-center">
        <p class="border-top pt-2 mb-0 font-weight-bold">Authorized Signature</p>
      </div>
    </div>

    <!-- Footer Branding Bar -->
    <div class="row mt-4 pt-3 border-top text-muted text-xs">
      <div class="col-6">
        Copyright &copy; <?php echo date('Y') ?>. A property of <a href="#" class="text-primary font-weight-bold"><?php echo htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?></a>. All rights reserved.
      </div>
      <div class="col-6 text-right font-weight-bold">
        <?php echo htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?> v1.0.11
      </div>
    </div>
</div>
</body>
</html>
