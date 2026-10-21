<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

// Retrieve all CRM opportunities grouped by stage
$stages = ['qualification', 'proposal', 'negotiation', 'won', 'lost'];
$stage_labels = [
    'qualification' => 'Qualification',
    'proposal' => 'Proposal Sent',
    'negotiation' => 'Negotiation',
    'won' => 'Closed Won',
    'lost' => 'Closed Lost'
];
$stage_badge = [
    'qualification' => 'badge-info',
    'proposal' => 'badge-primary',
    'negotiation' => 'badge-warning',
    'won' => 'badge-success',
    'lost' => 'badge-danger'
];

$opps_by_stage = [];
foreach($stages as $s){
    $opps_by_stage[$s] = [];
}

$qry = $conn->query("
    SELECT o.*, p.name as party_name
    FROM `crm_opportunities` o
    LEFT JOIN `party_list` p ON o.party_id = p.id
    WHERE o.tenant_id = '{$tenant_id}'
    ORDER BY o.id DESC
");

$total_pipeline_val = 0;
$total_won_val = 0;
$opp_count = 0;

while($row = $qry->fetch_assoc()){
    $opp_count++;
    $opps_by_stage[$row['stage']][] = $row;
    if($row['stage'] !== 'lost'){
        $total_pipeline_val += floatval($row['amount']);
    }
    if($row['stage'] === 'won'){
        $total_won_val += floatval($row['amount']);
    }
}
?>

<style>
.crm-pipeline-container {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 12px;
}
.crm-stage-column {
    flex: 1;
    min-width: 250px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
}
.crm-column-header {
    font-weight: 700;
    font-size: 0.95rem;
    color: #0f172a;
    padding-bottom: 8px;
    border-bottom: 2px solid #cbd5e1;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.opp-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    transition: all 0.15s ease;
}
.opp-card:hover {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}
.opp-title {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1e293b;
    margin-bottom: 4px;
}
.opp-amount {
    font-weight: 700;
    color: #0284c7;
    font-size: 0.95rem;
}
</style>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-handshake text-primary mr-2"></i> CRM Opportunities & Sales Pipeline</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_new_opportunity" onclick="uni_modal('New Opportunity Deal', 'crm/manage_opportunity.php', 'mid-large')"><i class="fas fa-plus mr-1"></i> New Opportunity</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Summary Metric Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-info elevation-1"><i class="fas fa-funnel-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Total Pipeline Value</span>
                        <span class="info-box-number text-dark h4 mb-0">$<?= number_format($total_pipeline_val, 2) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-trophy"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Closed Won Revenue</span>
                        <span class="info-box-number text-success h4 mb-0">$<?= number_format($total_won_val, 2) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-navy elevation-1"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Active Opportunities</span>
                        <span class="info-box-number text-navy h4 mb-0"><?= $opp_count ?> Deals</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanban Pipeline Board -->
        <div class="crm-pipeline-container">
            <?php foreach($stages as $stg): ?>
                <div class="crm-stage-column">
                    <div class="crm-column-header">
                        <span><?= $stage_labels[$stg] ?></span>
                        <span class="badge <?= $stage_badge[$stg] ?>"><?= count($opps_by_stage[$stg]) ?></span>
                    </div>

                    <?php if(!empty($opps_by_stage[$stg])): ?>
                        <?php foreach($opps_by_stage[$stg] as $o): ?>
                            <div class="opp-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span class="opp-title"><?= htmlspecialchars($o['title']) ?></span>
                                    <button class="btn btn-xs text-muted edit_opp" data-id="<?= $o['id'] ?>"><i class="fas fa-edit"></i></button>
                                </div>
                                <div class="text-muted text-xs mb-2">
                                    <i class="fas fa-building mr-1"></i> <?= htmlspecialchars($o['party_name'] ?: 'General Contact') ?>
                                </div>
                                <div class="d-flex align-items-center justify-content-between border-top pt-2">
                                    <span class="opp-amount">$<?= number_format($o['amount'], 2) ?></span>
                                    <small class="badge badge-light border"><?= $o['probability'] ?>% Win</small>
                                </div>
                                <?php if($o['expected_close_date']): ?>
                                    <div class="text-muted text-xs mt-1">
                                        <i class="far fa-calendar-alt mr-1"></i> <?= date("d M Y", strtotime($o['expected_close_date'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted text-xs">No deals in stage.</div>
                    <?php endif; ?>
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

    $('.edit_opp').click(function(){
        uni_modal("Edit Opportunity Deal", "crm/manage_opportunity.php?id=" + $(this).data('id'), "mid-large");
    });
});
</script>
