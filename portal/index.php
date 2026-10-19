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
</style>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand-md navbar-dark portal-header shadow-sm">
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
    <div class="content-header">
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
        $inv_qry = $conn->query("SELECT i.*, p.name as customer_name, p.email as customer_email, p.address as customer_address FROM `invoice_list` i LEFT JOIN `party_list` p ON i.party_id = p.id WHERE i.id = '{$conn->real_escape_string($view_invoice_id)}' AND i.party_id = '{$party_id}'");
        if (!$inv_qry || $inv_qry->num_rows == 0):
      ?>
        <div class="alert alert-danger">Invoice not found or access denied.</div>
      <?php
        else:
          $inv = $inv_qry->fetch_assoc();
          $items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$inv['id']}'");

          // Log invoice view action
          $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
          $ip_esc = $conn->real_escape_string($ip);
          $tenant_id = $_SESSION['customer_portal']['tenant_id'] ?? $inv['tenant_id'] ?? 1;
          $portal_user_id = $_SESSION['customer_portal']['id'] ?? NULL;
          $inv_code = $conn->real_escape_string($inv['invoice_no']);
          $conn->query("INSERT INTO `customer_portal_logs` (`tenant_id`, `party_id`, `portal_user_id`, `action`, `details`, `ip_address`)
                        VALUES ('{$tenant_id}', '{$party_id}', " . ($portal_user_id ? "'{$portal_user_id}'" : "NULL") . ", 'view_invoice', 'Viewed Invoice #{$inv_code}', '{$ip_esc}')");
      ?>
        <div class="card card-outline card-primary shadow-sm">
          <div class="card-header border-bottom-0">
            <h3 class="card-title">Invoice #<?php echo htmlspecialchars($inv['invoice_no']) ?></h3>
            <div class="card-tools">
              <button class="btn btn-default btn-sm" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print</button>
            </div>
          </div>
          <div class="card-body">
            <div class="row mb-4">
              <div class="col-sm-6">
                <h6 class="text-muted">Billed From:</h6>
                <p class="font-weight-bold mb-1"><?php echo $_settings->info('name') ?></p>
                <p class="text-sm text-muted mb-0"><?php echo $_settings->info('address') ?? '' ?></p>
              </div>
              <div class="col-sm-6 text-right">
                <h6 class="text-muted">Billed To:</h6>
                <p class="font-weight-bold mb-1"><?php echo htmlspecialchars($inv['customer_name']) ?></p>
                <p class="text-sm text-muted mb-0"><?php echo htmlspecialchars($inv['customer_email']) ?></p>
              </div>
            </div>

            <div class="row mb-4">
              <div class="col-sm-4">
                <span class="text-muted text-xs">Date Issued:</span>
                <p class="font-weight-bold mb-0"><?php echo date("d M Y", strtotime($inv['invoice_date'] ?? $inv['date_created'])) ?></p>
              </div>
              <div class="col-sm-4">
                <span class="text-muted text-xs">Due Date:</span>
                <p class="font-weight-bold mb-0"><?php echo !empty($inv['due_date']) ? date("d M Y", strtotime($inv['due_date'])) : 'N/A' ?></p>
              </div>
              <div class="col-sm-4 text-right">
                <span class="text-muted text-xs">Status:</span>
                <p class="mb-0">
                  <?php if($inv['status'] == 'paid' || $inv['status'] == 1): ?>
                    <span class="badge badge-success px-3 py-1">Paid</span>
                  <?php elseif($inv['status'] == 'partially_paid' || $inv['status'] == 2): ?>
                    <span class="badge badge-warning px-3 py-1">Partially Paid</span>
                  <?php else: ?>
                    <span class="badge badge-danger px-3 py-1">Unpaid</span>
                  <?php endif; ?>
                </p>
              </div>
            </div>

            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th>Description</th>
                  <th class="text-center" style="width: 100px;">QTY</th>
                  <th class="text-right" style="width: 150px;">Unit Price</th>
                  <th class="text-right" style="width: 150px;">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php while($row = $items->fetch_assoc()): ?>
                <tr>
                  <td><?php echo htmlspecialchars($row['item_name'] ?? $row['description'] ?? 'Line Item') ?></td>
                  <td class="text-center"><?php echo number_format($row['qty'], 2) ?></td>
                  <td class="text-right"><?php echo number_format($row['unit_price'], 2) ?></td>
                  <td class="text-right"><?php echo number_format($row['amount'], 2) ?></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-right">Total Amount:</th>
                  <th class="text-right"><?php echo number_format($inv['total'], 2) ?></th>
                </tr>
              </tfoot>
            </table>
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
                      <a href="index.php?invoice_id=<?php echo $row['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye mr-1"></i> View</a>
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
  <footer class="main-footer">
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
