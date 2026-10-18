<?php
require_once('../config.php');

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    unset($_SESSION['customer_id']);
    unset($_SESSION['customer_name']);
    unset($_SESSION['customer_email']);
    unset($_SESSION['customer_client_id']);
    redirect('portal/login.php');
}

if (!isset($_SESSION['customer_id'])) {
    redirect('portal/login.php');
}

$client_id = $_SESSION['customer_client_id'];
$customer_name = $_SESSION['customer_name'];

// Fetch party info
$party = $conn->query("SELECT * FROM `client_list` WHERE id = '{$client_id}'")->fetch_assoc();

// Handle viewing a specific invoice
$view_invoice_id = $_GET['invoice_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<?php require_once('../admin/inc/header.php') ?>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand-md navbar-light navbar-white shadow-sm">
    <div class="container">
      <a href="<?php echo base_url ?>portal/" class="navbar-brand">
        <span class="brand-text font-weight-bold text-primary"><?php echo $_settings->info('name') ?></span>
        <span class="badge badge-secondary ml-2">Customer Portal</span>
      </a>

      <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#navbarCollapse">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse order-3" id="navbarCollapse">
        <ul class="navbar-nav ml-auto">
          <li class="nav-item dropdown">
            <a id="dropdownSubMenu1" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="nav-link dropdown-toggle">
              <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION['customer_name']) ?>
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
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark"> Welcome, <?php echo htmlspecialchars($customer_name) ?></h1>
            <p class="text-muted text-sm"><?php echo htmlspecialchars($party['email'] ?? '') ?></p>
          </div>
          <div class="col-sm-6 text-right">
            <?php if ($view_invoice_id): ?>
              <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Back to Statement</a>
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
        $inv_qry = $conn->query("SELECT i.*, p.name as customer_name, p.email as customer_email, p.address as customer_address FROM `invoice_list` i LEFT JOIN `client_list` p ON i.client_id = p.id WHERE i.id = '{$conn->real_escape_string($view_invoice_id)}' AND i.client_id = '{$client_id}'");
        if ($inv_qry->num_rows == 0):
      ?>
        <div class="alert alert-danger">Invoice not found or access denied.</div>
      <?php
        else:
          $inv = $inv_qry->fetch_assoc();
          $items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$inv['id']}'");
      ?>
        <div class="card card-outline card-primary shadow-sm">
          <div class="card-header border-bottom-0">
            <h3 class="card-title">Invoice #<?php echo htmlspecialchars($inv['invoice_code']) ?></h3>
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
                <p class="font-weight-bold mb-0"><?php echo date("d M Y", strtotime($inv['date_created'])) ?></p>
              </div>
              <div class="col-sm-4">
                <span class="text-muted text-xs">Due Date:</span>
                <p class="font-weight-bold mb-0"><?php echo !empty($inv['due_date']) ? date("d M Y", strtotime($inv['due_date'])) : 'N/A' ?></p>
              </div>
              <div class="col-sm-4 text-right">
                <span class="text-muted text-xs">Status:</span>
                <p class="mb-0">
                  <?php if($inv['status'] == 1): ?>
                    <span class="badge badge-success px-3 py-1">Paid</span>
                  <?php elseif($inv['status'] == 2): ?>
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
                  <td class="text-center"><?php echo number_format($row['quantity'], 2) ?></td>
                  <td class="text-right"><?php echo number_format($row['unit_price'], 2) ?></td>
                  <td class="text-right"><?php echo number_format($row['amount'], 2) ?></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-right">Total Amount:</th>
                  <th class="text-right"><?php echo number_format($inv['total_amount'], 2) ?></th>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php else: ?>
        <!-- Customer Account Dashboard Summary -->
        <?php
          $tot_inv_qry = $conn->query("SELECT COUNT(*) as count, SUM(total_amount) as total FROM `invoice_list` WHERE client_id = '{$client_id}'");
          $tot_inv = $tot_inv_qry->fetch_assoc();

          $paid_qry = $conn->query("SELECT SUM(amount) as total_paid FROM `payment_list` WHERE client_id = '{$client_id}'");
          $paid = $paid_qry->fetch_assoc();
          $total_paid = $paid['total_paid'] ?? 0;
          $total_invoiced = $tot_inv['total'] ?? 0;
          $balance_due = max(0, $total_invoiced - $total_paid);
        ?>
        <div class="row">
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

        <!-- Invoices List -->
        <div class="card card-outline card-primary shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title font-weight-bold"><i class="fas fa-file-alt mr-1"></i> Your Invoices</h3>
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
                    $invoices = $conn->query("SELECT * FROM `invoice_list` WHERE client_id = '{$client_id}' ORDER BY date_created DESC");
                    if ($invoices->num_rows > 0):
                      while($row = $invoices->fetch_assoc()):
                  ?>
                  <tr>
                    <td><?php echo date("Y-m-d", strtotime($row['date_created'])) ?></td>
                    <td class="font-weight-bold"><?php echo htmlspecialchars($row['invoice_code']) ?></td>
                    <td>$<?php echo number_format($row['total_amount'], 2) ?></td>
                    <td>
                      <?php if($row['status'] == 1): ?>
                        <span class="badge badge-success">Paid</span>
                      <?php elseif($row['status'] == 2): ?>
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
