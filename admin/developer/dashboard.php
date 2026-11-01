<?php
require_once(dirname(__DIR__, 2) . '/config.php');

use App\Services\DeveloperService;

if ($_settings->userdata('type') != 1) {
    echo '<div class="alert alert-danger font-weight-bold my-4"><i class="fas fa-exclamation-triangle mr-2"></i> Access Denied: Developer module is restricted to Super Administrators.</div>';
    return;
}

$svc = new DeveloperService($conn);
$totals = $svc->platformTotals();
$app = $svc->applicationPerformance();
$server = $svc->serverPerformance();
$tenants = $svc->tenantPerformance();
$apiSeries = $svc->apiCallsSeries(14);
$whSeries = $svc->webhookSeries(14);

function fmt_bytes($b){
    $b = (float)$b; $u = ['B','KB','MB','GB','TB'];
    for($i=0; $b>=1024 && $i<count($u)-1; $i++){ $b/=1024; }
    return round($b,1).' '.$u[$i];
}
$cur = $_settings->info('currency') ?: '$';
?>
<style>
.dev-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.03);height:100%}
.dev-kpi .lbl{font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.dev-kpi .val{font-size:1.6rem;font-weight:800;color:#0f172a;line-height:1.1;margin-top:4px}
.dev-kpi .sub{font-size:.75rem;color:#94a3b8;margin-top:2px}
.dev-section-title{font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#475569;margin:22px 0 12px;display:flex;align-items:center;gap:8px}
.dev-section-title .line{flex:1;height:1px;background:#e2e8f0}
.dev-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.03)}
.meter{height:8px;border-radius:6px;background:#eef2f7;overflow:hidden}
.meter > span{display:block;height:100%;border-radius:6px}
.srv-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #eef2f7;font-size:.85rem}
.srv-row:last-child{border-bottom:none}
.srv-row .k{color:#64748b}
.srv-row .v{font-weight:700;color:#0f172a}
.ext-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;margin:2px}
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="mb-0 font-weight-bold"><i class="fas fa-tachometer-alt text-info mr-2"></i>Developer Dashboard</h4>
    <span class="text-muted small">Platform-wide view · <?php echo date('D, d M Y H:i') ?></span>
</div>

<!-- PLATFORM TOTALS -->
<div class="row">
    <div class="col-md-3 col-6 mb-3"><div class="dev-kpi"><div class="lbl">Tenants</div><div class="val"><?php echo (int)$totals['tenants'] ?></div><div class="sub"><?php echo (int)$totals['active_tenants'] ?> active</div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="dev-kpi"><div class="lbl">Total Users</div><div class="val"><?php echo (int)$totals['users'] ?></div><div class="sub">across all companies</div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="dev-kpi"><div class="lbl">Invoices</div><div class="val"><?php echo number_format((int)$totals['invoices']) ?></div><div class="sub"><?php echo $cur . number_format((float)$totals['revenue'],2) ?> billed</div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="dev-kpi"><div class="lbl">Outstanding</div><div class="val"><?php echo $cur . number_format((float)$totals['outstanding'],0) ?></div><div class="sub">unpaid balance</div></div></div>
</div>

<!-- APPLICATION PERFORMANCE -->
<div class="dev-section-title"><i class="fas fa-bolt text-warning"></i> Application Performance <span class="line"></span></div>
<div class="row">
    <div class="col-md-4 mb-3">
        <div class="dev-card h-100">
            <div class="font-weight-bold mb-2"><i class="fas fa-share-square text-primary"></i> Webhooks</div>
            <div class="srv-row"><span class="k">Active subscriptions</span><span class="v"><?php echo (int)$app['webhooks']['active'] ?> / <?php echo (int)$app['webhooks']['total'] ?></span></div>
            <div class="srv-row"><span class="k">Delivered (24h)</span><span class="v text-success"><?php echo (int)$app['webhooks']['delivered_24h'] ?></span></div>
            <div class="srv-row"><span class="k">Failed (24h)</span><span class="v text-danger"><?php echo (int)$app['webhooks']['failed_24h'] ?></span></div>
            <div class="srv-row"><span class="k">Pending in queue</span><span class="v"><?php echo (int)$app['webhooks']['pending'] ?></span></div>
            <div class="mt-2">
                <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Success rate (24h)</span><span class="font-weight-bold"><?php echo $app['webhooks']['success_rate'] ?>%</span></div>
                <div class="meter"><span style="width:<?php echo $app['webhooks']['success_rate'] ?>%;background:<?php echo $app['webhooks']['success_rate'] >= 95 ? '#22c55e' : ($app['webhooks']['success_rate'] >= 80 ? '#f59e0b' : '#ef4444') ?>"></span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="dev-card h-100">
            <div class="font-weight-bold mb-2"><i class="fas fa-code text-success"></i> REST API</div>
            <div class="srv-row"><span class="k">Active API keys</span><span class="v"><?php echo (int)$app['api']['keys'] ?></span></div>
            <div class="srv-row"><span class="k">Calls (24h)</span><span class="v"><?php echo number_format((int)$app['api']['calls_24h']) ?></span></div>
            <div class="srv-row"><span class="k">Errors (24h)</span><span class="v text-danger"><?php echo (int)$app['api']['errors_24h'] ?></span></div>
            <div class="srv-row"><span class="k">Avg response</span><span class="v"><?php echo $app['api']['avg_ms'] ?> ms</span></div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="dev-card h-100">
            <div class="font-weight-bold mb-2"><i class="fas fa-project-diagram text-info"></i> Workflow Automation</div>
            <div class="srv-row"><span class="k">Active workflows</span><span class="v"><?php echo (int)$app['workflows']['active'] ?> / <?php echo (int)$app['workflows']['total'] ?></span></div>
            <div class="srv-row"><span class="k">Runs (24h)</span><span class="v text-success"><?php echo (int)$app['workflows']['runs_24h'] ?></span></div>
            <div class="srv-row"><span class="k">Failed runs (24h)</span><span class="v text-danger"><?php echo (int)$app['workflows']['failed_24h'] ?></span></div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-3"><div class="dev-card"><div class="font-weight-bold mb-2 small text-muted">API CALLS · LAST 14 DAYS</div><div id="apiChart"></div></div></div>
    <div class="col-md-6 mb-3"><div class="dev-card"><div class="font-weight-bold mb-2 small text-muted">WEBHOOK DELIVERIES · LAST 14 DAYS</div><div id="whChart"></div></div></div>
</div>

<!-- TENANT PERFORMANCE -->
<div class="dev-section-title"><i class="fas fa-building text-primary"></i> Tenant Performance <span class="line"></span></div>
<div class="dev-card p-0">
    <table class="table table-hover table-sm mb-0" id="tenantTable">
        <thead class="thead-light">
            <tr>
                <th>Company</th>
                <th>Plan</th>
                <th class="text-right">Users</th>
                <th class="text-right">Invoices</th>
                <th class="text-right">Revenue</th>
                <th class="text-right">Outstanding</th>
                <th class="text-right">API (30d)</th>
                <th>Usage</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($tenants as $t): ?>
            <tr>
                <td><b><?php echo htmlspecialchars($t['name']) ?></b><div><small class="text-muted"><?php echo htmlspecialchars($t['tenant_code']) ?></small></div></td>
                <td>
                    <?php if(!empty($t['plan_name'])): ?>
                        <span class="badge badge-primary"><?php echo htmlspecialchars($t['plan_name']) ?></span>
                        <?php
                            $ps = $t['plan_status'] ?? 'active';
                            $psb = ['active'=>'success','trial'=>'info','past_due'=>'warning','suspended'=>'danger','cancelled'=>'secondary'][$ps] ?? 'secondary';
                        ?>
                        <div><small class="text-<?php echo $psb ?>"><?php echo ucfirst(str_replace('_',' ',$ps)) ?></small></div>
                    <?php else: ?>
                        <span class="badge badge-light border">No plan</span>
                    <?php endif; ?>
                </td>
                <td class="text-right"><?php echo (int)$t['user_count'] ?><?php echo ((int)$t['max_users']>0)?' / '.(int)$t['max_users']:'' ?></td>
                <td class="text-right"><?php echo number_format((int)$t['invoice_count']) ?></td>
                <td class="text-right"><?php echo $cur . number_format((float)$t['revenue'],0) ?></td>
                <td class="text-right"><?php echo $cur . number_format((float)$t['outstanding'],0) ?></td>
                <td class="text-right"><?php echo number_format((int)$t['api_calls_30d']) ?></td>
                <td style="min-width:90px">
                    <?php if((int)$t['max_invoices']>0): ?>
                        <div class="meter"><span style="width:<?php echo (int)$t['usage_pct'] ?>%;background:<?php echo $t['usage_pct']>=90?'#ef4444':($t['usage_pct']>=70?'#f59e0b':'#3b82f6') ?>"></span></div>
                        <small class="text-muted"><?php echo (int)$t['usage_pct'] ?>% of invoice quota</small>
                    <?php else: ?><small class="text-muted">—</small><?php endif; ?>
                </td>
                <td class="text-center"><?php echo $t['status']==1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- SERVER PERFORMANCE -->
<div class="dev-section-title"><i class="fas fa-server text-danger"></i> Server Performance <span class="line"></span></div>
<div class="row">
    <div class="col-md-4 mb-3">
        <div class="dev-card h-100">
            <div class="font-weight-bold mb-2">Runtime</div>
            <div class="srv-row"><span class="k">PHP</span><span class="v"><?php echo htmlspecialchars($server['php_version']) ?> (<?php echo htmlspecialchars($server['php_sapi']) ?>)</span></div>
            <div class="srv-row"><span class="k">MySQL</span><span class="v"><?php echo htmlspecialchars($server['mysql_version']) ?></span></div>
            <div class="srv-row"><span class="k">OS</span><span class="v"><?php echo htmlspecialchars($server['os']) ?></span></div>
            <div class="srv-row"><span class="k">Web server</span><span class="v"><?php echo htmlspecialchars(mb_strimwidth((string)$server['server_software'],0,28,'…')) ?></span></div>
            <?php if(is_array($server['load_avg'])): ?>
            <div class="srv-row"><span class="k">Load avg</span><span class="v"><?php echo implode(' · ', array_map(fn($l)=>number_format($l,2), $server['load_avg'])) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="dev-card h-100">
            <div class="font-weight-bold mb-2">Storage</div>
            <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Disk used</span><span class="font-weight-bold"><?php echo $server['disk_pct'] ?>%</span></div>
            <div class="meter mb-2"><span style="width:<?php echo $server['disk_pct'] ?>%;background:<?php echo $server['disk_pct']>=90?'#ef4444':($server['disk_pct']>=75?'#f59e0b':'#22c55e') ?>"></span></div>
            <div class="srv-row"><span class="k">Disk total</span><span class="v"><?php echo fmt_bytes($server['disk_total']) ?></span></div>
            <div class="srv-row"><span class="k">Disk free</span><span class="v"><?php echo fmt_bytes($server['disk_free']) ?></span></div>
            <div class="srv-row"><span class="k">Database size</span><span class="v"><?php echo $server['db_size_mb'] ?> MB (<?php echo (int)$server['table_count'] ?> tables)</span></div>
            <div class="srv-row"><span class="k">Uploads</span><span class="v"><?php echo $server['uploads_mb'] ?> MB</span></div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="dev-card h-100">
            <div class="font-weight-bold mb-2">PHP Runtime & Extensions</div>
            <div class="srv-row"><span class="k">Memory limit</span><span class="v"><?php echo htmlspecialchars((string)$server['mem_limit']) ?></span></div>
            <div class="srv-row"><span class="k">Memory in use</span><span class="v"><?php echo fmt_bytes($server['mem_usage']) ?></span></div>
            <div class="srv-row"><span class="k">Peak memory</span><span class="v"><?php echo fmt_bytes($server['mem_peak']) ?></span></div>
            <div class="srv-row"><span class="k">OPcache</span><span class="v"><?php echo $server['opcache'] ? '<span class="text-success">On</span>' : '<span class="text-muted">Off</span>' ?></span></div>
            <div class="mt-2">
                <?php foreach($server['extensions'] as $ext=>$on): ?>
                    <span class="ext-badge" style="background:<?php echo $on?'#dcfce7':'#fee2e2' ?>;color:<?php echo $on?'#166534':'#991b1b' ?>"><?php echo $ext ?> <?php echo $on?'✓':'✗' ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(function(){
    if ($.fn.DataTable.isDataTable('#tenantTable')) { $('#tenantTable').DataTable().destroy(); }
    $('#tenantTable').dataTable({ order:[], pageLength:10, lengthChange:false });

    var apiOpts = {
        chart:{type:'area',height:220,toolbar:{show:false},sparkline:{enabled:false}},
        series:[{name:'API calls',data:<?php echo json_encode($apiSeries['values']) ?>}],
        xaxis:{categories:<?php echo json_encode($apiSeries['labels']) ?>,labels:{style:{fontSize:'10px'}}},
        colors:['#3b82f6'], dataLabels:{enabled:false}, stroke:{curve:'smooth',width:2},
        fill:{type:'gradient',gradient:{opacityFrom:.4,opacityTo:.05}},
        grid:{borderColor:'#f1f5f9'}
    };
    new ApexCharts(document.querySelector("#apiChart"), apiOpts).render();

    var whOpts = {
        chart:{type:'bar',height:220,toolbar:{show:false},stacked:true},
        series:[
            {name:'Delivered',data:<?php echo json_encode($whSeries['delivered']) ?>},
            {name:'Failed',data:<?php echo json_encode($whSeries['failed']) ?>}
        ],
        xaxis:{categories:<?php echo json_encode($whSeries['labels']) ?>,labels:{style:{fontSize:'10px'}}},
        colors:['#22c55e','#ef4444'], dataLabels:{enabled:false},
        plotOptions:{bar:{columnWidth:'55%',borderRadius:3}}, legend:{position:'top',fontSize:'11px'},
        grid:{borderColor:'#f1f5f9'}
    };
    new ApexCharts(document.querySelector("#whChart"), whOpts).render();
});
</script>
