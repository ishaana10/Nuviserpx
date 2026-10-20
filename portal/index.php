<?php
require_once('../config.php');

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    unset($_SESSION['customer_portal']);
    unset($_SESSION['customer_id']);
    unset($_SESSION['customer_name']);
    unset($_SESSION['customer_email']);
    unset($_SESSION['customer_client_id']);
    redirect('portal/login.php');
}

if (!isset($_SESSION['customer_portal']) && !isset($_SESSION['customer_id'])) {
    redirect('portal/login.php');
}

$party_id = $_SESSION['customer_portal']['party_id'] ?? $_SESSION['customer_party_id'] ?? $_SESSION['customer_client_id'] ?? 0;
$customer_name = $_SESSION['customer_name'] ?? '';

// Fetch party info
$party = $conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}'")->fetch_assoc();
if ($party) {
    if (empty($customer_name)) {
        $customer_name = $party['name'];
    }
}

// System info for invoice header branding
$sys = [];
$sys_qry = $conn->query("SELECT meta_field, meta_value FROM `system_info`");
if ($sys_qry) {
    while ($r = $sys_qry->fetch_assoc()) {
        $sys[$r['meta_field']] = $r['meta_value'];
    }
}

// Read portal customization settings
$portal_show_invoices = $_settings->info('portal_show_invoices') !== '0';
$portal_show_payments = $_settings->info('portal_show_payments') !== '0';
$portal_show_orders   = $_settings->info('portal_show_orders') !== '0';
$portal_show_kpi      = $_settings->info('portal_show_kpi_cards') !== '0';
$portal_welcome_msg   = $_settings->info('portal_welcome_msg') ?: 'Welcome to your customer portal. View your account statement and invoices below.';
$portal_announcement  = $_settings->info('portal_announcement');
$portal_theme_color   = $_settings->info('portal_primary_color') ?: '#0284c7';

// Handle viewing a specific invoice
$view_invoice_id = $_GET['invoice_id'] ?? null;
$view_order_id   = $_GET['order_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<?php require_once('../admin/inc/header.php') ?>
<style>
  .portal-header {
    background-color: <?php echo htmlspecialchars($portal_theme_color) ?> !important;
  }
  @media print {
    .no-print { display: none !important; }
    .main-header, .main-footer, .content-header { display: none !important; }
    .content-wrapper { background: #fff !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
  }
</style>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand-md navbar-dark portal-header shadow-sm no-print">
    <div class="container">
      <a href="<?php echo base_url ?>portal/" class="navbar-brand">
        <span class="brand-text font-weight-bold text-white"><?php echo $_settings->info('name') ?></span>
        <span class="badge badge-light ml-2">Customer Portal (Read-Only)</span>
      </a>

      <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#navbarCollapse">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav ml-auto">
          <li class="nav-item dropdown">
            <a id="dropdownSubMenu1" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="nav-link dropdown-toggle text-white">
              <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($customer_name ?: 'Customer Account') ?>
            </a>
            <ul aria-labelledby="dropdownSubMenu1" class="dropdown-menu dropdown-menu-right border-0 shadow">
              <li><a href="index.php?action=logout" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt mr-2"></i> Log Out</a></li>
            </ul>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  <!-- /.navbar -->

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper bg-light">
    <!-- Content Header (Page header) -->
    <div class="content-header no-print">
      <div class="container">
        <?php if (!empty($portal_announcement)): ?>
          <div class="alert alert-info border-left border-info shadow-sm mb-3">
            <i class="fas fa-bullhorn mr-2"></i> <?php echo nl2br(htmlspecialchars($portal_announcement)) ?>
          </div>
        <?php endif; ?>

        <div class="row mb-2">
          <div class="col-sm-8">
            <h1 class="m-0 text-dark font-weight-bold"> Welcome, <?php echo htmlspecialchars($customer_name) ?></h1>
            <p class="text-muted text-sm mb-0"><?php echo htmlspecialchars($portal_welcome_msg) ?></p>
          </div>
          <div class="col-sm-4 text-right">
            <?php if ($view_invoice_id || $view_order_id): ?>
              <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Back to Dashboard</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Main content -->
    <div class="content">
      <div class="container">

      <?php if ($view_invoice_id):
        // Fetch invoice details
        $inv_qry = $conn->query("SELECT i.*, p.party_code, p.name as customer_name, p.email as customer_email, p.phone as customer_phone, p.tin as customer_tin, p.address as customer_address FROM `invoice_list` i LEFT JOIN `party_list` p ON i.party_id = p.id WHERE i.id = '{$conn->real_escape_string($view_invoice_id)}' AND i.party_id = '{$party_id}'");
        if (!$inv_qry || $inv_qry->num_rows == 0):
      ?>
        <div class="alert alert-danger">Invoice not found or access denied.</div>
      <?php
        else:
          $inv = $inv_qry->fetch_assoc();
          $items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$inv['id']}' ORDER BY id ASC");
          $payments = $conn->query("SELECT pa.amount as allocated_amount, p.payment_no, p.payment_date, p.method FROM `payment_allocations` pa INNER JOIN `payment_list` p ON p.id = pa.payment_id WHERE pa.invoice_id = '{$inv['id']}' ORDER BY p.payment_date DESC");

          // Log invoice view action
          $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
          $ip_esc = $conn->real_escape_string($ip);
          $tenant_id = $_SESSION['customer_portal']['tenant_id'] ?? $inv['tenant_id'] ?? 1;
          $portal_user_id = $_SESSION['customer_portal']['id'] ?? NULL;
          $inv_code = $conn->real_escape_string($inv['invoice_no']);
          $conn->query("INSERT INTO `customer_portal_logs` (`tenant_id`, `party_id`, `portal_user_id`, `action`, `details`, `ip_address`)
                        VALUES ('{$tenant_id}', '{$party_id}', " . ($portal_user_id ? "'{$portal_user_id}'" : "NULL") . ", 'view_invoice', 'Viewed Invoice #{$inv_code}', '{$ip_esc}')");

          $status_class = [
            'draft' => 'secondary',
            'unpaid' => 'danger',
            'partially_paid' => 'warning',
            'paid' => 'success',
            'cancelled' => 'dark'
          ][$inv['status']] ?? 'secondary';
      ?>
        <div class="card card-outline card-primary shadow-sm mb-4">
          <div class="card-header no-print d-flex justify-content-between align-items-center">
            <h3 class="card-title font-weight-bold m-0"><i class="fas fa-file-invoice text-primary mr-2"></i> Tax Invoice #<?php echo htmlspecialchars($inv['invoice_no']) ?></h3>
            <div class="card-tools">
              <a href="view_invoice.php?id=<?php echo $inv['id'] ?>&print=1" target="_blank" class="btn btn-sm btn-primary font-weight-bold mr-1">
                <i class="fas fa-file-pdf mr-1"></i> Download PDF / Print
              </a>
              <button onclick="window.print()" class="btn btn-sm btn-outline-secondary font-weight-bold">
                <i class="fas fa-print mr-1"></i> Print View
              </button>
            </div>
          </div>
          <div class="card-body p-4 bg-white" id="invoice-print-area">
            <!-- Header Banner Matching Attachment Image -->
            <div class="row border-bottom pb-3 mb-4 align-items-center">
              <div class="col-sm-7 d-flex align-items-center">
                <?php if(!empty($_settings->info('logo'))): ?>
                  <img src="<?php echo validate_image($_settings->info('logo')) ?>" alt="Logo" class="mr-3 rounded" style="max-height: 70px; width: auto; object-fit: contain;">
                <?php endif; ?>
                <div>
                  <h3 class="font-weight-bold text-navy mb-1"><?php echo htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?></h3>
                  <p class="mb-0 text-muted small"><?php echo htmlspecialchars($sys['address'] ?? 'Fiji Islands') ?></p>
                  <p class="mb-0 text-muted small">
                    <b>Email:</b> <?php echo htmlspecialchars($sys['email'] ?? '') ?> | <b>Phone:</b> <?php echo htmlspecialchars($sys['contact'] ?? '') ?>
                  </p>
                </div>
              </div>
              <div class="col-sm-5 text-right">
                <h2 class="font-weight-bold text-dark mb-0">TAX INVOICE</h2>
                <h4 class="font-weight-bold text-primary mb-1">#<?php echo htmlspecialchars($inv['invoice_no']) ?></h4>
                <p class="mb-1 text-muted small"><b>Invoice Date:</b> <?php echo date('d M Y', strtotime($inv['invoice_date'] ?? $inv['date_created'])) ?></p>
                <p class="mb-1 text-muted small"><b>Due Date:</b> <?php echo !empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></p>
                <span class="badge badge-<?php echo $status_class ?> px-3 py-1 font-weight-bold text-uppercase mt-1">
                  <?php echo strtoupper(str_replace('_', ' ', $inv['status'])) ?>
                </span>
              </div>
            </div>

            <!-- Billed To Section -->
            <div class="row mb-4 p-3 rounded bg-light border">
              <div class="col-sm-6">
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
              <div class="col-sm-6 text-right">
                <h6 class="text-uppercase font-weight-bold text-navy mb-2">BILLING ADDRESS:</h6>
                <p class="mb-0 text-muted small"><?php echo nl2br(htmlspecialchars($inv['customer_address'] ?: 'N/A')) ?></p>
              </div>
            </div>

            <!-- Line Items Breakdown Table -->
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

            <!-- Notes & Terms + Summary Totals -->
            <div class="row">
              <div class="col-sm-7">
                <?php if(!empty($inv['notes'])): ?>
                <div class="border p-3 rounded mb-3 bg-light">
                  <h6 class="font-weight-bold text-navy mb-1"><i class="fas fa-sticky-note mr-1"></i> Notes & Terms:</h6>
                  <p class="mb-0 small text-muted"><?php echo nl2br(htmlspecialchars($inv['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if($payments && $payments->num_rows > 0): ?>
                <div class="border p-3 rounded bg-light">
                  <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-history mr-1"></i> Payment History:</h6>
                  <table class="table table-sm table-borderless m-0 small">
                    <thead>
                      <tr class="border-bottom">
                        <th>Receipt No</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th class="text-right">Amount Paid</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php while($pay = $payments->fetch_assoc()): ?>
                      <tr>
                        <td><b><?php echo htmlspecialchars($pay['payment_no']) ?></b></td>
                        <td><?php echo date('d M Y', strtotime($pay['payment_date'])) ?></td>
                        <td><?php echo ucfirst($pay['method']) ?></td>
                        <td class="text-right font-weight-bold text-success">$<?php echo number_format($pay['allocated_amount'], 2) ?></td>
                      </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </div>

              <div class="col-sm-5">
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
        </div>
      <?php endif; ?>

      <?php else: ?>
        <!-- Customer Account Dashboard Summary -->
        <?php if ($portal_show_kpi):
          $tot_inv_qry = $conn->query("SELECT COUNT(*) as count, SUM(total) as total FROM `invoice_list` WHERE party_id = '{$party_id}'");
          $tot_inv = $tot_inv_qry ? $tot_inv_qry->fetch_assoc() : ['count' => 0, 'total' => 0];

          $paid_qry = $conn->query("SELECT SUM(amount) as total_paid FROM `payment_list` WHERE party_id = '{$party_id}'");
          $paid = $paid_qry ? $paid_qry->fetch_assoc() : ['total_paid' => 0];
          $total_paid = $paid['total_paid'] ?? 0;
          $total_invoiced = $tot_inv['total'] ?? 0;
          $balance_due = max(0, $total_invoiced - $total_paid);
        ?>
        <div class="row mb-4">
          <div class="col-lg-4 col-6">
            <div class="small-box bg-info shadow-sm">
              <div class="inner">
                <h3><?php echo number_format($tot_inv['count']) ?></h3>
                <p>Total Invoices</p>
              </div>
              <div class="icon"><i class="fas fa-file-invoice"></i></div>
            </div>
          </div>
          <div class="col-lg-4 col-6">
            <div class="small-box bg-warning shadow-sm">
              <div class="inner">
                <h3>$<?php echo number_format($balance_due, 2) ?></h3>
                <p>Outstanding Balance</p>
              </div>
              <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
            </div>
          </div>
          <div class="col-lg-4 col-6">
            <div class="small-box bg-success shadow-sm">
              <div class="inner">
                <h3>$<?php echo number_format($total_paid, 2) ?></h3>
                <p>Total Payments Received</p>
              </div>
              <div class="icon"><i class="fas fa-check-circle"></i></div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($portal_show_invoices): ?>
        <!-- Invoices List -->
        <div class="card card-outline card-primary shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title font-weight-bold"><i class="fas fa-file-alt mr-1"></i> Your Invoices (Read-Only)</h3>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover mb-0">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th class="text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $invoices = $conn->query("SELECT * FROM `invoice_list` WHERE party_id = '{$party_id}' ORDER BY date_created DESC");
                    if ($invoices && $invoices->num_rows > 0):
                      while($row = $invoices->fetch_assoc()):
                  ?>
                  <tr>
                    <td><?php echo date("Y-m-d", strtotime($row['invoice_date'] ?? $row['date_created'])) ?></td>
                    <td class="font-weight-bold"><?php echo htmlspecialchars($row['invoice_no']) ?></td>
                    <td>$<?php echo number_format($row['total'], 2) ?></td>
                    <td>
                      <?php if($row['status'] == 'paid' || $row['status'] == 1): ?>
                        <span class="badge badge-success">Paid</span>
                      <?php elseif($row['status'] == 'partially_paid' || $row['status'] == 2): ?>
                        <span class="badge badge-warning">Partial</span>
                      <?php else: ?>
                        <span class="badge badge-danger">Unpaid</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <a href="index.php?invoice_id=<?php echo $row['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye mr-1"></i> View Invoice</a>
                      <a href="view_invoice.php?id=<?php echo $row['id'] ?>&print=1" target="_blank" class="btn btn-sm btn-outline-secondary ml-1"><i class="fas fa-file-pdf mr-1"></i> PDF</a>
                    </td>
                  </tr>
                  <?php
                      endwhile;
                    else:
                  ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted py-4">No invoices found for your account.</td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($portal_show_payments): ?>
        <!-- Payments & Receipts List -->
        <div class="card card-outline card-success shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title font-weight-bold"><i class="fas fa-receipt mr-1"></i> Payment History (Read-Only)</h3>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover mb-0">
                <thead>
                  <tr>
                    <th>Payment Date</th>
                    <th>Receipt / Ref #</th>
                    <th>Payment Method</th>
                    <th>Amount Paid</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $payments = $conn->query("SELECT * FROM `payment_list` WHERE party_id = '{$party_id}' ORDER BY payment_date DESC");
                    if ($payments && $payments->num_rows > 0):
                      while($p_row = $payments->fetch_assoc()):
                  ?>
                  <tr>
                    <td><?php echo date("Y-m-d", strtotime($p_row['payment_date'])) ?></td>
                    <td class="font-weight-bold"><?php echo htmlspecialchars($p_row['payment_no'] ?? $p_row['reference'] ?? 'N/A') ?></td>
                    <td><span class="badge badge-light border"><?php echo ucfirst(htmlspecialchars($p_row['method'] ?? 'cash')) ?></span></td>
                    <td class="text-success font-weight-bold">$<?php echo number_format($p_row['amount'], 2) ?></td>
                  </tr>
                  <?php
                      endwhile;
                    else:
                  ?>
                  <tr>
                    <td colspan="4" class="text-center text-muted py-4">No payment receipts recorded yet.</td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

      <?php endif; ?>

      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->

  <!-- Main Footer -->
  <footer class="main-footer no-print">
    <div class="container text-center text-muted text-sm">
      &copy; <?php echo date("Y") ?> <strong><?php echo $_settings->info('name') ?></strong>. All rights reserved.
    </div>
  </footer>
</div>
<!-- ./wrapper -->

<script src="<?php echo base_url ?>plugins/jquery/jquery.min.js"></script>
<script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo base_url ?>dist/js/adminlte.min.js"></script>
</body>
</html>
