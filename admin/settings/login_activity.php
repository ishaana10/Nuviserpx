<?php
$tenant_id = $_settings->active_tenant_id();

$qry = $conn->query("
    SELECT al.*, u.username
    FROM `audit_logs` al
    LEFT JOIN `users` u ON al.user_id = u.id
    WHERE al.tenant_id = '{$tenant_id}' AND (al.module = 'Authentication' OR al.action LIKE '%login%' OR al.action LIKE '%Logout%')
    ORDER BY al.id DESC
    LIMIT 200
");
?>

<div class="card card-outline card-danger shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-exclamation-triangle text-danger mr-2"></i> Security Login Activity & Brute-Force Audit Log</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Security Log</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-warning border-warning text-sm mb-4">
            <i class="fas fa-shield-alt mr-2"></i> Real-time monitoring of authentication attempts, successful user sign-ins, and failed login events for brute-force threat detection.
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="login-activity-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Timestamp</th>
                        <th>User Account</th>
                        <th>Event Action</th>
                        <th>Event Description / Status</th>
                        <th class="text-center">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                            $is_failed = strpos(strtolower($row['action']), 'failed') !== false || strpos(strtolower($row['details']), 'failed') !== false;
                    ?>
                    <tr class="<?= $is_failed ? 'table-danger' : '' ?>">
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle text-xs font-monospace"><?= date("d M Y H:i:s", strtotime($row['date_created'])) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['username'] ?: 'Unknown User') ?></td>
                        <td class="align-middle">
                            <?php if($is_failed): ?>
                                <span class="badge badge-danger px-2 py-1"><i class="fas fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($row['action']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> <?= htmlspecialchars($row['action']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle text-xs"><?= htmlspecialchars($row['details']) ?></td>
                        <td class="text-center align-middle text-xs font-monospace"><?= htmlspecialchars($row['ip_address'] ?: '127.0.0.1') ?></td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No security login activity logged yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#login-activity-table').dataTable({
        order: [[0, 'asc']]
    });
});
</script>
