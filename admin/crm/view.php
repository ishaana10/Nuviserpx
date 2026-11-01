<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$opp = $conn->query("SELECT o.*, p.name party_name, p.email party_email, p.phone party_phone
    FROM `crm_opportunities` o LEFT JOIN `party_list` p ON o.party_id = p.id
    WHERE o.id = '{$id}' AND o.tenant_id = '{$tenant_id}'");
if (!$opp || $opp->num_rows == 0) { echo '<div class="alert alert-danger">Opportunity not found.</div>'; return; }
$opp = $opp->fetch_assoc();

$stage_labels = ['qualification'=>'Qualification','proposal'=>'Proposal Sent','negotiation'=>'Negotiation','won'=>'Closed Won','lost'=>'Closed Lost'];
$stage_badge = ['qualification'=>'info','proposal'=>'primary','negotiation'=>'warning','won'=>'success','lost'=>'danger'];
$type_icon = ['call'=>'fa-phone','meeting'=>'fa-users','email'=>'fa-envelope','task'=>'fa-check-square','note'=>'fa-sticky-note'];

$acts = $conn->query("SELECT * FROM `crm_activities` WHERE opportunity_id = '{$id}' AND tenant_id = '{$tenant_id}' ORDER BY is_done ASC, (due_date IS NULL), due_date ASC, id DESC");

$weighted = round((float)$opp['amount'] * (int)$opp['probability'] / 100, 2);
?>
<div class="row">
    <div class="col-lg-5">
        <div class="card card-outline card-primary shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title font-weight-bold m-0"><i class="fas fa-handshake text-primary"></i> Deal</h3>
                <a href="./?page=crm" class="btn btn-sm btn-default btn-flat"><i class="fas fa-arrow-left"></i> Pipeline</a>
            </div>
            <div class="card-body">
                <h4 class="font-weight-bold"><?= htmlspecialchars($opp['title']) ?></h4>
                <span class="badge badge-<?= $stage_badge[$opp['stage']] ?> px-2 py-1 mb-3"><?= strtoupper($stage_labels[$opp['stage']]) ?></span>

                <table class="table table-sm mb-3">
                    <tr><th class="text-muted" style="width:45%">Customer</th><td><?= htmlspecialchars($opp['party_name'] ?: 'General Contact') ?></td></tr>
                    <tr><th class="text-muted">Deal value</th><td class="font-weight-bold">$<?= number_format((float)$opp['amount'], 2) ?></td></tr>
                    <tr><th class="text-muted">Probability</th><td><?= (int)$opp['probability'] ?>%</td></tr>
                    <tr><th class="text-muted">Weighted value</th><td>$<?= number_format($weighted, 2) ?></td></tr>
                    <tr><th class="text-muted">Expected close</th><td><?= $opp['expected_close_date'] ? date("d M Y", strtotime($opp['expected_close_date'])) : '—' ?></td></tr>
                </table>

                <?php if (!empty($opp['notes'])): ?>
                <div class="mb-3"><b class="text-muted small">Notes</b><p class="small mb-0"><?= nl2br(htmlspecialchars($opp['notes'])) ?></p></div>
                <?php endif; ?>

                <div class="border-top pt-3">
                    <button class="btn btn-sm btn-primary btn-flat" id="btn_edit"><i class="fas fa-edit"></i> Edit</button>
                    <?php if ($opp['stage'] !== 'won' && $opp['stage'] !== 'lost'): ?>
                    <button class="btn btn-sm btn-success btn-flat stage-btn" data-stage="won"><i class="fas fa-trophy"></i> Mark Won</button>
                    <button class="btn btn-sm btn-outline-danger btn-flat stage-btn" data-stage="lost"><i class="fas fa-times"></i> Mark Lost</button>
                    <?php endif; ?>
                </div>

                <?php if ($opp['stage'] === 'won'): ?>
                <div class="border-top pt-3 mt-3">
                    <?php if (!empty($opp['invoice_id'])): ?>
                        <a href="./?page=invoices/view&id=<?= (int)$opp['invoice_id'] ?>" class="btn btn-sm btn-outline-success btn-flat btn-block"><i class="fas fa-file-invoice"></i> View Invoice #<?= (int)$opp['invoice_id'] ?></a>
                    <?php else: ?>
                        <button class="btn btn-sm btn-success btn-flat btn-block" id="btn_convert"><i class="fas fa-file-invoice-dollar"></i> Convert to Invoice</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-outline card-info shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title font-weight-bold m-0"><i class="fas fa-tasks text-info"></i> Follow-ups &amp; Activities</h3>
                <button class="btn btn-sm btn-info btn-flat" id="btn_add_activity"><i class="fas fa-plus"></i> Add Activity</button>
            </div>
            <div class="card-body">
                <!-- inline add form (hidden until toggled) -->
                <form id="activity_form" class="border rounded p-3 mb-3 bg-light" style="display:none">
                    <input type="hidden" name="opportunity_id" value="<?= $id ?>">
                    <input type="hidden" name="id" value="">
                    <div class="row">
                        <div class="col-md-3 form-group mb-2">
                            <label class="small font-weight-bold mb-1">Type</label>
                            <select name="type" class="form-control form-control-sm rounded-0">
                                <option value="task">Task</option><option value="call">Call</option>
                                <option value="meeting">Meeting</option><option value="email">Email</option><option value="note">Note</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-2">
                            <label class="small font-weight-bold mb-1">Subject</label>
                            <input type="text" name="subject" class="form-control form-control-sm rounded-0" required>
                        </div>
                        <div class="col-md-3 form-group mb-2">
                            <label class="small font-weight-bold mb-1">Due date</label>
                            <input type="date" name="due_date" class="form-control form-control-sm rounded-0">
                        </div>
                    </div>
                    <div class="form-group mb-2"><input type="text" name="notes" class="form-control form-control-sm rounded-0" placeholder="Notes (optional)"></div>
                    <div class="text-right">
                        <button type="button" class="btn btn-xs btn-secondary" id="cancel_activity">Cancel</button>
                        <button type="submit" class="btn btn-xs btn-info">Save Activity</button>
                    </div>
                </form>

                <?php if ($acts && $acts->num_rows): ?>
                <ul class="list-group list-group-flush">
                    <?php while ($a = $acts->fetch_assoc()):
                        $done = (int)$a['is_done'] === 1;
                        $overdue = !$done && $a['due_date'] && strtotime($a['due_date']) < strtotime(date('Y-m-d'));
                    ?>
                    <li class="list-group-item px-0 d-flex align-items-start <?= $done ? 'text-muted' : '' ?>">
                        <button class="btn btn-xs btn-link p-0 mr-2 toggle_act" data-id="<?= $a['id'] ?>" title="Mark done">
                            <i class="<?= $done ? 'fas fa-check-circle text-success' : 'far fa-circle text-muted' ?>" style="font-size:1.1rem"></i>
                        </button>
                        <div class="flex-grow-1">
                            <div><i class="fas <?= $type_icon[$a['type']] ?? 'fa-check-square' ?> text-muted mr-1"></i>
                                <span class="<?= $done ? 'text-decoration-line-through' : 'font-weight-bold' ?>"><?= htmlspecialchars($a['subject']) ?></span>
                                <?php if ($overdue): ?><span class="badge badge-danger ml-1">OVERDUE</span><?php endif; ?>
                            </div>
                            <div class="small text-muted">
                                <?= ucfirst($a['type']) ?><?php if ($a['due_date']): ?> · due <?= date("d M Y", strtotime($a['due_date'])) ?><?php endif; ?>
                                <?php if ($done && $a['done_at']): ?> · done <?= date("d M Y", strtotime($a['done_at'])) ?><?php endif; ?>
                                <?php if ($a['notes']): ?><br><?= htmlspecialchars($a['notes']) ?><?php endif; ?>
                            </div>
                        </div>
                        <button class="btn btn-xs text-danger del_act" data-id="<?= $a['id'] ?>"><i class="fas fa-trash"></i></button>
                    </li>
                    <?php endwhile; ?>
                </ul>
                <?php else: ?>
                <p class="text-muted small text-center py-3">No activities yet. Add a follow-up to stay on top of this deal.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
var OPP_ID = <?= (int)$id ?>;
$(document).ready(function(){
    $('#btn_edit').click(function(){ uni_modal("Edit Opportunity Deal", "crm/manage_opportunity.php?id="+OPP_ID, "mid-large"); });

    $('.stage-btn').click(function(){
        var stage = $(this).data('stage');
        start_loader();
        $.ajax({ url:_base_url_+"classes/Master.php?f=move_opportunity_stage", method:"POST", data:{ id:OPP_ID, stage:stage }, dataType:"json",
            error:err=>{ console.log(err); end_loader(); },
            success:function(resp){ location.reload(); } });
    });

    $('#btn_convert').click(function(){
        if(!confirm('Create an invoice from this deal?')) return;
        start_loader();
        $.ajax({ url:_base_url_+"classes/Master.php?f=convert_opportunity", method:"POST", data:{ id:OPP_ID }, dataType:"json",
            error:err=>{ console.log(err); alert_toast("Conversion failed.",'error'); end_loader(); },
            success:function(resp){ if(resp.status=='success'){ location.reload(); } else { alert_toast(resp.msg||"Conversion failed.",'error'); end_loader(); } } });
    });

    $('#btn_add_activity').click(function(){ $('#activity_form').slideToggle(120); });
    $('#cancel_activity').click(function(){ $('#activity_form').slideUp(120); });

    $('#activity_form').submit(function(e){
        e.preventDefault(); start_loader();
        $.ajax({ url:_base_url_+"classes/Master.php?f=save_crm_activity", method:"POST", data:$(this).serialize(), dataType:"json",
            error:err=>{ console.log(err); alert_toast("Could not save activity.",'error'); end_loader(); },
            success:function(resp){ if(resp.status=='success'){ location.reload(); } else { alert_toast(resp.msg||"Could not save activity.",'error'); end_loader(); } } });
    });

    $('.toggle_act').click(function(){
        var aid = $(this).data('id'); start_loader();
        $.ajax({ url:_base_url_+"classes/Master.php?f=toggle_crm_activity", method:"POST", data:{ id:aid }, dataType:"json",
            error:err=>{ console.log(err); end_loader(); },
            success:function(resp){ location.reload(); } });
    });

    $('.del_act').click(function(){
        if(!confirm('Delete this activity?')) return;
        var aid = $(this).data('id'); start_loader();
        $.ajax({ url:_base_url_+"classes/Master.php?f=delete_crm_activity", method:"POST", data:{ id:aid }, dataType:"json",
            error:err=>{ console.log(err); end_loader(); },
            success:function(resp){ location.reload(); } });
    });
});
</script>
