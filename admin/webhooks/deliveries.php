<?php
require_once(dirname(__DIR__, 2) . '/config.php');
$tenant_id = $_settings->active_tenant_id();
?>
<div class="card card-outline card-secondary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-stream mr-1"></i> Webhook Delivery Log</h3>
		<div class="card-tools">
			<a href="<?php echo base_url ?>admin/?page=webhooks" class="btn btn-flat btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Webhooks</a>
		</div>
	</div>
	<div class="card-body">
		<p class="text-muted small mb-3">Recent delivery attempts (most recent first). Failed deliveries retry automatically with exponential backoff up to the webhook's retry limit.</p>
		<table class="table table-sm table-hover table-bordered" id="dlist">
			<thead>
				<tr>
					<th>#</th>
					<th>Event</th>
					<th>Target</th>
					<th class="text-center">Status</th>
					<th class="text-center">Attempts</th>
					<th class="text-center">HTTP</th>
					<th>When</th>
					<th class="text-center">Payload</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$i = 1;
				$qry = $conn->query("SELECT * FROM `webhook_deliveries` WHERE tenant_id = '{$tenant_id}' ORDER BY id DESC LIMIT 300");
				while($qry && $row = $qry->fetch_assoc()):
					$sb = ['pending'=>'warning','delivering'=>'info','delivered'=>'success','failed'=>'danger'][$row['status']] ?? 'secondary';
				?>
				<tr>
					<td class="text-center"><?php echo $i++; ?></td>
					<td><span class="badge badge-info"><?php echo htmlspecialchars($row['event']) ?></span></td>
					<td><small class="text-monospace"><?php echo htmlspecialchars(mb_strimwidth($row['target_url'],0,46,'…')) ?></small></td>
					<td class="text-center"><span class="badge badge-<?php echo $sb ?>"><?php echo ucfirst($row['status']) ?></span></td>
					<td class="text-center"><?php echo (int)$row['attempts'] ?> / <?php echo (int)$row['max_retries'] ?></td>
					<td class="text-center"><?php echo $row['response_code'] !== null ? (int)$row['response_code'] : '—' ?></td>
					<td><small><?php echo $row['date_created'] ?></small></td>
					<td class="text-center">
						<button type="button" class="btn btn-xs btn-outline-secondary" onclick='view_payload(<?php echo json_encode($row["payload"] ?? "", JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?php echo json_encode($row["error"] ?? "", JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?php echo json_encode($row["response_body"] ?? "", JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-eye"></i></button>
					</td>
				</tr>
				<?php endwhile; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
$(document).ready(function(){
	if ($.fn.DataTable.isDataTable('#dlist')) { $('#dlist').DataTable().destroy(); }
	$('#dlist').dataTable({ columnDefs:[{orderable:false, targets:[3,4,5,7]}], order:[0,'asc'], pageLength:25 });
});
function view_payload(payload, error, response){
	var html = '<label class="small font-weight-bold mb-1">Request Payload</label>';
	html += '<pre class="bg-dark text-light p-2 rounded" style="max-height:220px;overflow:auto;font-size:11px;">'+ (payload||'(empty)') +'</pre>';
	if(error){ html += '<label class="small font-weight-bold text-danger mb-1">Error</label><pre class="bg-light border p-2 rounded text-danger" style="font-size:11px;">'+error+'</pre>'; }
	if(response){ html += '<label class="small font-weight-bold mb-1">Response Body</label><pre class="bg-light border p-2 rounded" style="max-height:160px;overflow:auto;font-size:11px;">'+response+'</pre>'; }
	$('#uni_modal .modal-title').html('<i class="fas fa-stream"></i> Delivery Detail');
	$('#uni_modal .modal-body').html(html);
	$('#uni_modal #submit').hide();
	$('#uni_modal').modal('show');
	$('#uni_modal').on('hidden.bs.modal', function(){ $('#uni_modal #submit').show(); });
}
</script>
