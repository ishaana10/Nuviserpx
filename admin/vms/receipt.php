<?php
use App\Core\Container;
use App\Services\VmsService;

$vmsService = Container::getInstance()->make(VmsService::class);
$tenant_id = $_settings->active_tenant_id();
$invoice_id = intval($_GET['id'] ?? 0);

$receipt = $vmsService->getReceiptByInvoice($invoice_id, $tenant_id);

if (!$receipt) {
    echo '<div class="alert alert-danger m-4">No fiscal receipt found for Invoice #' . $invoice_id . '. Please ensure the invoice is fiscalized via VMS queue.</div>';
    return;
}

$conn = $_settings->conn;
$inv = $conn->query("
    SELECT i.*, p.name AS party_name, p.tin AS party_tin, p.address AS party_address
    FROM `invoice_list` i
    LEFT JOIN `party_list` p ON i.party_id = p.id
    WHERE i.id = '{$invoice_id}' AND i.tenant_id = '{$tenant_id}'
")->fetch_assoc();

$items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$invoice_id}'");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Fiscal Receipt - Invoice #<?php echo htmlspecialchars($inv['invoice_no'] ?? ''); ?></title>
    <style>
        body { font-family: 'Courier New', Courier, monospace; background: #f4f6f9; color: #000; padding: 20px; }
        .receipt-card { max-width: 450px; margin: 0 auto; background: #fff; padding: 20px; border: 1px solid #ccc; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .line { border-bottom: 1px dashed #000; margin: 10px 0; }
        .qr-code { width: 160px; height: 160px; margin: 10px auto; display: block; }
        table { width: 100%; font-size: 13px; border-collapse: collapse; }
        th, td { padding: 4px 0; }
        .btn-print { display: block; width: 100%; max-width: 450px; margin: 10px auto; padding: 10px; background: #28a745; color: #fff; text-align: center; font-family: sans-serif; text-decoration: none; font-weight: bold; border-radius: 4px; }
        @media print { .btn-print { display: none; } body { background: #fff; padding: 0; } .receipt-card { border: none; box-shadow: none; } }
    </style>
</head>
<body>

<a href="#" onclick="window.print(); return false;" class="btn-print">🖨️ Print Fiscal Receipt</a>

<div class="receipt-card">
    <div class="text-center">
        <h2><?php echo htmlspecialchars($_settings->info('name')); ?></h2>
        <p><?php echo htmlspecialchars($_settings->info('address')); ?></p>
        <p>TIN: <?php echo htmlspecialchars($_settings->info('tin') ?: '123456789'); ?></p>
        <h3>FISCAL RECEIPT</h3>
    </div>

    <div class="line"></div>

    <p><strong>Invoice No:</strong> <?php echo htmlspecialchars($inv['invoice_no']); ?></p>
    <p><strong>Date:</strong> <?php echo htmlspecialchars($inv['invoice_date']); ?></p>
    <p><strong>Buyer:</strong> <?php echo htmlspecialchars($inv['party_name'] ?: 'Cash Customer'); ?></p>
    <?php if (!empty($inv['party_tin'])): ?>
        <p><strong>Buyer TIN:</strong> <?php echo htmlspecialchars($inv['party_tin']); ?></p>
    <?php endif; ?>

    <div class="line"></div>

    <table>
        <thead>
            <tr>
                <th align="left">Item</th>
                <th align="center">Qty</th>
                <th align="right">Price</th>
                <th align="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items): ?>
                <?php while ($it = $items->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                        <td align="center"><?php echo $it['qty']; ?></td>
                        <td align="right">$<?php echo number_format((float)$it['unit_price'], 2); ?></td>
                        <td align="right">$<?php echo number_format((float)$it['amount'], 2); ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="line"></div>

    <table>
        <tr>
            <td><strong>Subtotal:</strong></td>
            <td align="right">$<?php echo number_format((float)$inv['subtotal'], 2); ?></td>
        </tr>
        <tr>
            <td><strong>VAT (15% Included):</strong></td>
            <td align="right">$<?php echo number_format((float)$receipt['tax_amount'], 2); ?></td>
        </tr>
        <tr>
            <td><strong>TOTAL PAID:</strong></td>
            <td align="right"><strong>$<?php echo number_format((float)$receipt['total_amount'], 2); ?></strong></td>
        </tr>
    </table>

    <div class="line"></div>

    <div class="text-center">
        <p><strong>SDC Invoice No:</strong><br><code><?php echo htmlspecialchars($receipt['sdc_invoice_no']); ?></code></p>
        <p><strong>SDC Time:</strong> <?php echo htmlspecialchars($receipt['sdc_date_time']); ?></p>
        <p><strong>MRC:</strong> <?php echo htmlspecialchars($receipt['mrc_code']); ?></p>

        <img src="<?php echo htmlspecialchars($receipt['verification_qr']); ?>" class="qr-code" alt="FRCS QR Code">

        <p style="font-size: 11px; word-break: break-all;">
            Verify Receipt:<br>
            <a href="<?php echo htmlspecialchars($receipt['verification_url']); ?>" target="_blank"><?php echo htmlspecialchars($receipt['verification_url']); ?></a>
        </p>

        <div class="line"></div>
        <p>Thank you for shopping with us!</p>
        <p><small>Powered by Nuvis ERPX VMS Module</small></p>
    </div>
</div>

</body>
</html>
