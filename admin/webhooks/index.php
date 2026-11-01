<?php
require_once(dirname(__DIR__, 2) . '/config.php');
$tenant_id = $_settings->active_tenant_id();
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')</script>
<?php endif;?>

<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-plug mr-1"></i> Outgoing Webhooks</h3>
		<div class="card-tools">
			<a href="<?php echo base_url ?>admin/?page=webhooks/deliveries" class="btn btn-flat btn-outline-secondary btn-sm"><i class="fas fa-stream"></i> Delivery Log</a>
			<a href="javascript:void(0)" onclick="uni_modal('<i class=\'fa fa-plus\'></i> New Webhook', 'webhooks/manage_webhook.php')" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Add Webhook</a>
		</div>
	</div>
	<div class="card-body">
		<p class="text-muted small mb-3">Webhooks push a signed JSON payload to an external URL whenever a subscribed event occurs. Deliveries are queued and retried automatically. Verify the <code>X-ERPX-Signature</code> header (HMAC-SHA256 of the raw body using the secret token) on your receiving endpoint.</p>
		<table class="table table-hover table-striped table-bordered" id="list">
			<thead>
				<tr>
					<th>#</th>
					<th>Name</th>
					<th>Event</th>
					<th>Target URL</th>
					<th class="text-center">Health</th>
					<th class="text-center">Status</th>
					<th class="text-center">Action</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$i = 1;
				$qry = $conn->query("SELECT * FROM `webhooks` WHERE tenant_id = '{$tenant_id}' ORDER BY id DESC");
				while($qry && $row = $qry->fetch_assoc()):
					$last = $row['last_status'] ?? '';
					$health_badge = 'secondary'; $health_text = 'No deliveries yet';
					if($last === 'delivered'){ $health_badge='success'; $health_text='Healthy'; }
					elseif($last === 'retrying'){ $health_badge='warning'; $health_text='Retrying'; }
					elseif($last === 'failed'){ $health_badge='danger'; $health_text='Failing'; }
				?>
				<tr>
					<td class="text-center"><?php echo $i++; ?></td>
					<td><b><?php echo htmlspecialchars($row['name'] ?: '—') ?></b></td>
					<td><span class="badge badge-info"><?php echo htmlspecialchars($row['event']) ?></span></td>
					<td><small class="text-monospace"><?php echo htmlspecialchars($row['target_url']) ?></small></td>
					<td class="text-center">
						<span class="badge badge-<?php echo $health_badge ?>"><?php echo $health_text ?></span>
						<?php if((int)$row['failure_count'] > 0): ?>
							<div><small class="text-danger"><?php echo (int)$row['failure_count'] ?> fails</small></div>
						<?php endif; ?>
					</td>
					<td class="text-center">
						<?php if($row['status'] == 1): ?>
							<span class="badge badge-success px-3 rounded-pill">Active</span>
						<?php else: ?>
							<span class="badge badge-secondary px-3 rounded-pill">Paused</span>
						<?php endif; ?>
					</td>
					<td class="text-center">
						<button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle" data-toggle="dropdown">Action</button>
						<div class="dropdown-menu dropdown-menu-right" role="menu">
							<a class="dropdown-item" href="javascript:void(0)" onclick="test_webhook(<?php echo $row['id'] ?>)"><span class="fa fa-paper-plane text-info"></span> Send Test</a>
							<a class="dropdown-item" href="javascript:void(0)" onclick="uni_modal('<i class=\'fa fa-edit\'></i> Edit Webhook', 'webhooks/manage_webhook.php?id=<?php echo $row['id'] ?>')"><span class="fa fa-edit text-primary"></span> Edit</a>
							<div class="dropdown-divider"></div>
							<a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
						</div>
					</td>
				</tr>
				<?php endwhile; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
$(document).ready(function(){
	if ($.fn.DataTable.isDataTable('#list')) { $('#list').DataTable().destroy(); }
	$('#list').dataTable({ columnDefs:[{orderable:false, targets:[4,5,6]}], order:[0,'asc'] });

	$(document).on('click','.delete_data',function(e){
		e.preventDefault();
		_conf("Delete this webhook? External apps subscribed to it will stop receiving events.","delete_webhook",[$(this).attr('data-id')])
	});
});
function delete_webhook($id){
	start_loader();
	$.ajax({
		url:_base_url_+"classes/Master.php?f=delete_webhook",
		method:"POST", data:{id:$id}, dataType:"json",
		error:err=>{console.log(err);alert_toast("An error occurred.",'error');end_loader();},
		success:function(resp){
			if(typeof resp=='object' && resp.status=='success'){ location.reload(); }
			else{ alert_toast("An error occurred.",'error'); end_loader(); }
		}
	})
}
function test_webhook($id){
	start_loader();
	$.ajax({
		url:_base_url_+"classes/Master.php?f=test_webhook",
		method:"POST", data:{id:$id}, dataType:"json",
		error:err=>{console.log(err);alert_toast("An error occurred.",'error');end_loader();},
		success:function(resp){
			end_loader();
			if(typeof resp=='object'){
				alert_toast(resp.msg || 'Test sent.', resp.status=='success'?'success':'error');
			}else{ alert_toast("An error occurred.",'error'); }
		}
	})
}
</script>
