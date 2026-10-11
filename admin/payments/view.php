<?php
require_once('../../config.php');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<script>alert('Invalid Payment ID'); location.replace('./?page=payments');</script>";
    exit;
}

$tenant_id = $_settings->active_tenant_id();
$id = intval($_GET['id']);

$qry = $conn->query("
    SELECT p.*,
           pt.party_code, pt.name AS party_name, pt.email AS party_email, pt.phone AS party_phone,
           u.firstname, u.lastname,
           a.name AS bank_account_name
    FROM `payment_list` p
    INNER JOIN `party_list` pt ON pt.id = p.party_id
    LEFT JOIN `users` u ON u.id = p.created_by
    LEFT JOIN `account_list` a ON a.id = p.bank_account_id
    WHERE p.id = '{$id}' AND p.tenant_id = '{$tenant_id}'
");

if ($qry->num_rows <= 0) {
    echo "<script>alert('Payment not found'); location.replace('./?page=payments');</script>";
    exit;
}

$payment = $qry->fetch_assoc();

$allocations = $conn->query("
    SELECT pa.amount AS allocated_amount,
           i.id AS invoice_id,
           i.invoice_no,
           i.invoice_date,
           i.due_date,
           i.total AS invoice_total,
           i.status AS invoice_status
    FROM `payment_allocations` pa
    INNER JOIN `invoice_list` i ON i.id = pa.invoice_id
    WHERE pa.payment_id = '{$id}'
    ORDER BY i.invoice_date ASC
");

$sys = [];
$sys_qry = $conn->query("SELECT meta_field, meta_value FROM `system_info`");
while ($row = $sys_qry->fetch_assoc()) {
    $sys[$row['meta_field']] = $row['meta_value'];
}

$type_label = $payment['type'] === 'receipt' ? 'Payment Receipt' : 'Payment Voucher';
?>

<style>
    :root {
        --receipt-primary: #1a56db;
        --receipt-border: #e5e7eb;
    }

    body {
        background: #f3f4f6;
    }

    .receipt-wrapper {
        max-width: 850px;
        margin: 20px auto;
        background: #ffffff;
        border: 1px solid var(--receipt-border);
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border-radius: 8px;
        overflow: hidden;
    }

    .receipt-header {
        background: linear-gradient(135deg, #1e3a8a, #1a56db);
        color: white;
        padding: 25px 35px;
        position: relative;
    }

    .receipt-header::after {
        content: "";
        position: absolute;
        bottom: -12px;
        left: 0;
        right: 0;
        height: 12px;
        background: repeating-linear-gradient(
            -45deg,
            #1a56db,
            #1a56db 10px,
            #1e40af 10px,
            #1e40af 20px
        );
    }

    .company-name {
        font-size: 1.6rem;
        font-weight: 700;
        margin-bottom: 2px;
    }

    .receipt-title {
        font-size: 1.4rem;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-top: 8px;
    }

    .receipt-body {
        padding: 35px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }

    .info-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6b7280;
        margin-bottom: 3px;
    }

    .info-value {
        font-size: 1.05rem;
        font-weight: 600;
        color: #111827;
    }

    .amount-box {
        background: #f0f9ff;
        border: 2px solid #bae6fd;
        border-radius: 8px;
        padding: 18px 25px;
        text-align: center;
        margin: 25px 0;
    }

    .amount-box .label {
        font-size: 0.85rem;
        color: #0369a1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .amount-box .value {
        font-size: 2.2rem;
        font-weight: 700;
        color: #0c4a6e;
        margin: 5px 0;
    }

    .section-title {
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #374151;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 8px;
        margin-bottom: 15px;
    }

    .allocation-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 25px;
    }

    .allocation-table th {
        background: #f9fafb;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #6b7280;
        padding: 10px 12px;
        text-align: left;
        border-bottom: 2px solid #e5e7eb;
    }

    .allocation-table td {
        padding: 12px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 0.95rem;
    }

    .allocation-table tr:last-child td {
        border-bottom: none;
    }

    .signature-area {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 40px;
        margin-top: 50px;
        padding-top: 20px;
    }

    .signature-box {
        text-align: center;
    }

    .signature-line {
        border-top: 1px solid #9ca3af;
        margin: 50px 20px 8px;
    }

    .footer-note {
        text-align: center;
        font-size: 0.8rem;
        color: #6b7280;
        margin-top: 30px;
        padding-top: 15px;
        border-top: 1px dashed #d1d5db;
    }

    /* Print styles */
    @media print {
        body {
            background: white !important;
        }
        .no-print {
            display: none !important;
        }
        .receipt-wrapper {
            box-shadow: none;
            border: none;
            margin: 0;
            max-width: 100%;
        }
        .receipt-header {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>

<div class="no-print text-center mb-3" style="max-width:850px;margin:15px auto;">
    <button type="button" id="btn_email_receipt" class="btn btn-warning btn-sm">
        <i class="fa fa-envelope"></i> Email Receipt
    </button>
    <button onclick="window.print()" class="btn btn-primary btn-sm">
        <i class="fa fa-print"></i> Print Receipt
    </button>
    <a href="./?page=payments" class="btn btn-default btn-sm">
        <i class="fa fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="receipt-wrapper">
    <!-- Header -->
    <div class="receipt-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="company-name"><?= htmlspecialchars($sys['name'] ?? 'Nuvis ERPX') ?></div>
                <div style="font-size:0.9rem;opacity:0.9;">
                    <?= htmlspecialchars($sys['email'] ?? '') ?>
                    <?php if (!empty($sys['contact'])): ?>
                        &nbsp;|&nbsp; <?= htmlspecialchars($sys['contact']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-right">
                <div class="receipt-title"><?= $type_label ?></div>
                <div style="font-size:1.1rem;font-weight:600;margin-top:4px;">
                    <?= htmlspecialchars($payment['payment_no']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="receipt-body">

        <!-- Key Information -->
        <div class="info-grid">
            <div>
                <div class="info-label">Received From / Paid To</div>
                <div class="info-value"><?= htmlspecialchars($payment['party_name']) ?></div>
                <div style="font-size:0.9rem;color:#6b7280;margin-top:2px;">
                    <?= htmlspecialchars($payment['party_code']) ?>
                    <?php if ($payment['party_phone']): ?>
                        &nbsp;•&nbsp; <?= htmlspecialchars($payment['party_phone']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-right">
                <div class="info-label">Payment Date</div>
                <div class="info-value"><?= date('d F Y', strtotime($payment['payment_date'])) ?></div>

                <div class="info-label" style="margin-top:12px;">Payment Method</div>
                <div class="info-value"><?= ucfirst($payment['method']) ?></div>
            </div>
        </div>

        <!-- Amount Highlight -->
        <div class="amount-box">
            <div class="label">Amount <?= $payment['type'] === 'receipt' ? 'Received' : 'Paid' ?></div>
            <div class="value">$<?= number_format($payment['amount'], 2) ?></div>
            <div style="font-size:0.9rem;color:#0369a1;">
                <?= htmlspecialchars($payment['currency'] ?? 'FJD') ?>
                <?php if ($payment['reference']): ?>
                    &nbsp;|&nbsp; Ref: <?= htmlspecialchars($payment['reference']) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Allocated Invoices -->
        <?php if ($allocations->num_rows > 0): ?>
            <div class="section-title">Allocated to Invoice(s)</div>
            <table class="allocation-table">
                <thead>
                    <tr>
                        <th>Invoice No.</th>
                        <th>Invoice Date</th>
                        <th class="text-right">Invoice Total</th>
                        <th class="text-right">Amount Applied</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_allocated = 0;
                    while ($row = $allocations->fetch_assoc()):
                        $total_allocated += $row['allocated_amount'];
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['invoice_no']) ?></strong>
                        </td>
                        <td><?= date('d M Y', strtotime($row['invoice_date'])) ?></td>
                        <td class="text-right">$<?= number_format($row['invoice_total'], 2) ?></td>
                        <td class="text-right"><strong>$<?= number_format($row['allocated_amount'], 2) ?></strong></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right" style="padding-top:12px;font-weight:600;">Total Allocated</td>
                        <td class="text-right" style="padding-top:12px;font-weight:700;">
                            $<?= number_format($total_allocated, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <?php if (!empty($payment['notes'])): ?>
            <div class="section-title">Notes</div>
            <p style="color:#4b5563;"><?= nl2br(htmlspecialchars($payment['notes'])) ?></p>
        <?php endif; ?>

        <!-- Signature Area -->
        <div class="signature-area">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div style="font-size:0.85rem;color:#6b7280;">Received By / Customer Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div style="font-size:0.85rem;color:#6b7280;">
                    Authorized Signature<br>
                    <small><?= htmlspecialchars(trim(($payment['firstname'] ?? '') . ' ' . ($payment['lastname'] ?? ''))) ?></small>
                </div>
            </div>
        </div>

        <script>
        $(document).ready(function(){
            $('#btn_email_receipt').click(function(){
                var email = prompt("Enter customer email address:", "<?= htmlspecialchars($payment['party_email'] ?? '') ?>");
                if(email){
                    start_loader();
                    $.ajax({
                        url: _base_url_ + "classes/Master.php?f=email_payment_receipt",
                        method: "POST",
                        data: { id: "<?= $payment['id'] ?>", custom_email: email },
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
        <div class="footer-note">
            This is a computer-generated receipt and is valid without a physical signature.<br>
            Thank you for your business.
        </div>
    </div>
</div>