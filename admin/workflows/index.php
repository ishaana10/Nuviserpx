<?php
require_once(dirname(__DIR__, 2) . '/config.php');

use App\Support\IntegrationEvents;

$tenant_id = $_settings->active_tenant_id();
$labels = [];
foreach(IntegrationEvents::catalog() as $k=>$m){ $labels[$k] = $m['label']; }
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')</script>
<?php endif;?>

<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-bolt mr-1"></i> Automation Workflows</h3>
		<div class="card-tools">
			<a href="<?php echo base_url ?>admin/?page=workflows/logs" class="btn btn-flat btn-outline-secondary btn-sm"><i class="fas fa-history"></i> Run History</a>
			<a href="javascript:void(0)" onclick="uni_modal('<i class=\'fa fa-plus\'></i> New Workflow', 'workflows/manage_workflow.php', 'modal-lg')" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> Create Workflow</a>
		</div>
	</div>
	<div class="card-body">
		<p class="text-muted small mb-3">A workflow watches for an event, checks optional conditions, then runs one or more actions — send an email, call a URL, or open a support ticket. Fully customisable; no code required.</p>
		<table class="table table-hover table-striped table-bordered" id="list">
			<thead>
				<tr>
					<th>#</th>
					<th>Name</th>
					<th>Trigger</th>
					<th class="text-center">Conditions</th>
					<th class="text-center">Actions</th>
					<th class="text-center">Runs</th>
					<th class="text-center">Status</th>
					<th class="text-center">Action</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$i=1;
				$qry = $conn->query("SELECT * FROM `workflows` WHERE tenant_id = '{$tenant_id}' ORDER BY id DESC");
				while($qry && $row = $qry->fetch_assoc()):
					$cond = json_decode($row['conditions'] ?: '[]', true); $cond = is_array($cond)?$cond:[];
					$acts = json_decode($row['actions'] ?: '[]', true); $acts = is_array($acts)?$acts:[];
				?>
				<tr>
					<td class="text-center"><?php echo $i++; ?></td>
					<td>
						<b><?php echo htmlspecialchars($row['name']) ?></b>
						<?php if(!empty($row['description'])): ?><div><small class="text-muted"><?php echo htmlspecialchars($row['description']) ?></small></div><?php endif; ?>
					</td>
					<td><span class="badge badge-info"><?php echo htmlspecialchars($labels[$row['trigger_event']] ?? $row['trigger_event']) ?></span></td>
					<td class="text-center"><?php echo count($cond) ?> <small class="text-muted">(<?php echo $row['match_type']==='any'?'any':'all' ?>)</small></td>
					<td class="text-center"><?php echo count($acts) ?></td>
					<td class="text-center"><?php echo (int)$row['run_count'] ?></td>
					<td class="text-center">
						<span class="badge badge-<?php echo $row['status']==1?'success':'secondary' ?> px-3 rounded-pill" style="cursor:pointer" onclick="toggle_wf(<?php echo $row['id'] ?>)"><?php echo $row['status']==1?'Active':'Paused' ?></span>
					</td>
					<td class="text-center">
						<button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle" data-toggle="dropdown">Action</button>
						<div class="dropdown-menu dropdown-menu-right" role="menu">
							<a class="dropdown-item" href="javascript:void(0)" onclick="uni_modal('<i class=\'fa fa-edit\'></i> Edit Workflow', 'workflows/manage_workflow.php?id=<?php echo $row['id'] ?>', 'modal-lg')"><span class="fa fa-edit text-primary"></span> Edit</a>
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
	$('#list').dataTable({ columnDefs:[{orderable:false, targets:[3,4,6,7]}], order:[0,'asc'] });
	$(document).on('click','.delete_data',function(e){
		e.preventDefault();
		_conf("Delete this workflow? It will stop running immediately.","delete_workflow",[$(this).attr('data-id')])
	});
});
function delete_workflow($id){
	start_loader();
	$.ajax({url:_base_url_+"classes/Master.php?f=delete_workflow",method:"POST",data:{id:$id},dataType:"json",
		error:err=>{console.log(err);alert_toast("An error occurred.",'error');end_loader();},
		success:function(resp){ if(typeof resp=='object'&&resp.status=='success'){location.reload();}else{alert_toast("An error occurred.",'error');end_loader();} }
	})
}
function toggle_wf($id){
	$.ajax({url:_base_url_+"classes/Master.php?f=toggle_workflow",method:"POST",data:{id:$id},dataType:"json",
		success:function(){ location.reload(); }, error:()=>{ alert_toast("An error occurred.",'error'); }
	})
}
</script>
