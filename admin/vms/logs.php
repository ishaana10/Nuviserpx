<?php
use App\Core\Container;
use App\Services\VmsService;

$vmsService = Container::getInstance()->make(VmsService::class);
$tenant_id = $_settings->active_tenant_id();

$log_type = $_GET['type'] ?? '';
$logs = $vmsService->getLogs($tenant_id, $log_type ?: null, 100);
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-file-alt text-primary mr-2"></i>VMS Full Audit, Fail, Retry & Queue Logs</h3>
        <div class="card-tools">
            <a href="./?page=vms/queue" class="btn btn-sm btn-info"><i class="fas fa-list-ol mr-1"></i> Queue Manager</a>
            <a href="./?page=vms" class="btn btn-sm btn-default"><i class="fas fa-arrow-left mr-1"></i> Back</a>
        </div>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <a href="./?page=vms/logs" class="btn btn-outline-secondary <?php echo empty($log_type) ? 'active' : ''; ?>">All Logs</a>
                <a href="./?page=vms/logs&type=audit" class="btn btn-outline-info <?php echo $log_type === 'audit' ? 'active' : ''; ?>"><i class="fas fa-search mr-1"></i> Audit Logs</a>
                <a href="./?page=vms/logs&type=fail" class="btn btn-outline-danger <?php echo $log_type === 'fail' ? 'active' : ''; ?>"><i class="fas fa-exclamation-triangle mr-1"></i> Fail Logs</a>
                <a href="./?page=vms/logs&type=retry" class="btn btn-outline-warning <?php echo $log_type === 'retry' ? 'active' : ''; ?>"><i class="fas fa-redo mr-1"></i> Retry Logs</a>
                <a href="./?page=vms/logs&type=queue" class="btn btn-outline-primary <?php echo $log_type === 'queue' ? 'active' : ''; ?>"><i class="fas fa-stream mr-1"></i> Queue Logs</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover text-sm">
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Type</th>
                        <th>Action</th>
                        <th>Inv / Queue</th>
                        <th>Code</th>
                        <th>Exec Time</th>
                        <th>Message</th>
                        <th>Timestamp</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)): ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td>#<?php echo $l['id']; ?></td>
                                <td>
                                    <?php
                                    switch ($l['log_type']) {
                                        case 'audit':
                                            echo '<span class="badge badge-info">Audit</span>';
                                            break;
                                        case 'fail':
                                            echo '<span class="badge badge-danger">Fail</span>';
                                            break;
                                        case 'retry':
                                            echo '<span class="badge badge-warning">Retry</span>';
                                            break;
                                        case 'queue':
                                            echo '<span class="badge badge-primary">Queue</span>';
                                            break;
                                    }
                                    ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($l['action']); ?></code></td>
                                <td>
                                    Inv: <?php echo $l['invoice_id'] ? '#'.$l['invoice_id'] : '-'; ?><br>
                                    Q: <?php echo $l['queue_id'] ? '#'.$l['queue_id'] : '-'; ?>
                                </td>
                                <td><span class="badge badge-secondary"><?php echo $l['status_code']; ?></span></td>
                                <td><?php echo $l['execution_time_ms']; ?> ms</td>
                                <td><?php echo htmlspecialchars($l['message'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($l['created_at']); ?></td>
                                <td>
                                    <?php if (!empty($l['request_data']) || !empty($l['response_data'])): ?>
                                        <button type="button" class="btn btn-xs btn-outline-secondary view-payload-btn"
                                            data-req="<?php echo htmlspecialchars($l['request_data'] ?? ''); ?>"
                                            data-resp="<?php echo htmlspecialchars($l['response_data'] ?? ''); ?>">
                                            <i class="fas fa-code"></i> Payload
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No log records found for the selected filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Payload Inspection Modal -->
<div class="modal fade" id="payloadModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-code text-primary mr-2"></i>Log Request & Response Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <h6><strong>Request Data:</strong></h6>
                <pre id="modal-req" class="bg-dark text-light p-3 rounded" style="max-height: 200px; overflow-y: auto;"></pre>
                <h6 class="mt-3"><strong>Response Data:</strong></h6>
                <pre id="modal-resp" class="bg-dark text-light p-3 rounded" style="max-height: 200px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('.view-payload-btn').click(function(){
        var req = $(this).attr('data-req');
        var resp = $(this).attr('data-resp');
        try {
            req = req ? JSON.stringify(JSON.parse(req), null, 2) : 'N/A';
        } catch(e) {}
        try {
            resp = resp ? JSON.stringify(JSON.parse(resp), null, 2) : 'N/A';
        } catch(e) {}

        $('#modal-req').text(req || 'N/A');
        $('#modal-resp').text(resp || 'N/A');
        $('#payloadModal').modal('show');
    });
});
</script>
