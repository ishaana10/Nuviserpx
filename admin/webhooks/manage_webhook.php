<?php
require_once(dirname(__DIR__, 2) . '/config.php');

use App\Support\IntegrationEvents;

$tenant_id = $_settings->active_tenant_id();
$row = [];
if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
	$id = intval($_GET['id']);
	$qry = $conn->query("SELECT * FROM `webhooks` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
	if($qry && $qry->num_rows > 0){ $row = $qry->fetch_assoc(); }
}
$catalog = IntegrationEvents::catalog();
// Group events for a nicer <optgroup> dropdown
$grouped = [];
foreach($catalog as $key => $meta){ $grouped[$meta['group']][$key] = $meta['label']; }
?>
<div class="container-fluid">
	<form action="" id="webhook-form">
		<input type="hidden" name="id" value="<?php echo $row['id'] ?? '' ?>">

		<div class="form-group">
			<label class="control-label">Name / Description</label>
			<input type="text" name="name" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($row['name'] ?? '') ?>" placeholder="e.g. Notify Slack, Sync to CRM" required>
		</div>

		<div class="form-group">
			<label class="control-label">Trigger Event</label>
			<select name="event" class="form-control form-control-sm rounded-0" required>
				<option value="">— Select an event —</option>
				<?php foreach($grouped as $group => $events): ?>
				<optgroup label="<?php echo htmlspecialchars($group) ?>">
					<?php foreach($events as $ev => $label): ?>
					<option value="<?php echo $ev ?>" <?php echo (($row['event'] ?? '') === $ev)?'selected':'' ?>><?php echo htmlspecialchars($label) ?> (<?php echo $ev ?>)</option>
					<?php endforeach; ?>
				</optgroup>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="form-group">
			<label class="control-label">Target URL</label>
			<input type="url" name="target_url" class="form-control form-control-sm rounded-0 text-monospace" value="<?php echo htmlspecialchars($row['target_url'] ?? '') ?>" placeholder="https://example.com/webhooks/erpx" required>
		</div>

		<div class="form-group">
			<label class="control-label">Secret Token <small class="text-muted">(used to sign payloads — leave blank to auto-generate)</small></label>
			<input type="text" name="secret_token" class="form-control form-control-sm rounded-0 text-monospace" value="<?php echo htmlspecialchars($row['secret_token'] ?? '') ?>" placeholder="auto-generated if empty">
		</div>

		<div class="row">
			<div class="col-md-6 form-group">
				<label class="control-label">Max Retries</label>
				<input type="number" name="max_retries" min="0" max="10" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars((string)($row['max_retries'] ?? 5)) ?>">
			</div>
			<div class="col-md-6 form-group">
				<label class="control-label">Status</label>
				<select name="status" class="form-control form-control-sm rounded-0">
					<option value="1" <?php echo (!isset($row['status']) || $row['status']==1)?'selected':'' ?>>Active</option>
					<option value="0" <?php echo (isset($row['status']) && $row['status']==0)?'selected':'' ?>>Paused</option>
				</select>
			</div>
		</div>

		<div class="alert alert-light border small mb-0">
			<i class="fas fa-shield-alt text-primary"></i> Payloads are signed with <b>HMAC-SHA256</b>. Your endpoint receives header <code>X-ERPX-Signature: sha256=&lt;hex&gt;</code> — recompute it over the raw body with your secret token to verify authenticity.
		</div>
	</form>
</div>

<script>
$(function(){
	$('#webhook-form').submit(function(e){
		e.preventDefault();
		$('.err-msg').remove();
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=save_webhook",
			data:new FormData($(this)[0]), cache:false, contentType:false, processData:false, method:'POST', dataType:'json',
			error:err=>{console.log(err);alert_toast("An error occurred",'error');end_loader();},
			success:function(resp){
				if(typeof resp=='object' && resp.status=='success'){ location.reload(); }
				else if(resp.status=='failed' && resp.msg){
					var el=$('<div>').addClass("alert alert-danger err-msg").text(resp.msg);
					$('#webhook-form').prepend(el); el.show('slow'); end_loader();
				}else{ alert_toast("An error occurred",'error'); end_loader(); }
			}
		})
	});
});
</script>
