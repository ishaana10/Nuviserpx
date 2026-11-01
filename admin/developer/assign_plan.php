<?php
require_once(dirname(__DIR__, 2) . '/config.php');
if ($_settings->userdata('type') != 1) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;
$tenant = [];
if($tenant_id > 0){
    $q = $conn->query("SELECT * FROM `tenants` WHERE id = '{$tenant_id}'");
    if($q && $q->num_rows>0){ $tenant = $q->fetch_assoc(); }
}
if(empty($tenant)){ echo '<div class="alert alert-warning">Tenant not found.</div>'; return; }
?>
<div class="container-fluid">
    <form action="" id="assign-form">
        <input type="hidden" name="tenant_id" value="<?php echo $tenant_id ?>">
        <div class="alert alert-light border small">
            Assigning a plan to <b><?php echo htmlspecialchars($tenant['name']) ?></b> (<?php echo htmlspecialchars($tenant['tenant_code']) ?>)
        </div>
        <div class="form-group">
            <label class="control-label">Plan</label>
            <select name="plan_id" class="form-control form-control-sm rounded-0">
                <option value="">— No plan —</option>
                <?php
                $plans = $conn->query("SELECT * FROM `tenant_plans` WHERE status = 1 ORDER BY sort_order ASC, price ASC");
                while($plans && $p = $plans->fetch_assoc()):
                ?>
                <option value="<?php echo $p['id'] ?>" <?php echo (($tenant['plan_id']??'')==$p['id'])?'selected':'' ?>><?php echo htmlspecialchars($p['name']) ?> — <?php echo htmlspecialchars($p['billing_cycle']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="row">
            <div class="col-md-6 form-group">
                <label class="control-label">Plan Status</label>
                <select name="plan_status" class="form-control form-control-sm rounded-0">
                    <?php foreach(['trial','active','past_due','suspended','cancelled'] as $s): ?>
                    <option value="<?php echo $s ?>" <?php echo (($tenant['plan_status']??'active')===$s)?'selected':'' ?>><?php echo ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label class="control-label">Expires On <small class="text-muted">(optional)</small></label>
                <input type="date" name="plan_expires_at" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($tenant['plan_expires_at'] ?? '') ?>">
            </div>
        </div>
    </form>
</div>
<script>
$('#assign-form').submit(function(e){
    e.preventDefault(); start_loader();
    $.ajax({url:_base_url_+"classes/Master.php?f=assign_plan",data:new FormData(this),cache:false,contentType:false,processData:false,method:'POST',dataType:'json',
        error:err=>{console.log(err);alert_toast("An error occurred",'error');end_loader();},
        success:function(resp){ if(resp && resp.status=='success'){location.reload();}else{alert_toast((resp&&resp.msg)||"Error",'error');end_loader();} }
    });
});
</script>
