<?php
$tenant_id = $_settings->active_tenant_id();

$where = "WHERE al.tenant_id = '{$tenant_id}'";

$module = isset($_GET['module']) ? $_GET['module'] : '';
if(!empty($module) && $module != 'all'){
    $where .= " AND al.module = '{$conn->real_escape_string($module)}'";
}

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
if(!empty($start_date) && !empty($end_date)){
    $where .= " AND DATE(al.date_created) BETWEEN '{$conn->real_escape_string($start_date)}' AND '{$conn->real_escape_string($end_date)}'";
}

$qry = $conn->query("
    SELECT al.*, u.username, u.firstname, u.lastname
    FROM `audit_logs` al
    LEFT JOIN `users` u ON al.user_id = u.id
    {$where}
    ORDER BY al.id DESC
    LIMIT 200
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-history text-primary mr-2"></i> System Audit Trail & Logs</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.print()"><i class="fas fa-print mr-1"></i> Print Log Report</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Date & Module Filter Form -->
        <form action="" method="GET" class="mb-4">
            <input type="hidden" name="page" value="settings/audit_logs">
            <div class="row align-items-end">
                <div class="col-md-3 form-group mb-md-0">
                    <label class="font-weight-bold text-xs text-uppercase text-muted">Module Filter</label>
                    <select name="module" class="form-control form-control-sm rounded-0">
                        <option value="all">All Modules</option>
                        <option value="invoices" <?= $module == 'invoices' ? 'selected' : '' ?>>Invoices</option>
                        <option value="journals" <?= $module == 'journals' ? 'selected' : '' ?>>Journals</option>
                        <option value="assets" <?= $module == 'assets' ? 'selected' : '' ?>>Fixed Assets</option>
                        <option value="payments" <?= $module == 'payments' ? 'selected' : '' ?>>Payments</option>
                        <option value="users" <?= $module == 'users' ? 'selected' : '' ?>>Users & Access</option>
                        <option value="settings" <?= $module == 'settings' ? 'selected' : '' ?>>System Settings</option>
                    </select>
                </div>
                <div class="col-md-3 form-group mb-md-0">
                    <label class="font-weight-bold text-xs text-uppercase text-muted">From Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm rounded-0" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3 form-group mb-md-0">
                    <label class="font-weight-bold text-xs text-uppercase text-muted">To Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm rounded-0" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3 form-group mb-md-0">
                    <button type="submit" class="btn btn-sm btn-primary btn-flat w-100 font-weight-bold"><i class="fas fa-filter mr-1"></i> Apply Filters</button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="audit-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Action Performed</th>
                        <th>Details / Parameters</th>
                        <th class="text-center">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle text-nowrap text-xs"><?= date("d M Y H:i:s", strtotime($row['date_created'])) ?></td>
                        <td class="align-middle font-weight-bold">
                            <?= !empty($row['username']) ? htmlspecialchars($row['username']) : 'System / Portal User' ?>
                        </td>
                        <td class="align-middle text-capitalize">
                            <span class="badge badge-light border px-2 py-1"><?= htmlspecialchars($row['module'] ?: 'General') ?></span>
                        </td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['action']) ?></td>
                        <td class="align-middle text-xs"><?= htmlspecialchars($row['details']) ?></td>
                        <td class="text-center align-middle text-xs font-monospace"><?= htmlspecialchars($row['ip_address'] ?: '127.0.0.1') ?></td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No audit logs found matching the selected filter criteria.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#audit-table').dataTable({
        order: [[0, 'asc']]
    });
});
</script>
