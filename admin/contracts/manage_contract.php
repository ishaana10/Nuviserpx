<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id > 0){
    $qry = $conn->query("SELECT * FROM `contracts` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        $res = $qry->fetch_assoc();
        foreach($res as $k => $v){
            $$k = $v;
        }
    }
}
?>

<div class="container-fluid">
    <form action="" id="contract-form">
        <input type="hidden" name="id" value="<?= isset($id) ? $id : '' ?>">

        <div class="form-group mb-3">
            <label for="title" class="font-weight-bold text-dark text-sm">Contract / Service Title <span class="text-danger">*</span></label>
            <input type="text" id="title" name="title" class="form-control form-control-sm rounded-0" placeholder="e.g. Monthly SLA Maintenance Agreement" value="<?= isset($title) ? htmlspecialchars($title) : '' ?>" required>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="party_id" class="font-weight-bold text-dark text-sm">Customer <span class="text-danger">*</span></label>
                <select name="party_id" id="party_id" class="form-control form-control-sm select2" style="width: 100%;" required>
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
                <label for="billing_frequency" class="font-weight-bold text-dark text-sm">Billing Frequency</label>
                <select name="billing_frequency" id="billing_frequency" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="monthly" <?= (!isset($billing_frequency) || $billing_frequency == 'monthly') ? 'selected' : '' ?>>Monthly</option>
                    <option value="quarterly" <?= (isset($billing_frequency) && $billing_frequency == 'quarterly') ? 'selected' : '' ?>>Quarterly</option>
                    <option value="annual" <?= (isset($billing_frequency) && $billing_frequency == 'annual') ? 'selected' : '' ?>>Annual</option>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 form-group">
                <label for="amount" class="font-weight-bold text-dark text-sm">Recurring Amount ($) <span class="text-danger">*</span></label>
                <input type="number" step="any" id="amount" name="amount" class="form-control form-control-sm text-right" placeholder="0.00" value="<?= isset($amount) ? $amount : '' ?>" required>
            </div>
            <div class="col-md-4 form-group">
                <label for="start_date" class="font-weight-bold text-dark text-sm">Start Date <span class="text-danger">*</span></label>
                <input type="date" id="start_date" name="start_date" class="form-control form-control-sm" value="<?= isset($start_date) ? $start_date : date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4 form-group">
                <label for="end_date" class="font-weight-bold text-dark text-sm">End Date <small class="text-muted font-weight-normal">(optional)</small></label>
                <input type="date" id="end_date" name="end_date" class="form-control form-control-sm" value="<?= isset($end_date) ? $end_date : '' ?>">
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="status" class="font-weight-bold text-dark text-sm">Status</label>
                <select name="status" id="status" class="form-control form-control-sm select2" style="width: 100%;">
                    <option value="active" <?= (!isset($status) || $status == 'active') ? 'selected' : '' ?>>Active</option>
                    <option value="draft" <?= (isset($status) && $status == 'draft') ? 'selected' : '' ?>>Draft</option>
                    <option value="expired" <?= (isset($status) && $status == 'expired') ? 'selected' : '' ?>>Expired</option>
                    <option value="cancelled" <?= (isset($status) && $status == 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label for="notes" class="font-weight-bold text-dark text-sm">Notes / SLA Terms</label>
                <input type="text" id="notes" name="notes" class="form-control form-control-sm rounded-0" placeholder="Special terms or notes..." value="<?= isset($notes) ? htmlspecialchars($notes) : '' ?>">
            </div>
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

    $('#uni_modal #contract-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_contract",
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
                    alert_toast(resp.msg || "Failed to save contract.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
