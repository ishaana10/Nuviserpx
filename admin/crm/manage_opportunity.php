<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$id = isset($_GET['id']) ? $_GET['id'] : '';
if(!empty($id)){
    $qry = $conn->query("SELECT * FROM `crm_opportunities` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        $res = $qry->fetch_assoc();
        foreach($res as $k => $v){
            $$k = $v;
        }
    }
}
?>

<div class="container-fluid">
    <form action="" id="opportunity-form">
        <input type="hidden" name="id" value="<?= isset($id) ? $id : '' ?>">

        <div class="form-group mb-3">
            <label for="title" class="font-weight-bold text-dark text-sm">Deal Title <span class="text-danger">*</span></label>
            <input type="text" id="title" name="title" class="form-control form-control-sm rounded-0" placeholder="e.g. Annual IT Infrastructure Support Contract" value="<?= isset($title) ? htmlspecialchars($title) : '' ?>" required>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="party_id" class="font-weight-bold text-dark text-sm">Contact / Customer</label>
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
                <label for="stage" class="font-weight-bold text-dark text-sm">Pipeline Stage</label>
                <select name="stage" id="stage" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="qualification" <?= (!isset($stage) || $stage == 'qualification') ? 'selected' : '' ?>>Qualification</option>
                    <option value="proposal" <?= (isset($stage) && $stage == 'proposal') ? 'selected' : '' ?>>Proposal Sent</option>
                    <option value="negotiation" <?= (isset($stage) && $stage == 'negotiation') ? 'selected' : '' ?>>Negotiation</option>
                    <option value="won" <?= (isset($stage) && $stage == 'won') ? 'selected' : '' ?>>Closed Won</option>
                    <option value="lost" <?= (isset($stage) && $stage == 'lost') ? 'selected' : '' ?>>Closed Lost</option>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 form-group">
                <label for="amount" class="font-weight-bold text-dark text-sm">Deal Value ($)</label>
                <input type="number" step="any" id="amount" name="amount" class="form-control form-control-sm text-right" placeholder="0.00" value="<?= isset($amount) ? $amount : '' ?>">
            </div>
            <div class="col-md-4 form-group">
                <label for="probability" class="font-weight-bold text-dark text-sm">Win Probability (%)</label>
                <input type="number" id="probability" name="probability" class="form-control form-control-sm" placeholder="20" value="<?= isset($probability) ? $probability : '20' ?>">
            </div>
            <div class="col-md-4 form-group">
                <label for="expected_close_date" class="font-weight-bold text-dark text-sm">Expected Close Date</label>
                <input type="date" id="expected_close_date" name="expected_close_date" class="form-control form-control-sm" value="<?= isset($expected_close_date) ? $expected_close_date : date('Y-m-d', strtotime('+30 days')) ?>">
            </div>
        </div>

        <div class="form-group mb-3">
            <label for="notes" class="font-weight-bold text-dark text-sm">Notes & Activity</label>
            <textarea id="notes" name="notes" rows="3" class="form-control form-control-sm rounded-0" placeholder="Add details about client requirements and discussions..."><?= isset($notes) ? htmlspecialchars($notes) : '' ?></textarea>
        </div>
    </form>
</div>

<script>
$(document).ready(function(){
    $('#uni_modal').on('shown.bs.modal', function(){
        $('.select2').select2({
            dropdownParent: $('#uni_modal')
        });
    });

    $('#uni_modal #opportunity-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_opportunity",
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
                    alert_toast(resp.msg || "Failed to save deal opportunity.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
