<?php
use App\Core\Container;
use App\Services\VmsService;

$vmsService = Container::getInstance()->make(VmsService::class);
$tenant_id = $_settings->active_tenant_id();

$conn = $_settings->conn;
$pending_cnt = $conn->query("SELECT COUNT(*) AS c FROM `vms_queues` WHERE tenant_id = '{$tenant_id}' AND status = 'pending'")->fetch_assoc()['c'] ?? 0;
$retrying_cnt = $conn->query("SELECT COUNT(*) AS c FROM `vms_queues` WHERE tenant_id = '{$tenant_id}' AND status = 'retrying'")->fetch_assoc()['c'] ?? 0;
$failed_cnt = $conn->query("SELECT COUNT(*) AS c FROM `vms_queues` WHERE tenant_id = '{$tenant_id}' AND status = 'failed'")->fetch_assoc()['c'] ?? 0;
$completed_cnt = $conn->query("SELECT COUNT(*) AS c FROM `vms_queues` WHERE tenant_id = '{$tenant_id}' AND status = 'completed'")->fetch_assoc()['c'] ?? 0;
$receipts_cnt = $conn->query("SELECT COUNT(*) AS c FROM `vms_fiscal_receipts` WHERE tenant_id = '{$tenant_id}'")->fetch_assoc()['c'] ?? 0;

$recent_receipts = $conn->query("
    SELECT r.*, i.invoice_no
    FROM `vms_fiscal_receipts` r
    LEFT JOIN `invoice_list` i ON r.invoice_id = i.id
    WHERE r.tenant_id = '{$tenant_id}'
    ORDER BY r.id DESC LIMIT 5
");
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-microchip text-primary mr-2"></i>VAT Monitoring System (VMS 3.0) Dashboard</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-success" id="process-queue-btn"><i class="fas fa-play mr-1"></i> Process Pending Queue</button>
            <a href="./?page=vms/config" class="btn btn-sm btn-primary"><i class="fas fa-cog mr-1"></i> Settings</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3 bg-info">
                    <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Pending Queue</span>
                        <span class="info-box-number"><?php echo number_format($pending_cnt); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3 bg-warning">
                    <span class="info-box-icon"><i class="fas fa-redo"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Retrying Queue</span>
                        <span class="info-box-number"><?php echo number_format($retrying_cnt); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3 bg-danger">
                    <span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Failed Queue</span>
                        <span class="info-box-number"><?php echo number_format($failed_cnt); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3 bg-success">
                    <span class="info-box-icon"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Fiscalized Receipts</span>
                        <span class="info-box-number"><?php echo number_format($receipts_cnt); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card card-outline card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-receipt mr-2"></i>Recent Fiscal Receipts</h3>
                        <div class="card-tools">
                            <a href="./?page=vms/queue" class="btn btn-tool text-primary">View Queue Manager <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-striped table-hover m-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Invoice #</th>
                                    <th>SDC Invoice No</th>
                                    <th>SDC Date & Time</th>
                                    <th>Total Amount</th>
                                    <th>Tax (VAT)</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_receipts && $recent_receipts->num_rows > 0): ?>
                                    <?php $i = 1; while ($r = $recent_receipts->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><strong><?php echo htmlspecialchars($r['invoice_no'] ?? 'INV-'.$r['invoice_id']); ?></strong></td>
                                            <td><span class="badge badge-info"><?php echo htmlspecialchars($r['sdc_invoice_no']); ?></span></td>
                                            <td><?php echo htmlspecialchars($r['sdc_date_time']); ?></td>
                                            <td>$<?php echo number_format((float)$r['total_amount'], 2); ?></td>
                                            <td>$<?php echo number_format((float)$r['tax_amount'], 2); ?></td>
                                            <td>
                                                <a href="./?page=vms/receipt&id=<?php echo $r['invoice_id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="fas fa-qrcode mr-1"></i> Receipt
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No fiscalized receipts found yet. Create an invoice to begin automatic VMS fiscalization.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#process-queue-btn').click(function(){
        _conf("Process all pending VMS queue items now?", "processQueue", []);
    });
});

function processQueue(){
    start_loader();
    $.ajax({
        url: _base_url_ + 'classes/Master.php?f=process_vms_queue',
        method: 'POST',
        dataType: 'json',
        error: function(err){
            console.error(err);
            alert_toast("An error occurred while processing VMS queue.", 'error');
            end_loader();
        },
        success: function(resp){
            end_loader();
            if(resp.status == 'success'){
                alert_toast("Processed " + resp.processed_count + " queue items successfully.", 'success');
                setTimeout(function(){
                    location.reload();
                }, 1000);
            } else {
                alert_toast("Failed to process queue.", 'error');
            }
        }
    });
}
</script>
