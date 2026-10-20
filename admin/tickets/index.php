<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$where = "WHERE t.tenant_id = '{$tenant_id}'";
if(!empty($status) && $status != 'all'){
    $where .= " AND t.status = '{$conn->real_escape_string($status)}'";
}

$qry = $conn->query("
    SELECT t.*, p.name as party_name, u.username as assigned_user
    FROM `tickets` t
    LEFT JOIN `party_list` p ON t.party_id = p.id
    LEFT JOIN `users` u ON t.assigned_to = u.id
    {$where}
    ORDER BY t.id DESC
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-headset text-primary mr-2"></i> Helpdesk & Support Tickets</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_new_ticket"><i class="fas fa-plus mr-1"></i> New Ticket</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $status == 'all' ? 'active font-weight-bold' : '' ?>" href="./?page=tickets&status=all">All Tickets</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'new' ? 'active font-weight-bold' : '' ?>" href="./?page=tickets&status=new">New</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'in_progress' ? 'active font-weight-bold' : '' ?>" href="./?page=tickets&status=in_progress">In Progress</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'resolved' ? 'active font-weight-bold' : '' ?>" href="./?page=tickets&status=resolved">Resolved</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $status == 'closed' ? 'active font-weight-bold' : '' ?>" href="./?page=tickets&status=closed">Closed</a>
            </li>
        </ul>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="tickets-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Ticket Code</th>
                        <th>Subject</th>
                        <th>Customer / Contact</th>
                        <th>Priority</th>
                        <th>Assigned To</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width: 10%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['ticket_code']) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['subject']) ?></td>
                        <td class="align-middle"><?= htmlspecialchars($row['party_name'] ?: 'General Inquiry') ?></td>
                        <td class="align-middle">
                            <?php if($row['priority'] == 'urgent'): ?>
                                <span class="badge badge-danger">Urgent</span>
                            <?php elseif($row['priority'] == 'high'): ?>
                                <span class="badge badge-warning">High</span>
                            <?php elseif($row['priority'] == 'medium'): ?>
                                <span class="badge badge-info">Medium</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Low</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle"><?= htmlspecialchars($row['assigned_user'] ?: 'Unassigned') ?></td>
                        <td class="text-center align-middle">
                            <span class="badge badge-<?= $row['status'] == 'resolved' || $row['status'] == 'closed' ? 'success' : ($row['status'] == 'in_progress' ? 'primary' : 'warning') ?> px-2 py-1">
                                <?= strtoupper(str_replace('_', ' ', $row['status'])) ?>
                            </span>
                        </td>
                        <td class="text-center align-middle">
                            <a href="./?page=tickets/view_ticket&id=<?= $row['id'] ?>" class="btn btn-xs btn-outline-primary btn-flat"><i class="fas fa-eye mr-1"></i> View Thread</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($qry->num_rows <= 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No support tickets found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#tickets-table').dataTable();
    $('#btn_new_ticket').click(function(){
        uni_modal("Create Support Ticket", "tickets/manage_ticket.php", "mid-large");
    });
});
</script>
