<?php
require_once(dirname(__DIR__, 2) . '/config.php');

if ($_settings->userdata('type') != 1) {
    echo '<div class="alert alert-danger font-weight-bold my-4"><i class="fas fa-exclamation-triangle mr-2"></i> Access Denied: restricted to Super Administrators.</div>';
    return;
}
$cur = $_settings->info('currency') ?: '$';
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')</script>
<?php endif;?>

<h4 class="mb-3 font-weight-bold"><i class="fas fa-layer-group text-primary mr-2"></i>Customer Plans</h4>

<!-- PLAN CATALOGUE -->
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title">Subscription Plans</h3>
        <div class="card-tools">
            <a href="javascript:void(0)" onclick="uni_modal('<i class=\'fa fa-plus\'></i> New Plan','developer/manage_plan.php')" class="btn btn-flat btn-primary btn-sm"><i class="fas fa-plus"></i> Add Plan</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <?php
            $plans = $conn->query("SELECT p.*, (SELECT COUNT(*) FROM `tenants` t WHERE t.plan_id = p.id AND t.delete_flag = 0) AS subscribers FROM `tenant_plans` p ORDER BY p.sort_order ASC, p.price ASC");
            while($plans && $p = $plans->fetch_assoc()):
                $features = json_decode($p['features'] ?: '[]', true); $features = is_array($features)?$features:[];
            ?>
            <div class="col-md-4 mb-3">
                <div class="border rounded p-3 h-100 position-relative" style="border-color:#e2e8f0!important">
                    <?php if($p['status']==0): ?><span class="badge badge-secondary position-absolute" style="top:10px;right:10px">Inactive</span><?php endif; ?>
                    <h5 class="font-weight-bold mb-0"><?php echo htmlspecialchars($p['name']) ?></h5>
                    <div class="text-muted small mb-2"><code><?php echo htmlspecialchars($p['code']) ?></code></div>
                    <div class="mb-2"><span style="font-size:1.6rem;font-weight:800"><?php echo $cur . number_format((float)$p['price'],2) ?></span><small class="text-muted">/<?php echo htmlspecialchars($p['billing_cycle']) ?></small></div>
                    <p class="text-muted small"><?php echo htmlspecialchars($p['description']) ?></p>
                    <ul class="list-unstyled small mb-2">
                        <li><i class="fas fa-users text-muted mr-1"></i> <?php echo ((int)$p['max_users']>0)?(int)$p['max_users'].' users':'Unlimited users' ?></li>
                        <li><i class="fas fa-file-invoice text-muted mr-1"></i> <?php echo ((int)$p['max_invoices']>0)?number_format((int)$p['max_invoices']).' invoices':'Unlimited invoices' ?></li>
                        <li><i class="fas fa-code text-muted mr-1"></i> <?php echo ((int)$p['max_api_calls']>0)?number_format((int)$p['max_api_calls']).' API calls':'Unlimited API' ?></li>
                    </ul>
                    <?php if($features): ?><ul class="small pl-3 mb-2"><?php foreach($features as $f): ?><li><?php echo htmlspecialchars($f) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                        <small class="text-muted"><b><?php echo (int)$p['subscribers'] ?></b> subscriber(s)</small>
                        <div>
                            <a href="javascript:void(0)" class="btn btn-xs btn-outline-primary" onclick="uni_modal('<i class=\'fa fa-edit\'></i> Edit Plan','developer/manage_plan.php?id=<?php echo $p['id'] ?>')"><i class="fas fa-edit"></i></a>
                            <a href="javascript:void(0)" class="btn btn-xs btn-outline-danger del-plan" data-id="<?php echo $p['id'] ?>"><i class="fas fa-trash"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<!-- TENANT ASSIGNMENT -->
<div class="card card-outline card-secondary">
    <div class="card-header"><h3 class="card-title">Tenant Plan Assignments</h3></div>
    <div class="card-body">
        <table class="table table-hover table-bordered table-sm" id="assignTable">
            <thead class="thead-light">
                <tr><th>Company</th><th>Current Plan</th><th class="text-center">Status</th><th>Expires</th><th class="text-center">Action</th></tr>
            </thead>
            <tbody>
                <?php
                $ts = $conn->query("SELECT t.*, p.name AS plan_name FROM `tenants` t LEFT JOIN `tenant_plans` p ON t.plan_id = p.id WHERE t.delete_flag = 0 ORDER BY t.name ASC");
                while($ts && $t = $ts->fetch_assoc()):
                    $ps = $t['plan_status'] ?? 'active';
                    $psb = ['active'=>'success','trial'=>'info','past_due'=>'warning','suspended'=>'danger','cancelled'=>'secondary'][$ps] ?? 'secondary';
                ?>
                <tr>
                    <td><b><?php echo htmlspecialchars($t['name']) ?></b> <small class="text-muted"><?php echo htmlspecialchars($t['tenant_code']) ?></small></td>
                    <td><?php echo $t['plan_name'] ? '<span class="badge badge-primary">'.htmlspecialchars($t['plan_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><span class="badge badge-<?php echo $psb ?>"><?php echo ucfirst(str_replace('_',' ',$ps)) ?></span></td>
                    <td><small><?php echo $t['plan_expires_at'] ?: '—' ?></small></td>
                    <td class="text-center">
                        <a href="javascript:void(0)" class="btn btn-xs btn-outline-primary" onclick="uni_modal('<i class=\'fa fa-user-tag\'></i> Assign Plan','developer/assign_plan.php?tenant_id=<?php echo $t['id'] ?>')"><i class="fas fa-user-tag"></i> Assign</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(function(){
    if ($.fn.DataTable.isDataTable('#assignTable')) { $('#assignTable').DataTable().destroy(); }
    $('#assignTable').dataTable({ order:[], pageLength:10, lengthChange:false });
    $(document).on('click','.del-plan',function(){
        _conf("Delete this plan? Tenants on it will be unassigned.","delete_plan",[$(this).attr('data-id')]);
    });
});
function delete_plan($id){
    start_loader();
    $.ajax({url:_base_url_+"classes/Master.php?f=delete_plan",method:"POST",data:{id:$id},dataType:"json",
        error:()=>{alert_toast("An error occurred.",'error');end_loader();},
        success:function(resp){ if(resp && resp.status=='success'){location.reload();}else{alert_toast((resp&&resp.msg)||"Error",'error');end_loader();} }
    });
}
</script>
