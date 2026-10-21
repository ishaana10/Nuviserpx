<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$id = isset($_GET['id']) ? $_GET['id'] : '';
if(!empty($id)){
    $qry = $conn->query("SELECT * FROM `tickets` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        $res = $qry->fetch_assoc();
        foreach($res as $k => $v){
            $$k = $v;
        }
    }
}
?>

<div class="container-fluid">
    <form action="" id="ticket-form">
        <input type="hidden" name="id" value="<?= isset($id) ? $id : '' ?>">

        <div class="form-group mb-3">
            <label for="subject" class="font-weight-bold text-dark text-sm">Subject <span class="text-danger">*</span></label>
            <input type="text" id="subject" name="subject" class="form-control form-control-sm rounded-0" placeholder="Summary of issue or request..." value="<?= isset($subject) ? htmlspecialchars($subject) : '' ?>" required>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="party_id" class="font-weight-bold text-dark text-sm">Customer / Contact</label>
                <select name="party_id" id="party_id" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="">Select Customer...</option>
                    <?php
                    $parties = $conn->query("SELECT id, name FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
                    while($p = $parties->fetch_assoc()):
                    ?>
                        <option value="<?= $p['id'] ?>" <?= (isset($party_id) && $party_id == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label for="assigned_to" class="font-weight-bold text-dark text-sm">Assign Staff</label>
                <select name="assigned_to" id="assigned_to" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="">Unassigned</option>
                    <?php
                    $users = $conn->query("SELECT id, username, firstname, lastname FROM `users` WHERE tenant_id = '{$tenant_id}' ORDER BY username ASC");
                    while($u = $users->fetch_assoc()):
                    ?>
                        <option value="<?= $u['id'] ?>" <?= (isset($assigned_to) && $assigned_to == $u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname'] . ' (' . $u['username'] . ')') ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="priority" class="font-weight-bold text-dark text-sm">Priority</label>
                <select name="priority" id="priority" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="low" <?= (isset($priority) && $priority == 'low') ? 'selected' : '' ?>>Low</option>
                    <option value="medium" <?= (!isset($priority) || $priority == 'medium') ? 'selected' : '' ?>>Medium</option>
                    <option value="high" <?= (isset($priority) && $priority == 'high') ? 'selected' : '' ?>>High</option>
                    <option value="urgent" <?= (isset($priority) && $priority == 'urgent') ? 'selected' : '' ?>>Urgent</option>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label for="status" class="font-weight-bold text-dark text-sm">Status</label>
                <select name="status" id="status" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="new" <?= (!isset($status) || $status == 'new') ? 'selected' : '' ?>>New</option>
                    <option value="in_progress" <?= (isset($status) && $status == 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                    <option value="waiting_client" <?= (isset($status) && $status == 'waiting_client') ? 'selected' : '' ?>>Waiting Client</option>
                    <option value="resolved" <?= (isset($status) && $status == 'resolved') ? 'selected' : '' ?>>Resolved</option>
                    <option value="closed" <?= (isset($status) && $status == 'closed') ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>
        </div>

        <?php if(empty($id)): ?>
        <div class="form-group mb-3">
            <label for="initial_message" class="font-weight-bold text-dark text-sm">Initial Problem Description <span class="text-danger">*</span></label>
            <textarea id="initial_message" name="initial_message" rows="4" class="form-control form-control-sm rounded-0" placeholder="Provide complete details..." required></textarea>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
$(document).ready(function(){
    $('#uni_modal').on('shown.bs.modal', function(){
        $('.select2').select2({
            dropdownParent: $('#uni_modal')
        });
    });

    $('#uni_modal #ticket-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_ticket",
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
                    alert_toast(resp.msg || "Failed to save ticket.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
