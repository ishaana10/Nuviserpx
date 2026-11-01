<?php
require_once(dirname(__DIR__, 2) . '/config.php');
if ($_settings->userdata('type') != 1) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

$row = [];
if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $id = intval($_GET['id']);
    $q = $conn->query("SELECT * FROM `tenant_plans` WHERE id = '{$id}'");
    if($q && $q->num_rows>0){ $row = $q->fetch_assoc(); }
}
$features = json_decode($row['features'] ?? '[]', true); $features = is_array($features)?$features:[];
?>
<div class="container-fluid">
    <form action="" id="plan-form">
        <input type="hidden" name="id" value="<?php echo $row['id'] ?? '' ?>">
        <div class="row">
            <div class="col-md-7 form-group">
                <label class="control-label">Plan Name</label>
                <input type="text" name="name" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($row['name'] ?? '') ?>" required>
            </div>
            <div class="col-md-5 form-group">
                <label class="control-label">Code <small class="text-muted">(unique)</small></label>
                <input type="text" name="code" class="form-control form-control-sm rounded-0 text-monospace" value="<?php echo htmlspecialchars($row['code'] ?? '') ?>" placeholder="e.g. professional" required <?php echo !empty($row['id'])?'':'' ?>>
            </div>
        </div>
        <div class="form-group">
            <label class="control-label">Description</label>
            <input type="text" name="description" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($row['description'] ?? '') ?>">
        </div>
        <div class="row">
            <div class="col-md-6 form-group">
                <label class="control-label">Price</label>
                <input type="number" step="0.01" min="0" name="price" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars((string)($row['price'] ?? '0')) ?>" required>
            </div>
            <div class="col-md-6 form-group">
                <label class="control-label">Billing Cycle</label>
                <select name="billing_cycle" class="form-control form-control-sm rounded-0">
                    <?php foreach(['monthly','quarterly','yearly','one_time'] as $c): ?>
                    <option value="<?php echo $c ?>" <?php echo (($row['billing_cycle']??'monthly')===$c)?'selected':'' ?>><?php echo ucfirst(str_replace('_',' ',$c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 form-group">
                <label class="control-label">Max Users <small class="text-muted">(0=∞)</small></label>
                <input type="number" min="0" name="max_users" class="form-control form-control-sm rounded-0" value="<?php echo (int)($row['max_users'] ?? 0) ?>">
            </div>
            <div class="col-md-4 form-group">
                <label class="control-label">Max Invoices <small class="text-muted">(0=∞)</small></label>
                <input type="number" min="0" name="max_invoices" class="form-control form-control-sm rounded-0" value="<?php echo (int)($row['max_invoices'] ?? 0) ?>">
            </div>
            <div class="col-md-4 form-group">
                <label class="control-label">Max API Calls <small class="text-muted">(0=∞)</small></label>
                <input type="number" min="0" name="max_api_calls" class="form-control form-control-sm rounded-0" value="<?php echo (int)($row['max_api_calls'] ?? 0) ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="control-label">Features <small class="text-muted">(one per line)</small></label>
            <textarea name="features" rows="4" class="form-control form-control-sm rounded-0"><?php echo htmlspecialchars(implode("\n", $features)) ?></textarea>
        </div>
        <div class="row">
            <div class="col-md-6 form-group">
                <label class="control-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control form-control-sm rounded-0" value="<?php echo (int)($row['sort_order'] ?? 0) ?>">
            </div>
            <div class="col-md-6 form-group">
                <label class="control-label">Status</label>
                <select name="status" class="form-control form-control-sm rounded-0">
                    <option value="1" <?php echo (!isset($row['status'])||$row['status']==1)?'selected':'' ?>>Active</option>
                    <option value="0" <?php echo (isset($row['status'])&&$row['status']==0)?'selected':'' ?>>Inactive</option>
                </select>
            </div>
        </div>
    </form>
</div>
<script>
$('#plan-form').submit(function(e){
    e.preventDefault(); $('.err-msg').remove(); start_loader();
    $.ajax({url:_base_url_+"classes/Master.php?f=save_plan",data:new FormData(this),cache:false,contentType:false,processData:false,method:'POST',dataType:'json',
        error:err=>{console.log(err);alert_toast("An error occurred",'error');end_loader();},
        success:function(resp){
            if(resp && resp.status=='success'){ location.reload(); }
            else if(resp && resp.msg){ var el=$('<div>').addClass("alert alert-danger err-msg").text(resp.msg); $('#plan-form').prepend(el); end_loader(); }
            else{ alert_toast("An error occurred",'error'); end_loader(); }
        }
    });
});
</script>
