<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id <= 0){
    echo "<script>alert('Invalid Ticket ID'); location.href='./?page=tickets';</script>";
    exit;
}

$qry = $conn->query("
    SELECT t.*, p.name as party_name, p.email as party_email, u.username as assigned_user
    FROM `tickets` t
    LEFT JOIN `party_list` p ON t.party_id = p.id
    LEFT JOIN `users` u ON t.assigned_to = u.id
    WHERE t.id = '{$id}' AND t.tenant_id = '{$tenant_id}'
");

if(!$qry || $qry->num_rows <= 0){
    echo "<script>alert('Ticket not found'); location.href='./?page=tickets';</script>";
    exit;
}

$ticket = $qry->fetch_assoc();

// Fetch message thread
$messages = $conn->query("
    SELECT tm.*, u.firstname, u.lastname, u.username
    FROM `ticket_messages` tm
    LEFT JOIN `users` u ON tm.user_id = u.id
    WHERE tm.ticket_id = '{$id}'
    ORDER BY tm.id ASC
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold">
            <i class="fas fa-headset text-primary mr-2"></i> Ticket #<?= htmlspecialchars($ticket['ticket_code']) ?> - <?= htmlspecialchars($ticket['subject']) ?>
        </h3>
        <div>
            <a href="./?page=tickets" class="btn btn-sm btn-default btn-flat"><i class="fas fa-arrow-left mr-1"></i> Back to Tickets</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-4 p-3 bg-light rounded border">
            <div class="col-md-3">
                <small class="text-uppercase text-muted font-weight-bold d-block">Customer / Contact</small>
                <strong class="text-dark"><?= htmlspecialchars($ticket['party_name'] ?: 'General Contact') ?></strong>
                <div class="text-muted text-xs"><?= htmlspecialchars($ticket['party_email'] ?: '') ?></div>
            </div>
            <div class="col-md-3">
                <small class="text-uppercase text-muted font-weight-bold d-block">Priority</small>
                <span class="badge badge-<?= $ticket['priority'] == 'urgent' ? 'danger' : 'info' ?> text-uppercase"><?= $ticket['priority'] ?></span>
            </div>
            <div class="col-md-3">
                <small class="text-uppercase text-muted font-weight-bold d-block">Status</small>
                <span class="badge badge-<?= $ticket['status'] == 'resolved' || $ticket['status'] == 'closed' ? 'success' : 'warning' ?> text-uppercase">
                    <?= str_replace('_', ' ', $ticket['status']) ?>
                </span>
            </div>
            <div class="col-md-3">
                <small class="text-uppercase text-muted font-weight-bold d-block">Assigned Agent</small>
                <strong class="text-dark"><?= htmlspecialchars($ticket['assigned_user'] ?: 'Unassigned') ?></strong>
            </div>
        </div>

        <!-- Conversation Thread -->
        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-comments mr-2 text-primary"></i> Discussion Thread</h5>

        <div class="ticket-thread-box mb-4">
            <?php while($msg = $messages->fetch_assoc()): ?>
                <div class="card card-outline card-secondary mb-3 shadow-none border">
                    <div class="card-header bg-light py-2 d-flex align-items-center justify-content-between">
                        <span class="font-weight-bold text-dark">
                            <i class="fas fa-user-circle text-secondary mr-1"></i>
                            <?= !empty($msg['firstname']) ? htmlspecialchars($msg['firstname'] . ' ' . $msg['lastname']) : 'Customer / System' ?>
                        </span>
                        <small class="text-muted"><i class="far fa-clock mr-1"></i> <?= date("d M Y H:i", strtotime($msg['date_created'])) ?></small>
                    </div>
                    <div class="card-body py-3 text-dark">
                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <!-- Reply Form -->
        <div class="card border">
            <div class="card-header bg-navy text-white">
                <h5 class="card-title font-weight-bold m-0"><i class="fas fa-reply mr-2"></i> Post Reply & Update Status</h5>
            </div>
            <div class="card-body">
                <form id="reply-ticket-form">
                    <input type="hidden" name="ticket_id" value="<?= $id ?>">

                    <div class="form-group mb-3">
                        <label for="message" class="font-weight-bold text-dark text-sm">Your Response <span class="text-danger">*</span></label>
                        <textarea id="message" name="message" rows="3" class="form-control rounded-0" placeholder="Type your response to the customer..." required></textarea>
                    </div>

                    <div class="row align-items-center">
                        <div class="col-md-4 form-group mb-0">
                            <label for="status" class="font-weight-bold text-dark text-sm mb-1">Update Ticket Status</label>
                            <select name="status" id="status" class="form-control form-control-sm rounded-0">
                                <option value="in_progress" <?= $ticket['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="waiting_client" <?= $ticket['status'] == 'waiting_client' ? 'selected' : '' ?>>Waiting Client</option>
                                <option value="resolved" <?= $ticket['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                <option value="closed" <?= $ticket['status'] == 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                        <div class="col-md-8 text-right mt-3 mt-md-0">
                            <button type="submit" class="btn btn-primary btn-flat font-weight-bold"><i class="fas fa-paper-plane mr-1"></i> Send Reply</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#reply-ticket-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=reply_ticket",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to post reply.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
