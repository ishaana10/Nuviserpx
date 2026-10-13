<?php
require_once(__DIR__ . '/../../config.php');

if(!isset($_GET['id']) || !is_numeric($_GET['id'])){
    echo "<script>alert('Invalid Invoice ID'); location.replace('./?page=invoices');</script>";
    exit;
}

$tenant_id = $_settings->active_tenant_id();
$id = intval($_GET['id']);

$qry = $conn->query("
    SELECT i.*,
           p.party_code, p.name AS party_name, p.email AS party_email, p.phone AS party_phone, p.tin AS party_tin, p.address AS party_address,
           u.firstname, u.lastname
    FROM `invoice_list` i
    INNER JOIN `party_list` p ON p.id = i.party_id
    LEFT JOIN `users` u ON u.id = i.created_by
    WHERE i.id = '{$id}' AND i.tenant_id = '{$tenant_id}'
");

if($qry->num_rows <= 0){
    echo "<script>alert('Invoice not found'); location.replace('./?page=invoices');</script>";
    exit;
}

$invoice = $qry->fetch_assoc();

$items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$id}' ORDER BY id ASC");

// Get payment history / allocations for this invoice
$payments = $conn->query("
    SELECT pa.amount AS allocated_amount, p.payment_no, p.payment_date, p.method, p.reference
    FROM `payment_allocations` pa
    INNER JOIN `payment_list` p ON p.id = pa.payment_id
    WHERE pa.invoice_id = '{$id}'
    ORDER BY p.payment_date DESC
");

// System info
$sys = [];
$sys_qry = $conn->query("SELECT meta_field, meta_value FROM `system_info`");
while($row = $sys_qry->fetch_assoc()){
    $sys[$row['meta_field']] = $row['meta_value'];
}

$status_class = [
    'draft'          => 'secondary',
    'unpaid'         => 'danger',
    'partially_paid' => 'warning',
    'paid'           => 'success',
    'cancelled'      => 'dark'
][$invoice['status']] ?? 'secondary';

$inv_view = isset($_GET['view']) ? $_GET['view'] : ($_settings->info('invoice_default_view') ?: 'modern');
$inv_color = isset($_GET['color']) ? $_GET['color'] : ($_settings->info('invoice_theme_color') ?: '#1e3a8a');
?>

<style>
    :root {
        --inv-primary: <?= htmlspecialchars($inv_color) ?>;
    }
    @media print {
        .no-print { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .card-header, .card-footer { display: none !important; }
        body { background: #fff !important; }
    }
    .invoice-title { font-size: 1.8rem; font-weight: 700; color: var(--inv-primary); }
    .bg-inv-primary { background-color: var(--inv-primary) !important; color: #ffffff !important; }
    .text-inv-primary { color: var(--inv-primary) !important; }
    .border-inv-primary { border-color: var(--inv-primary) !important; }

    /* Classic View Specific Styling */
    .invoice-classic-table { border: 2px solid #000 !important; }
    .invoice-classic-table th, .invoice-classic-table td { border: 1px solid #000 !important; }
    .invoice-classic-header { border-bottom: 3px double #000 !important; }
</style>

<div class="card card-outline card-primary">
    <div class="card-header no-print d-flex justify-content-between align-items-center">
        <h3 class="card-title m-0">
            Invoice <strong>#<?= htmlspecialchars($invoice['invoice_no']) ?></strong>
        </h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <!-- View Selector Dropdown -->
            <div class="btn-group mr-2">
                <button type="button" class="btn btn-sm btn-outline-navy dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-palette mr-1"></i> View Style: <?= ucfirst($inv_view) ?>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-menu-item dropdown-item <?= $inv_view == 'modern' ? 'active' : '' ?>" href="./?page=invoices/view&id=<?= $id ?>&view=modern&color=<?= urlencode($inv_color) ?>">Modern View</a>
                    <a class="dropdown-menu-item dropdown-item <?= $inv_view == 'classic' ? 'active' : '' ?>" href="./?page=invoices/view&id=<?= $id ?>&view=classic&color=<?= urlencode($inv_color) ?>">Classic View</a>
                    <a class="dropdown-menu-item dropdown-item <?= $inv_view == 'minimalist' ? 'active' : '' ?>" href="./?page=invoices/view&id=<?= $id ?>&view=minimalist&color=<?= urlencode($inv_color) ?>">Minimalist View</a>
                    <div class="dropdown-divider"></div>
                    <div class="dropdown-header">Accent Color Theme</div>
                    <a class="dropdown-item" href="./?page=invoices/view&id=<?= $id ?>&view=<?= $inv_view ?>&color=%231e3a8a"><span class="badge badge-primary mr-1">&nbsp;</span> Navy Blue</a>
                    <a class="dropdown-item" href="./?page=invoices/view&id=<?= $id ?>&view=<?= $inv_view ?>&color=%23059669"><span class="badge badge-success mr-1">&nbsp;</span> Emerald Green</a>
                    <a class="dropdown-item" href="./?page=invoices/view&id=<?= $id ?>&view=<?= $inv_view ?>&color=%237c3aed"><span class="badge badge-purple mr-1" style="background:#7c3aed;color:#fff;">&nbsp;</span> Royal Purple</a>
                    <a class="dropdown-item" href="./?page=invoices/view&id=<?= $id ?>&view=<?= $inv_view ?>&color=%23dc2626"><span class="badge badge-danger mr-1">&nbsp;</span> Crimson Red</a>
                    <a class="dropdown-item" href="./?page=invoices/view&id=<?= $id ?>&view=<?= $inv_view ?>&color=%23111827"><span class="badge badge-dark mr-1">&nbsp;</span> Charcoal Black</a>
                </div>
            </div>

            <?php if($invoice['balance'] > 0): ?>
            <a href="./?page=payments/manage_payment&party_id=<?= $invoice['party_id'] ?>&invoice_id=<?= $invoice['id'] ?>" class="btn btn-sm btn-success">
                <i class="fa fa-money-bill-wave"></i> Record Receipt
            </a>
            <?php endif; ?>
            <button type="button" id="btn_email_inv" class="btn btn-sm btn-warning">
                <i class="fa fa-envelope"></i> Email Invoice
            </button>
            <button onclick="window.print()" class="btn btn-sm btn-secondary">
                <i class="fa fa-print"></i> Print Invoice
            </button>
            <a href="./?page=invoices" class="btn btn-sm btn-default">
                <i class="fa fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div class="card-body p-4" id="print-area">
        <!-- Header -->
        <div class="row border-bottom pb-3 mb-4 align-items-center">
            <div class="col-md-6 d-flex align-items-center">
                <?php if(!empty($_settings->info('logo'))): ?>
                    <img src="<?= validate_image($_settings->info('logo')) ?>" alt="Logo" class="mr-3 rounded" style="max-height: 75px; width: auto; object-fit: contain;">
                <?php endif; ?>
                <div>
                    <h3 class="font-weight-bold text-navy mb-1"><?= htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?></h3>
                    <p class="mb-0 text-muted small"><?= htmlspecialchars($sys['address'] ?? 'Fiji Islands') ?></p>
                    <p class="mb-0 text-muted small">
                        <b>Email:</b> <?= htmlspecialchars($sys['email'] ?? '') ?> | <b>Phone:</b> <?= htmlspecialchars($sys['contact'] ?? '') ?>
                    </p>
                </div>
            </div>
            <div class="col-md-6 text-right">
                <div class="invoice-title">TAX INVOICE</div>
                <h4 class="font-weight-bold mb-1">#<?= htmlspecialchars($invoice['invoice_no']) ?></h4>
                <p class="mb-1 text-muted small"><b>Invoice Date:</b> <?= date('d M Y', strtotime($invoice['invoice_date'])) ?></p>
                <p class="mb-1 text-muted small"><b>Due Date:</b> <?= $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '—' ?></p>
                <span class="badge badge-<?= $status_class ?> px-3 py-1 font-weight-normal mt-1">
                    <?= strtoupper(str_replace('_', ' ', $invoice['status'])) ?>
                </span>
            </div>
        </div>

        <!-- Customer / Billed To Info -->
        <div class="row mb-4 bg-light p-3 rounded">
            <div class="col-md-6">
                <h6 class="text-uppercase font-weight-bold text-navy mb-2">Billed To:</h6>
                <h5 class="font-weight-bold mb-1"><?= htmlspecialchars($invoice['party_name']) ?></h5>
                <p class="mb-1 text-muted small"><b>Code:</b> <?= htmlspecialchars($invoice['party_code']) ?></p>
                <?php if($invoice['party_tin']): ?>
                    <p class="mb-1 text-muted small"><b>TIN:</b> <?= htmlspecialchars($invoice['party_tin']) ?></p>
                <?php endif; ?>
                <?php if($invoice['party_email']): ?>
                    <p class="mb-1 text-muted small"><b>Email:</b> <?= htmlspecialchars($invoice['party_email']) ?></p>
                <?php endif; ?>
                <?php if($invoice['party_phone']): ?>
                    <p class="mb-1 text-muted small"><b>Phone:</b> <?= htmlspecialchars($invoice['party_phone']) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-right">
                <h6 class="text-uppercase font-weight-bold text-navy mb-2">Billing Address:</h6>
                <p class="mb-0 text-muted small"><?= nl2br(htmlspecialchars($invoice['party_address'] ?? 'N/A')) ?></p>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-striped table-sm">
                <thead class="bg-navy text-white">
                    <tr>
                        <th>#</th>
                        <th>Item & Description</th>
                        <th class="text-center" width="80">Qty</th>
                        <th class="text-right" width="120">Unit Price</th>
                        <th class="text-center" width="90">Tax Rate</th>
                        <th class="text-right" width="130">Total (FJD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while($row = $items->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                            <?php if($row['description']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($row['description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($row['qty'], 2) ?></td>
                        <td class="text-right"><?= number_format($row['unit_price'], 2) ?></td>
                        <td class="text-center"><?= number_format($row['tax_rate'], 1) ?>%</td>
                        <td class="text-right font-weight-bold"><?= number_format($row['amount'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals Summary -->
        <div class="row">
            <div class="col-md-7">
                <?php if(!empty($invoice['notes'])): ?>
                <div class="border p-3 rounded mb-3 bg-light">
                    <h6 class="font-weight-bold text-navy mb-1"><i class="fas fa-sticky-note"></i> Notes & Terms:</h6>
                    <p class="mb-0 small text-muted"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if($payments->num_rows > 0): ?>
                <div class="border p-3 rounded bg-light">
                    <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-history"></i> Payment History:</h6>
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
                                <td><b><?= htmlspecialchars($pay['payment_no']) ?></b></td>
                                <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                <td><?= ucfirst($pay['method']) ?></td>
                                <td class="text-right font-weight-bold text-success">$<?= number_format($pay['allocated_amount'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-5">
                <div class="card border shadow-none bg-light p-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Subtotal:</span>
                        <strong class="text-dark">$<?= number_format($invoice['subtotal'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Tax / VAT Amount:</span>
                        <strong class="text-dark">$<?= number_format($invoice['tax_amount'], 2) ?></strong>
                    </div>
                    <?php if($invoice['discount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Discount:</span>
                        <strong class="text-danger">-$<?= number_format($invoice['discount'], 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="font-weight-bold h6">Grand Total:</span>
                        <strong class="font-weight-bold h5 text-primary">$<?= number_format($invoice['total'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Amount Paid:</span>
                        <strong class="text-success">$<?= number_format($invoice['paid_amount'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top">
                        <span class="font-weight-bold h6 text-danger">Balance Due:</span>
                        <strong class="font-weight-bold h5 text-danger">$<?= number_format($invoice['balance'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <script>
        $(document).ready(function(){
            $('#btn_email_inv').click(function(){
                var email = prompt("Enter customer email address:", "<?= htmlspecialchars($invoice['party_email'] ?? '') ?>");
                if(email){
                    start_loader();
                    $.ajax({
                        url: _base_url_ + "classes/Master.php?f=email_invoice",
                        method: "POST",
                        data: { id: "<?= $invoice['id'] ?>", custom_email: email },
                        dataType: "json",
                        error: err => {
                            console.log(err);
                            alert_toast("An error occurred.", 'error');
                            end_loader();
                        },
                        success: function(resp){
                            end_loader();
                            if(resp.status == 'success'){
                                alert_toast(resp.msg, 'success');
                            } else {
                                alert_toast(resp.msg || "Failed to send email.", 'error');
                            }
                        }
                    });
                }
            });
        });
        </script>
        <div class="row mt-5 pt-4">
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Customer Signature</p>
            </div>
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Authorized Signature</p>
            </div>
        </div>
    </div>

    <div class="card-footer text-center no-print">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa fa-print"></i> Print Invoice
        </button>
        <a href="./?page=invoices" class="btn btn-default">
            <i class="fa fa-arrow-left"></i> Back to Invoices
        </a>
    </div>
</div>