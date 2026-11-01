<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\CrmService;

$tenant_id = $_settings->active_tenant_id();
$svc = new CrmService($conn, $_settings);
$pipe = $svc->pipelineSummary();

$stages = CrmService::STAGES;
$stage_labels = [
    'qualification' => 'Qualification',
    'proposal' => 'Proposal Sent',
    'negotiation' => 'Negotiation',
    'won' => 'Closed Won',
    'lost' => 'Closed Lost',
];
$stage_badge = [
    'qualification' => 'badge-info',
    'proposal' => 'badge-primary',
    'negotiation' => 'badge-warning',
    'won' => 'badge-success',
    'lost' => 'badge-danger',
];

// Opportunities grouped by stage, with open-activity counts (overdue flag).
$opps_by_stage = array_fill_keys($stages, []);
$qry = $conn->query("
    SELECT o.*, p.name AS party_name,
        (SELECT COUNT(*) FROM crm_activities a WHERE a.opportunity_id = o.id AND a.is_done = 0) AS open_acts,
        (SELECT COUNT(*) FROM crm_activities a WHERE a.opportunity_id = o.id AND a.is_done = 0 AND a.due_date IS NOT NULL AND a.due_date < CURDATE()) AS overdue_acts
    FROM `crm_opportunities` o
    LEFT JOIN `party_list` p ON o.party_id = p.id
    WHERE o.tenant_id = '{$tenant_id}'
    ORDER BY o.id DESC
");
while ($row = $qry->fetch_assoc()) {
    if (isset($opps_by_stage[$row['stage']])) {
        $opps_by_stage[$row['stage']][] = $row;
    }
}
?>

<style>
.crm-pipeline-container { display:flex; gap:14px; overflow-x:auto; padding-bottom:12px; }
.crm-stage-column { flex:1; min-width:250px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; transition:background .15s, box-shadow .15s; }
.crm-stage-column.drag-over { background:#eff6ff; box-shadow:inset 0 0 0 2px #93c5fd; }
.crm-column-header { font-weight:700; font-size:.9rem; color:#0f172a; padding-bottom:8px; border-bottom:2px solid #cbd5e1; margin-bottom:10px; }
.crm-column-sub { font-size:.72rem; color:#64748b; font-weight:600; margin-top:2px; }
.opp-card { background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:9px; box-shadow:0 1px 2px rgba(0,0,0,.05); cursor:grab; transition:all .15s ease; }
.opp-card:hover { box-shadow:0 4px 6px -1px rgba(0,0,0,.1); border-color:#cbd5e1; }
.opp-card.dragging { opacity:.5; }
.opp-title { font-weight:700; font-size:.87rem; color:#1e293b; }
.opp-amount { font-weight:700; color:#0284c7; font-size:.92rem; }
.opp-meta { color:#64748b; font-size:.74rem; }
.opp-flag { font-size:.68rem; font-weight:700; padding:1px 6px; border-radius:999px; }
.opp-flag.due { background:#fef9c3; color:#854d0e; } .opp-flag.overdue { background:#fee2e2; color:#b91c1c; }
</style>

<?php if ($_settings->chk_flashdata('success')): ?>
<script>alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')</script>
<?php endif; ?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-handshake text-primary mr-2"></i> Sales Pipeline</h3>
        <div class="card-tools">
            <a href="./?page=reports/sales_pipeline" class="btn btn-sm btn-outline-secondary btn-flat mr-1"><i class="fas fa-chart-bar"></i> Pipeline Report</a>
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_new_opportunity"><i class="fas fa-plus mr-1"></i> New Opportunity</button>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-info elevation-1"><i class="fas fa-funnel-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Open Pipeline</span>
                        <span class="info-box-number text-dark h5 mb-0">$<?= number_format($pipe['open_value'], 2) ?></span>
                        <span class="progress-description text-muted"><?= $pipe['open_count'] ?> open deals</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-balance-scale-right"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Weighted Forecast</span>
                        <span class="info-box-number text-dark h5 mb-0">$<?= number_format($pipe['open_weighted'], 2) ?></span>
                        <span class="progress-description text-muted">probability-adjusted</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-trophy"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Closed Won</span>
                        <span class="info-box-number text-success h5 mb-0">$<?= number_format($pipe['won_value'], 2) ?></span>
                        <span class="progress-description text-muted"><?= $pipe['won_count'] ?> deals won</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-navy elevation-1"><i class="fas fa-percentage"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Win Rate</span>
                        <span class="info-box-number text-navy h5 mb-0"><?= $pipe['win_rate'] === null ? '–' : number_format($pipe['win_rate'], 0) . '%' ?></span>
                        <span class="progress-description text-muted"><?= $pipe['won_count'] ?> won / <?= $pipe['lost_count'] ?> lost</span>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-muted small mb-2"><i class="fas fa-arrows-alt mr-1"></i> Drag a card to move it between stages.</p>
        <div class="crm-pipeline-container">
            <?php foreach ($stages as $stg):
                $stage_value = $pipe['by_stage'][$stg]['value'] ?? 0; ?>
                <div class="crm-stage-column" data-stage="<?= $stg ?>">
                    <div class="crm-column-header">
                        <div class="d-flex align-items-center justify-content-between">
                            <span><?= $stage_labels[$stg] ?></span>
                            <span class="badge <?= $stage_badge[$stg] ?>"><?= count($opps_by_stage[$stg]) ?></span>
                        </div>
                        <div class="crm-column-sub">$<?= number_format($stage_value, 2) ?></div>
                    </div>
                    <div class="crm-dropzone" data-stage="<?= $stg ?>">
                    <?php if (!empty($opps_by_stage[$stg])): foreach ($opps_by_stage[$stg] as $o): ?>
                        <div class="opp-card" draggable="true" data-id="<?= $o['id'] ?>">
                            <div class="d-flex align-items-start justify-content-between">
                                <a href="./?page=crm/view&id=<?= $o['id'] ?>" class="opp-title text-decoration-none" style="color:inherit"><?= htmlspecialchars($o['title']) ?></a>
                            </div>
                            <div class="opp-meta mb-2"><i class="fas fa-building mr-1"></i> <?= htmlspecialchars($o['party_name'] ?: 'General Contact') ?></div>
                            <div class="d-flex align-items-center justify-content-between border-top pt-2">
                                <span class="opp-amount">$<?= number_format($o['amount'], 2) ?></span>
                                <small class="badge badge-light border"><?= (int)$o['probability'] ?>%</small>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-1">
                                <?php if ($o['expected_close_date']): ?>
                                    <span class="opp-meta"><i class="far fa-calendar-alt mr-1"></i> <?= date("d M Y", strtotime($o['expected_close_date'])) ?></span>
                                <?php else: ?><span></span><?php endif; ?>
                                <?php if ((int)$o['overdue_acts'] > 0): ?>
                                    <span class="opp-flag overdue"><?= (int)$o['overdue_acts'] ?> overdue</span>
                                <?php elseif ((int)$o['open_acts'] > 0): ?>
                                    <span class="opp-flag due"><?= (int)$o['open_acts'] ?> to do</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; else: ?>
                        <div class="text-center py-4 text-muted text-xs crm-empty">Drop deals here.</div>
                    <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#btn_new_opportunity').click(function(){
        uni_modal("New Opportunity Deal", "crm/manage_opportunity.php", "mid-large");
    });

    var dragId = null;

    $(document).on('dragstart', '.opp-card', function(e){
        dragId = $(this).data('id');
        $(this).addClass('dragging');
        e.originalEvent.dataTransfer.effectAllowed = 'move';
    });
    $(document).on('dragend', '.opp-card', function(){ $(this).removeClass('dragging'); dragId = null; });

    $(document).on('dragover', '.crm-stage-column', function(e){ e.preventDefault(); $(this).addClass('drag-over'); });
    $(document).on('dragleave', '.crm-stage-column', function(){ $(this).removeClass('drag-over'); });

    $(document).on('drop', '.crm-stage-column', function(e){
        e.preventDefault();
        $(this).removeClass('drag-over');
        var newStage = $(this).data('stage');
        var id = dragId;
        if (!id) return;
        var $card = $('.opp-card[data-id="'+id+'"]');
        // optimistic move
        $(this).find('.crm-dropzone').append($card);
        $('.crm-empty').remove();
        $.ajax({
            url:_base_url_+"classes/Master.php?f=move_opportunity_stage",
            method:"POST", data:{ id:id, stage:newStage }, dataType:"json",
            error:err=>{ console.log(err); alert_toast("Could not move the deal.",'error'); location.reload(); },
            success:function(resp){
                if(resp.status=='success'){ location.reload(); }
                else { alert_toast(resp.msg || "Could not move the deal.",'error'); location.reload(); }
            }
        });
    });
});
</script>
