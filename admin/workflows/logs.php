<?php
require_once(dirname(__DIR__, 2) . '/config.php');
$tenant_id = $_settings->active_tenant_id();
?>
<div class="card card-outline card-secondary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-history mr-1"></i> Workflow Run History</h3>
		<div class="card-tools">
			<a href="<?php echo base_url ?>admin/?page=workflows" class="btn btn-flat btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Workflows</a>
		</div>
	</div>
	<div class="card-body">
		<table class="table table-sm table-hover table-bordered" id="llist">
			<thead>
				<tr>
					<th>#</th>
					<th>Workflow</th>
					<th>Event</th>
					<th class="text-center">Matched</th>
					<th class="text-center">Result</th>
					<th>Actions Run</th>
					<th>Reference</th>
					<th>When</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$i=1;
				$qry = $conn->query("SELECT l.*, w.name AS wf_name FROM `workflow_logs` l LEFT JOIN `workflows` w ON l.workflow_id = w.id WHERE l.tenant_id = '{$tenant_id}' ORDER BY l.id DESC LIMIT 300");
				while($qry && $row = $qry->fetch_assoc()):
					$rb = ['success'=>'success','partial'=>'warning','failed'=>'danger','skipped'=>'secondary'][$row['result']] ?? 'secondary';
				?>
				<tr>
					<td class="text-center"><?php echo $i++; ?></td>
					<td><?php echo htmlspecialchars($row['wf_name'] ?: '(deleted)') ?></td>
					<td><span class="badge badge-info"><?php echo htmlspecialchars($row['trigger_event']) ?></span></td>
					<td class="text-center"><?php echo $row['matched']? '<span class="text-success"><i class="fas fa-check"></i></span>' : '<span class="text-muted"><i class="fas fa-minus"></i></span>' ?></td>
					<td class="text-center"><span class="badge badge-<?php echo $rb ?>"><?php echo ucfirst($row['result']) ?></span></td>
					<td><small class="text-monospace"><?php echo htmlspecialchars($row['actions_run'] ?: '—') ?></small></td>
					<td><small><?php echo htmlspecialchars($row['context_ref'] ?: '—') ?></small></td>
					<td><small><?php echo $row['date_created'] ?></small></td>
				</tr>
				<?php endwhile; ?>
			</tbody>
		</table>
	</div>
</div>
<script>
$(document).ready(function(){
	if ($.fn.DataTable.isDataTable('#llist')) { $('#llist').DataTable().destroy(); }
	$('#llist').dataTable({ columnDefs:[{orderable:false, targets:[3,4,5]}], order:[0,'asc'], pageLength:25 });
});
</script>
