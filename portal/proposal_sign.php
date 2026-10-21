<?php
require_once(__DIR__ . '/../config.php');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id <= 0){
    die("Invalid Sales Order / Proposal Reference ID.");
}

$qry = $conn->query("
    SELECT s.*, p.name as party_name, p.email as party_email, p.phone as party_phone, p.address as party_address
    FROM `sales_order_list` s
    LEFT JOIN `party_list` p ON s.party_id = p.id
    WHERE s.id = '{$id}'
");

if(!$qry || $qry->num_rows <= 0){
    die("Sales Order / Proposal record not found.");
}

$proposal = $qry->fetch_assoc();
$tenant_id = $proposal['tenant_id'];

// Check existing signature
$sig_qry = $conn->query("SELECT * FROM `proposal_signatures` WHERE sales_order_id = '{$id}' ORDER BY id DESC LIMIT 1");
$existing_sig = ($sig_qry && $sig_qry->num_rows > 0) ? $sig_qry->fetch_assoc() : null;

// Fetch line items
$items = $conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}'");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commercial Proposal Sign-off - #<?= htmlspecialchars($proposal['order_no']) ?></title>
    <link rel="stylesheet" href="<?= base_url ?>dist/css/adminlte.css">
    <link rel="stylesheet" href="<?= base_url ?>plugins/fontawesome-free/css/all.min.css">
    <script src="<?= base_url ?>plugins/jquery/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <style>
        body { background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .proposal-card { max-width: 900px; margin: 30px auto; background: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); padding: 32px; }
        .signature-pad-wrapper { border: 2px dashed #cbd5e1; border-radius: 8px; background: #fafafa; position: relative; }
        canvas { width: 100%; height: 180px; }
    </style>
</head>
<body>

<div class="proposal-card">
    <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-4">
        <div>
            <h3 class="font-weight-bold text-dark m-0">Commercial Proposal & Quote Acceptance</h3>
            <span class="text-muted text-sm">Proposal Reference: <strong>#<?= htmlspecialchars($proposal['order_no']) ?></strong></span>
        </div>
        <div>
            <span class="badge badge-<?= $proposal['status'] == 'confirmed' ? 'success' : 'primary' ?> px-3 py-2 text-sm">
                <?= ucfirst($proposal['status']) ?>
            </span>
        </div>
    </div>

    <!-- Client & Proposal Details -->
    <div class="row mb-4">
        <div class="col-md-6">
            <small class="text-uppercase text-muted font-weight-bold">Prepared For:</small>
            <h5 class="font-weight-bold text-dark mb-1"><?= htmlspecialchars($proposal['party_name']) ?></h5>
            <div class="text-muted text-sm"><?= htmlspecialchars($proposal['party_email'] ?: 'No email listed') ?></div>
            <div class="text-muted text-sm"><?= htmlspecialchars($proposal['party_address'] ?: '') ?></div>
        </div>
        <div class="col-md-6 text-md-right">
            <small class="text-uppercase text-muted font-weight-bold">Proposal Date:</small>
            <div class="h5 font-weight-bold text-dark mb-1"><?= date("d M Y", strtotime($proposal['order_date'])) ?></div>
            <small class="text-uppercase text-muted font-weight-bold">Total Valuation:</small>
            <div class="h3 font-weight-bold text-primary mb-0">$<?= number_format($proposal['total'], 2) ?></div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="table-responsive mb-4">
        <table class="table table-bordered">
            <thead class="bg-light">
                <tr>
                    <th>Item Description</th>
                    <th class="text-center" style="width: 10%;">Qty</th>
                    <th class="text-right" style="width: 20%;">Unit Price</th>
                    <th class="text-right" style="width: 20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $items->fetch_assoc()): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                        <?php if($row['description']): ?>
                            <div class="text-muted text-xs"><?= htmlspecialchars($row['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= number_format($row['qty'], 2) ?></td>
                    <td class="text-right">$<?= number_format($row['unit_price'], 2) ?></td>
                    <td class="text-right font-weight-bold">$<?= number_format($row['amount'], 2) ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-right">Subtotal</th>
                    <th class="text-right">$<?= number_format($proposal['subtotal'], 2) ?></th>
                </tr>
                <tr>
                    <th colspan="3" class="text-right">Tax Amount</th>
                    <th class="text-right">$<?= number_format($proposal['tax_amount'], 2) ?></th>
                </tr>
                <tr class="bg-light">
                    <th colspan="3" class="text-right h5 font-weight-bold">Total Amount</th>
                    <th class="text-right h5 font-weight-bold text-primary">$<?= number_format($proposal['total'], 2) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if($existing_sig): ?>
        <!-- Completed Signature Section -->
        <div class="alert alert-success p-4 border-success">
            <h5 class="font-weight-bold"><i class="fas fa-check-circle mr-2"></i> Proposal Accepted & Digitally Signed</h5>
            <p class="mb-2">Signed by: <strong><?= htmlspecialchars($existing_sig['signer_name']) ?></strong> (<?= htmlspecialchars($existing_sig['signer_email']) ?>) on <?= date("d M Y H:i", strtotime($existing_sig['signed_at'])) ?></p>
            <div class="bg-white p-3 rounded border d-inline-block">
                <img src="<?= $existing_sig['signature_data'] ?>" alt="Client Signature" style="max-height: 100px;">
            </div>
        </div>
    <?php else: ?>
        <!-- Online E-Signature Pad Section -->
        <div class="card card-outline card-primary shadow-none border">
            <div class="card-header bg-light">
                <h5 class="card-title font-weight-bold text-dark m-0"><i class="fas fa-signature mr-2 text-primary"></i> Digital Authorization & Signature</h5>
            </div>
            <div class="card-body">
                <form id="e-sign-form">
                    <input type="hidden" name="sales_order_id" value="<?= $id ?>">
                    <input type="hidden" name="tenant_id" value="<?= $tenant_id ?>">
                    <input type="hidden" name="signature_data" id="signature_data">

                    <div class="row mb-3">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-sm">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="signer_name" class="form-control" placeholder="e.g. John Doe" value="<?= htmlspecialchars($proposal['party_name']) ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-sm">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="signer_email" class="form-control" placeholder="john@example.com" value="<?= htmlspecialchars($proposal['party_email']) ?>" required>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-sm d-flex justify-content-between">
                            <span>Draw Signature Below <span class="text-danger">*</span></span>
                            <button type="button" class="btn btn-xs btn-outline-secondary" id="clear-signature">Clear Signature</button>
                        </label>
                        <div class="signature-pad-wrapper">
                            <canvas id="signature-canvas"></canvas>
                        </div>
                    </div>

                    <div class="custom-control custom-checkbox mb-3">
                        <input type="checkbox" class="custom-control-input" id="terms-agree" required>
                        <label class="custom-control-label text-muted text-sm" for="terms-agree">
                            I confirm that I am authorized to sign and accept this commercial proposal on behalf of <?= htmlspecialchars($proposal['party_name']) ?>.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-lg btn-success btn-block font-weight-bold">
                        <i class="fas fa-check-circle mr-2"></i> Accept & Sign Proposal
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function(){
    var canvas = document.getElementById('signature-canvas');
    if(canvas){
        var signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgba(255, 255, 255, 0)',
            penColor: 'rgb(15, 23, 42)'
        });

        function resizeCanvas() {
            var ratio =  Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear();
        }

        window.onresize = resizeCanvas;
        resizeCanvas();

        $('#clear-signature').click(function(){
            signaturePad.clear();
        });

        $('#e-sign-form').submit(function(e){
            e.preventDefault();
            if(signaturePad.isEmpty()){
                alert("Please draw your signature before submitting.");
                return false;
            }
            $('#signature_data').val(signaturePad.toDataURL());

            $.ajax({
                url: "<?= base_url ?>classes/Master.php?f=save_proposal_signature",
                method: "POST",
                data: $(this).serialize(),
                dataType: "json",
                error: err => {
                    console.log(err);
                    alert("An error occurred during submission.");
                },
                success: function(resp){
                    if(resp.status == 'success'){
                        alert("Proposal successfully accepted and signed!");
                        location.reload();
                    } else {
                        alert(resp.msg || "Failed to accept proposal.");
                    }
                }
            });
        });
    }
});
</script>

</body>
</html>
