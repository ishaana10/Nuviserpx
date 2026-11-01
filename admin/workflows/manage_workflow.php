<?php
require_once(dirname(__DIR__, 2) . '/config.php');

use App\Support\IntegrationEvents;

$tenant_id = $_settings->active_tenant_id();
$row = [];
if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
	$id = intval($_GET['id']);
	$q = $conn->query("SELECT * FROM `workflows` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
	if($q && $q->num_rows>0){ $row = $q->fetch_assoc(); }
}
$catalog = IntegrationEvents::catalog();
$grouped = [];
foreach($catalog as $key=>$meta){ $grouped[$meta['group']][$key] = $meta['label']; }

$existing_conditions = json_decode($row['conditions'] ?? '[]', true); $existing_conditions = is_array($existing_conditions)?$existing_conditions:[];
$existing_actions = json_decode($row['actions'] ?? '[]', true); $existing_actions = is_array($existing_actions)?$existing_actions:[];
?>
<div class="container-fluid">
	<form action="" id="workflow-form">
		<input type="hidden" name="id" value="<?php echo $row['id'] ?? '' ?>">
		<input type="hidden" name="conditions" id="conditions_json">
		<input type="hidden" name="actions" id="actions_json">

		<div class="row">
			<div class="col-md-7 form-group">
				<label class="control-label">Workflow Name</label>
				<input type="text" name="name" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($row['name'] ?? '') ?>" placeholder="e.g. Email customer when invoice is paid" required>
			</div>
			<div class="col-md-5 form-group">
				<label class="control-label">Status</label>
				<select name="status" class="form-control form-control-sm rounded-0">
					<option value="1" <?php echo (!isset($row['status'])||$row['status']==1)?'selected':'' ?>>Active</option>
					<option value="0" <?php echo (isset($row['status'])&&$row['status']==0)?'selected':'' ?>>Paused</option>
				</select>
			</div>
		</div>

		<div class="form-group">
			<label class="control-label">Description <small class="text-muted">(optional)</small></label>
			<input type="text" name="description" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($row['description'] ?? '') ?>">
		</div>

		<div class="form-group">
			<label class="control-label font-weight-bold"><i class="fas fa-bolt text-warning"></i> WHEN this happens (Trigger)</label>
			<select name="trigger_event" id="trigger_event" class="form-control form-control-sm rounded-0" required>
				<option value="">— Select a trigger event —</option>
				<?php foreach($grouped as $group=>$events): ?>
				<optgroup label="<?php echo htmlspecialchars($group) ?>">
					<?php foreach($events as $ev=>$label): ?>
					<option value="<?php echo $ev ?>" <?php echo (($row['trigger_event']??'')===$ev)?'selected':'' ?>><?php echo htmlspecialchars($label) ?></option>
					<?php endforeach; ?>
				</optgroup>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="card card-outline card-secondary mb-2">
			<div class="card-header py-2 d-flex justify-content-between align-items-center">
				<span class="font-weight-bold"><i class="fas fa-filter text-primary"></i> IF these conditions match</span>
				<div>
					<select name="match_type" class="form-control form-control-sm d-inline-block" style="width:auto">
						<option value="all" <?php echo (($row['match_type']??'all')==='all')?'selected':'' ?>>Match ALL</option>
						<option value="any" <?php echo (($row['match_type']??'')==='any')?'selected':'' ?>>Match ANY</option>
					</select>
					<button type="button" class="btn btn-xs btn-outline-primary" onclick="addCondition()"><i class="fas fa-plus"></i> Condition</button>
				</div>
			</div>
			<div class="card-body py-2" id="conditions_wrap">
				<p class="text-muted small mb-0" id="no_cond">No conditions — the workflow runs on every <span class="trigger-name">trigger</span>.</p>
			</div>
		</div>

		<div class="card card-outline card-success">
			<div class="card-header py-2 d-flex justify-content-between align-items-center">
				<span class="font-weight-bold"><i class="fas fa-play text-success"></i> THEN do this (Actions)</span>
				<button type="button" class="btn btn-xs btn-outline-success" onclick="addAction()"><i class="fas fa-plus"></i> Action</button>
			</div>
			<div class="card-body py-2" id="actions_wrap">
				<p class="text-muted small mb-0" id="no_act">Add at least one action.</p>
			</div>
		</div>
	</form>
</div>

<script>
var EVENT_FIELDS = <?php echo json_encode(array_map(fn($m)=>$m['fields'], $catalog)); ?>;
var OPERATORS = [
	['equals','equals'],['not_equals','does not equal'],['greater_than','is greater than'],
	['less_than','is less than'],['contains','contains'],['starts_with','starts with'],
	['is_empty','is empty'],['is_not_empty','is not empty'],['changed_to','changed to']
];
var ACTION_TYPES = [['send_email','Send Email'],['call_webhook','Call a URL / Webhook'],['create_ticket','Create Support Ticket'],['log','Log only']];

function currentFields(){
	var ev = $('#trigger_event').val();
	return EVENT_FIELDS[ev] || [];
}
function fieldOptions(selected){
	var f = currentFields(), html='';
	if(f.length===0){ html = '<option value="">(pick a trigger first)</option>'; }
	f.forEach(function(fld){ html += '<option value="'+fld+'" '+(fld===selected?'selected':'')+'>'+fld+'</option>'; });
	return html;
}
function opOptions(selected){
	var html='';
	OPERATORS.forEach(function(o){ html += '<option value="'+o[0]+'" '+(o[0]===selected?'selected':'')+'>'+o[1]+'</option>'; });
	return html;
}

/* ---- Conditions ---- */
function addCondition(c){
	c = c || {};
	$('#no_cond').hide();
	var rowHtml = '<div class="form-row align-items-center mb-2 cond-row">'
		+ '<div class="col-4"><select class="form-control form-control-sm c-field">'+fieldOptions(c.field)+'</select></div>'
		+ '<div class="col-4"><select class="form-control form-control-sm c-op">'+opOptions(c.operator)+'</select></div>'
		+ '<div class="col-3"><input type="text" class="form-control form-control-sm c-val" value="'+(c.value!==undefined?String(c.value).replace(/"/g,"&quot;"):"")+'" placeholder="value"></div>'
		+ '<div class="col-1 text-center"><a href="javascript:void(0)" class="text-danger" onclick="$(this).closest(\'.cond-row\').remove()"><i class="fas fa-times"></i></a></div>'
		+ '</div>';
	$('#conditions_wrap').append(rowHtml);
}

/* ---- Actions ---- */
function actionParamsHtml(a){
	a = a || {};
	var t = a.type || 'send_email';
	if(t==='send_email'){
		return '<div class="form-row mt-2">'
			+ '<div class="col-12 mb-1"><input type="text" class="form-control form-control-sm a-to" value="'+(a.to||'')+'" placeholder="To email (blank = customer email, supports {{party_email}})"></div>'
			+ '<div class="col-12 mb-1"><input type="text" class="form-control form-control-sm a-subject" value="'+(a.subject||'').replace(/"/g,"&quot;")+'" placeholder="Subject (supports {{invoice_no}} etc.)"></div>'
			+ '<div class="col-12"><textarea class="form-control form-control-sm a-body" rows="2" placeholder="Body — use {{field}} placeholders">'+(a.body||'')+'</textarea></div>'
			+ '</div>';
	}
	if(t==='call_webhook'){
		return '<div class="form-row mt-2">'
			+ '<div class="col-8 mb-1"><input type="url" class="form-control form-control-sm a-url" value="'+(a.url||'')+'" placeholder="https://..."></div>'
			+ '<div class="col-4"><input type="text" class="form-control form-control-sm a-secret" value="'+(a.secret||'')+'" placeholder="secret (optional)"></div>'
			+ '</div>';
	}
	if(t==='create_ticket'){
		var pr = a.priority||'medium';
		var opts = ['low','medium','high','urgent'].map(function(p){return '<option value="'+p+'" '+(p===pr?'selected':'')+'>'+p+'</option>';}).join('');
		return '<div class="form-row mt-2">'
			+ '<div class="col-8 mb-1"><input type="text" class="form-control form-control-sm a-subject" value="'+(a.subject||'').replace(/"/g,"&quot;")+'" placeholder="Ticket subject (supports {{invoice_no}})"></div>'
			+ '<div class="col-4"><select class="form-control form-control-sm a-priority">'+opts+'</select></div>'
			+ '</div>';
	}
	return '<div class="text-muted small mt-2">Records the run in the workflow log.</div>';
}
function addAction(a){
	a = a || {};
	$('#no_act').hide();
	var typeSel = ACTION_TYPES.map(function(t){ return '<option value="'+t[0]+'" '+((a.type||'send_email')===t[0]?'selected':'')+'>'+t[1]+'</option>'; }).join('');
	var rowHtml = '<div class="border rounded p-2 mb-2 act-row">'
		+ '<div class="d-flex justify-content-between align-items-center">'
		+ '<select class="form-control form-control-sm a-type" style="width:auto" onchange="onActionTypeChange(this)">'+typeSel+'</select>'
		+ '<a href="javascript:void(0)" class="text-danger" onclick="$(this).closest(\'.act-row\').remove()"><i class="fas fa-times"></i></a>'
		+ '</div><div class="a-params">'+actionParamsHtml(a)+'</div></div>';
	$('#actions_wrap').append(rowHtml);
}
function onActionTypeChange(sel){
	var $row = $(sel).closest('.act-row');
	$row.find('.a-params').html(actionParamsHtml({type: sel.value}));
}

/* ---- Trigger change re-maps condition field dropdowns ---- */
$('#trigger_event').on('change', function(){
	var sel = $(this).val();
	$('.trigger-name').text(sel || 'trigger');
	$('.cond-row').each(function(){
		var cur = $(this).find('.c-field').val();
		$(this).find('.c-field').html(fieldOptions(cur));
	});
});

/* ---- Serialize + submit ---- */
function collect(){
	var conditions = [];
	$('.cond-row').each(function(){
		var f=$(this).find('.c-field').val(), o=$(this).find('.c-op').val(), v=$(this).find('.c-val').val();
		if(f){ conditions.push({field:f, operator:o, value:v}); }
	});
	var actions = [];
	$('.act-row').each(function(){
		var t=$(this).find('.a-type').val(), a={type:t};
		if(t==='send_email'){ a.to=$(this).find('.a-to').val(); a.subject=$(this).find('.a-subject').val(); a.body=$(this).find('.a-body').val(); }
		else if(t==='call_webhook'){ a.url=$(this).find('.a-url').val(); a.secret=$(this).find('.a-secret').val(); }
		else if(t==='create_ticket'){ a.subject=$(this).find('.a-subject').val(); a.priority=$(this).find('.a-priority').val(); }
		actions.push(a);
	});
	$('#conditions_json').val(JSON.stringify(conditions));
	$('#actions_json').val(JSON.stringify(actions));
	return actions.length>0;
}

$('#workflow-form').submit(function(e){
	e.preventDefault();
	$('.err-msg').remove();
	if(!collect()){
		var el=$('<div>').addClass("alert alert-danger err-msg").text("Add at least one action.");
		$('#workflow-form').prepend(el); return;
	}
	start_loader();
	$.ajax({
		url:_base_url_+"classes/Master.php?f=save_workflow",
		data:new FormData($(this)[0]), cache:false, contentType:false, processData:false, method:'POST', dataType:'json',
		error:err=>{console.log(err);alert_toast("An error occurred",'error');end_loader();},
		success:function(resp){
			if(typeof resp=='object' && resp.status=='success'){ location.reload(); }
			else if(resp.status=='failed' && resp.msg){
				var el=$('<div>').addClass("alert alert-danger err-msg").text(resp.msg);
				$('#workflow-form').prepend(el); el.show('slow'); end_loader();
			}else{ alert_toast("An error occurred",'error'); end_loader(); }
		}
	})
});

/* ---- Hydrate existing rows on edit ---- */
$(function(){
	$('.trigger-name').text($('#trigger_event').val() || 'trigger');
	var existingC = <?php echo json_encode($existing_conditions) ?>;
	var existingA = <?php echo json_encode($existing_actions) ?>;
	existingC.forEach(function(c){ addCondition(c); });
	existingA.forEach(function(a){ addAction(a); });
});
</script>
