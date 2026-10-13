<?php
use App\Core\Container;
use App\Services\VmsService;

$vmsService = Container::getInstance()->make(VmsService::class);
$tenant_id = $_settings->active_tenant_id();

$status_filter = $_GET['status'] ?? '';
$queue_items = $vmsService->getQueue($tenant_id, $status_filter ?: null, 100);
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list-ol text-primary mr-2"></i>VMS Fiscalization Queue Manager</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-success" id="process-queue-btn"><i class="fas fa-play mr-1"></i> Process Pending Queue</button>
            <a href="./?page=vms/logs" class="btn btn-sm btn-info"><i class="fas fa-file-alt mr-1"></i> Logs Viewer</a>
            <a href="./?page=vms" class="btn btn-sm btn-default"><i class="fas fa-arrow-left mr-1"></i> Back</a>
        </div>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <a href="./?page=vms/queue" class="btn btn-outline-secondary <?php echo empty($status_filter) ? 'active' : ''; ?>">All Statuses</a>
                <a href="./?page=vms/queue&status=pending" class="btn btn-outline-info <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">Pending</a>
                <a href="./?page=vms/queue&status=retrying" class="btn btn-outline-warning <?php echo $status_filter === 'retrying' ? 'active' : ''; ?>">Retrying</a>
                <a href="./?page=vms/queue&status=failed" class="btn btn-outline-danger <?php echo $status_filter === 'failed' ? 'active' : ''; ?>">Failed</a>
                <a href="./?page=vms/queue&status=completed" class="btn btn-outline-success <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover">
                <thead>
                    <tr>
                        <th>Queue ID</th>
                        <th>Invoice #</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Next Attempt</th>
                        <th>Last Error</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($queue_items)): ?>
                        <?php foreach ($queue_items as $q): ?>
                            <tr>
                                <td>#<?php echo $q['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($q['invoice_no'] ?? 'INV-' . $q['invoice_id']); ?></strong></td>
                                <td>$<?php echo number_format((float)($q['invoice_total'] ?? 0.0), 2); ?></td>
                                <td>
                                    <?php
                                    switch ($q['status']) {
                                        case 'completed':
                                            echo '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i> Completed</span>';
                                            break;
                                        case 'pending':
                                            echo '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i> Pending</span>';
                                            break;
                                        case 'processing':
                                            echo '<span class="badge badge-primary"><i class="fas fa-spinner fa-spin mr-1"></i> Processing</span>';
                                            break;
                                        case 'retrying':
                                            echo '<span class="badge badge-warning"><i class="fas fa-redo mr-1"></i> Retrying</span>';
                                            break;
                                        case 'failed':
                                            echo '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i> Failed</span>';
                                            break;
                                    }
                                    ?>
                                </td>
                                <td><?php echo $q['attempts']; ?> / <?php echo $q['max_attempts']; ?></td>
                                <td><?php echo $q['next_attempt_at'] ? htmlspecialchars($q['next_attempt_at']) : '-'; ?></td>
                                <td>
                                    <?php if (!empty($q['last_error'])): ?>
                                        <small class="text-danger font-weight-bold" title="<?php echo htmlspecialchars($q['last_error']); ?>">
                                            <?php echo htmlspecialchars(substr($q['last_error'], 0, 40)) . (strlen($q['last_error']) > 40 ? '...' : ''); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($q['status'] === 'failed' || $q['status'] === 'retrying'): ?>
                                        <button type="button" class="btn btn-sm btn-warning retry-btn" data-id="<?php echo $q['id']; ?>">
                                            <i class="fas fa-sync-alt mr-1"></i> Retry
                                        </button>
                                    <?php elseif ($q['status'] === 'completed'): ?>
                                        <a href="./?page=vms/receipt&id=<?php echo $q['invoice_id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-receipt mr-1"></i> Receipt
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-ellipsis-h"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No queue items found for selected status filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#process-queue-btn').click(function(){
        start_loader();
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=process_vms_queue',
            method: 'POST',
            dataType: 'json',
            error: function(err){
                console.error(err);
                alert_toast("An error occurred while processing queue.", 'error');
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    alert_toast("Processed " + resp.processed_count + " queue items.", 'success');
                    setTimeout(function(){
                        location.reload();
                    }, 1000);
                } else {
                    alert_toast("Failed to process queue.", 'error');
                }
            }
        });
    });

    $('.retry-btn').click(function(){
        var qid = $(this).attr('data-id');
        start_loader();
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=retry_vms_queue',
            method: 'POST',
            data: { queue_id: qid },
            dataType: 'json',
            error: function(err){
                console.error(err);
                alert_toast("An error occurred while resetting queue item.", 'error');
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    alert_toast(resp.msg, 'success');
                    setTimeout(function(){
                        location.reload();
                    }, 1000);
                } else {
                    alert_toast(resp.msg || "Failed to retry queue item.", 'error');
                }
            }
        });
    });
});
</script>
