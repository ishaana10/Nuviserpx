<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\BankReconciliationService;

$svc = new BankReconciliationService($conn, $_settings);
$tenant_id = $_settings->active_tenant_id();
$banks = $svc->bankAccounts();

if ($_settings->chk_flashdata('success')):
?>
<script>alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')</script>
<?php endif; ?>

<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-university text-primary"></i> Bank Reconciliation</h3>
		<div class="card-tools">
			<?php if (!empty($banks)): ?>
			<button type="button" class="btn btn-flat btn-primary btn-sm" id="btn_new_rec"><span class="fas fa-plus"></span> New Reconciliation</button>
			<?php endif; ?>
		</div>
	</div>
	<div class="card-body">
		<?php if (empty($banks)): ?>
			<div class="text-center text-muted py-5">
				<i class="fas fa-university" style="font-size:2rem;opacity:.4"></i>
				<p class="mt-2 mb-0"><b>No bank or cash accounts found.</b></p>
				<p class="small">Give an account the type &ldquo;Bank&rdquo; in your <a href="./?page=accounts">chart of accounts</a> to reconcile it.</p>
			</div>
		<?php else: ?>
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<thead>
					<tr>
						<th>#</th>
						<th>Bank Account</th>
						<th>Statement Date</th>
						<th class="text-right">Opening</th>
						<th class="text-right">Closing</th>
						<th class="text-right">Difference</th>
						<th class="text-center">Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
					$recs = $conn->query("SELECT r.*, a.name acc_name, a.code acc_code
						FROM `bank_reconciliations` r
						INNER JOIN `account_list` a ON a.id = r.account_id
						WHERE r.tenant_id = '{$tenant_id}' ORDER BY r.id DESC");
					if ($recs): while ($row = $recs->fetch_assoc()):
						$sum = $svc->summary((int)$row['id'], (int)$tenant_id);
						$diff = $sum ? $sum['difference'] : 0.0;
						$sym = !empty($sum['currency']) ? $sum['currency'] . ' ' : '$';
						$is_done = $row['status'] === 'reconciled';
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo htmlspecialchars($row['acc_name']) ?></b> <small class="text-muted"><?php echo htmlspecialchars((string)$row['acc_code']) ?></small></td>
							<td><?php echo date("d M Y", strtotime($row['statement_date'])) ?></td>
							<td class="text-right"><?php echo $sym . number_format((float)$row['opening_balance'], 2) ?></td>
							<td class="text-right"><?php echo $sym . number_format((float)$row['closing_balance'], 2) ?></td>
							<td class="text-right <?php echo abs($diff) > 0.009 ? 'text-danger font-weight-bold' : 'text-success' ?>"><?php echo $sym . number_format($diff, 2) ?></td>
							<td class="text-center">
								<span class="badge badge-<?php echo $is_done ? 'success' : 'warning' ?> px-2 py-1"><?php echo $is_done ? 'RECONCILED' : 'IN PROGRESS' ?></span>
							</td>
							<td align="center">
								<button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">Action</button>
								<div class="dropdown-menu" role="menu">
									<a class="dropdown-item" href="./?page=bank_reconciliation/reconcile&id=<?php echo $row['id'] ?>"><span class="fa fa-<?php echo $is_done ? 'eye text-info' : 'tasks text-primary' ?>"></span> <?php echo $is_done ? 'View' : 'Continue Reconciling' ?></a>
									<div class="dropdown-divider"></div>
									<a class="dropdown-item delete_rec" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
								</div>
							</td>
						</tr>
					<?php endwhile; endif; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
</div>

<!-- New reconciliation modal -->
<div class="modal fade" id="modal_new_rec" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content border-0 shadow-lg rounded-0">
			<div class="modal-header py-2"><h5 class="modal-title font-weight-bold">New Bank Reconciliation</h5>
				<button type="button" class="close" data-dismiss="modal">&times;</button></div>
			<form id="form_new_rec">
				<div class="modal-body">
					<div class="form-group">
						<label class="control-label small font-weight-bold">Bank / Cash Account</label>
						<select name="account_id" class="form-control form-control-sm rounded-0" required>
							<option value="" disabled selected></option>
							<?php foreach ($banks as $b): ?>
							<option value="<?php echo $b['id'] ?>"><?php echo htmlspecialchars((string)$b['code']) . ' — ' . htmlspecialchars($b['name']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-group">
						<label class="control-label small font-weight-bold">Statement End Date</label>
						<input type="date" name="statement_date" class="form-control form-control-sm rounded-0" value="<?php echo date('Y-m-d') ?>" required>
					</div>
					<div class="row">
						<div class="col-6 form-group">
							<label class="control-label small font-weight-bold">Opening Balance</label>
							<input type="number" step="any" name="opening_balance" class="form-control form-control-sm rounded-0" value="0.00" required>
						</div>
						<div class="col-6 form-group">
							<label class="control-label small font-weight-bold">Closing Balance</label>
							<input type="number" step="any" name="closing_balance" class="form-control form-control-sm rounded-0" value="0.00" required>
						</div>
					</div>
					<small class="text-muted">Take these from your bank statement. The reconciliation balances when matched book entries equal the closing minus opening movement.</small>
				</div>
				<div class="modal-footer py-2">
					<button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-sm btn-primary">Start</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
	$(function(){
		$('#list').dataTable({ columnDefs:[{ orderable:false, targets:[7] }], order:[0,'asc'] });
		$('#btn_new_rec').click(function(){ $('#modal_new_rec').modal('show'); });

		$('#form_new_rec').submit(function(e){
			e.preventDefault();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=start_reconciliation",
				method:"POST", data:$(this).serialize(), dataType:"json",
				error:err=>{ console.log(err); alert_toast("An error occurred.",'error'); end_loader(); },
				success:function(resp){
					if(resp.status=='success'){ location.href='./?page=bank_reconciliation/reconcile&id='+resp.id; }
					else{ alert_toast(resp.msg || "Could not start reconciliation.",'error'); end_loader(); }
				}
			});
		});

		$('.delete_rec').click(function(){
			_conf("Delete this reconciliation and all its statement lines and matches?","delete_reconciliation",[$(this).attr('data-id')]);
		});
	});
	function delete_reconciliation($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_reconciliation",
			method:"POST", data:{id:$id}, dataType:"json",
			error:err=>{ console.log(err); alert_toast("An error occurred.",'error'); end_loader(); },
			success:function(resp){ if(resp.status=='success'){ location.reload(); } else { alert_toast("An error occurred.",'error'); end_loader(); } }
		});
	}
</script>
