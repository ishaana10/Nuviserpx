<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\BankReconciliationService;

$svc = new BankReconciliationService($conn, $_settings);
$tenant_id = $_settings->active_tenant_id();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$sum = $svc->summary($id, (int)$tenant_id);
if (!$sum) { echo '<div class="alert alert-danger">Reconciliation not found.</div>'; return; }
$rec = $sum['reconciliation'];
$account_id = (int)$rec['account_id'];
$is_done = $rec['status'] === 'reconciled';

$acc = $conn->query("SELECT name, code FROM `account_list` WHERE id = '{$account_id}'")->fetch_assoc();

// Statement lines
$lines = $conn->query("SELECT * FROM `bank_statement_lines` WHERE reconciliation_id = '{$id}' AND tenant_id = '{$tenant_id}' ORDER BY line_date ASC, id ASC");

// Unmatched book entries up to statement date (candidates)
$book = $svc->unmatchedBookEntries($id, $account_id, $rec['statement_date']);

// Matched entries (for display on matched rows)
$matched_map = [];
$mm = $conn->query("SELECT m.*, j.code, j.journal_date, j.description FROM `bank_reconciliation_matches` m
	INNER JOIN `journal_entries` j ON j.id = m.journal_id
	WHERE m.reconciliation_id = '{$id}' AND m.tenant_id = '{$tenant_id}'");
if ($mm) { while ($r = $mm->fetch_assoc()) { $matched_map[(int)$r['statement_line_id']] = $r; } }

$fmt = fn($n) => number_format((float)$n, 2);
?>
<style>
	.rec-wrap { max-width: 1200px; margin: 0 auto; }
	.rec-bal { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:16px; }
	.rec-bal .box { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; }
	.rec-bal .box .k { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:700; }
	.rec-bal .box .v { font-size:1.25rem; font-weight:700; color:#0f172a; margin-top:2px; }
	.rec-bal .box.diff .v.bad { color:#dc2626; } .rec-bal .box.diff .v.ok { color:#16a34a; }
	.rec-col h6 { font-weight:700; font-size:.85rem; color:#334155; border-bottom:2px solid #e2e8f0; padding-bottom:6px; margin-bottom:8px; }
	.rec-line { border:1px solid #e2e8f0; border-radius:8px; padding:8px 10px; margin-bottom:6px; font-size:.85rem; display:flex; justify-content:space-between; align-items:center; gap:8px; }
	.rec-line.matched { background:#f0fdf4; border-color:#bbf7d0; }
	.rec-line .amt.in { color:#16a34a; font-weight:700; } .rec-line .amt.out { color:#dc2626; font-weight:700; }
	.rec-line .meta { color:#64748b; font-size:.74rem; }
	.rec-sel { border-color:#2563eb !important; box-shadow:0 0 0 2px rgba(37,99,235,.15); }
</style>

<?php if($_settings->chk_flashdata('success')): ?>
<script>alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')</script>
<?php endif; ?>

<div class="rec-wrap">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<div>
			<h4 class="m-0 font-weight-bold"><i class="fas fa-university text-primary"></i> <?php echo htmlspecialchars($acc['name'] ?? 'Bank') ?>
				<span class="badge badge-<?php echo $is_done ? 'success' : 'warning' ?> align-middle"><?php echo $is_done ? 'RECONCILED' : 'IN PROGRESS' ?></span></h4>
			<small class="text-muted">Statement to <?php echo date("d M Y", strtotime($rec['statement_date'])) ?></small>
		</div>
		<div>
			<?php if ($is_done): ?>
				<button class="btn btn-sm btn-outline-secondary btn-flat" id="btn_reopen"><i class="fas fa-lock-open"></i> Reopen</button>
			<?php else: ?>
				<button class="btn btn-sm btn-success btn-flat" id="btn_finalize" <?php echo $sum['balanced'] ? '' : 'disabled title="Difference must be zero to finalize"' ?>><i class="fas fa-check-circle"></i> Finalize</button>
			<?php endif; ?>
			<a href="./?page=bank_reconciliation" class="btn btn-sm btn-default btn-flat"><i class="fas fa-arrow-left"></i> Back</a>
		</div>
	</div>

	<div class="rec-bal">
		<div class="box"><div class="k">Opening</div><div class="v">$<?php echo $fmt($rec['opening_balance']) ?></div></div>
		<div class="box"><div class="k">Closing</div><div class="v">$<?php echo $fmt($rec['closing_balance']) ?></div></div>
		<div class="box"><div class="k">Statement movement</div><div class="v">$<?php echo $fmt($sum['statement_movement']) ?></div></div>
		<div class="box"><div class="k">Matched (book)</div><div class="v" id="matched_box">$<?php echo $fmt($sum['matched_movement']) ?></div></div>
		<div class="box diff"><div class="k">Difference</div><div class="v <?php echo $sum['balanced'] ? 'ok' : 'bad' ?>" id="diff_box">$<?php echo $fmt($sum['difference']) ?></div></div>
	</div>

	<?php if (!$is_done): ?>
	<div class="card mb-3">
		<div class="card-body py-2">
			<div class="d-flex flex-wrap align-items-center" style="gap:8px">
				<b class="mr-2">Add statement lines:</b>
				<button class="btn btn-xs btn-outline-primary" id="btn_import_csv"><i class="fas fa-file-csv"></i> Import CSV</button>
				<button class="btn btn-xs btn-outline-secondary" id="btn_add_manual"><i class="fas fa-plus"></i> Add manually</button>
				<input type="file" id="csv_file" accept=".csv" style="display:none">
				<small class="text-muted ml-auto">CSV columns: date, description, amount (negative = money out)</small>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="row">
		<!-- Statement lines -->
		<div class="col-md-6 rec-col">
			<h6>Bank Statement Lines</h6>
			<div id="stmt_lines">
			<?php if ($lines && $lines->num_rows): while ($l = $lines->fetch_assoc()):
				$is_matched = $l['status'] === 'matched';
				$amt = (float)$l['amount'];
			?>
				<div class="rec-line <?php echo $is_matched ? 'matched' : '' ?>" data-line-id="<?php echo $l['id'] ?>" data-amount="<?php echo $amt ?>">
					<div>
						<div><?php echo htmlspecialchars($l['description'] ?: '(no description)') ?></div>
						<div class="meta"><?php echo date("d M Y", strtotime($l['line_date'])) ?><?php echo $l['reference'] ? ' · ' . htmlspecialchars($l['reference']) : '' ?>
							<?php if ($is_matched && isset($matched_map[(int)$l['id']])): ?> · matched to <b><?php echo htmlspecialchars($matched_map[(int)$l['id']]['code']) ?></b><?php endif; ?>
						</div>
					</div>
					<div class="text-right" style="white-space:nowrap">
						<span class="amt <?php echo $amt >= 0 ? 'in' : 'out' ?>"><?php echo ($amt >= 0 ? '+' : '') . $fmt($amt) ?></span>
						<?php if (!$is_done): ?>
						<div class="mt-1">
							<?php if ($is_matched): ?>
								<button class="btn btn-xs btn-outline-warning unmatch_btn" data-line-id="<?php echo $l['id'] ?>"><i class="fas fa-unlink"></i></button>
							<?php else: ?>
								<button class="btn btn-xs btn-outline-primary select_line_btn" data-line-id="<?php echo $l['id'] ?>" data-amount="<?php echo $amt ?>">Match</button>
							<?php endif; ?>
							<button class="btn btn-xs btn-outline-danger del_line_btn" data-line-id="<?php echo $l['id'] ?>"><i class="fas fa-trash"></i></button>
						</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endwhile; else: ?>
				<p class="text-muted small">No statement lines yet.</p>
			<?php endif; ?>
			</div>
		</div>

		<!-- Book entries -->
		<div class="col-md-6 rec-col">
			<h6>Book Entries (unmatched)</h6>
			<p class="text-muted small" id="match_hint" style="display:none">Select a book entry below to match it to the chosen statement line.</p>
			<div id="book_lines">
			<?php if (!empty($book)): foreach ($book as $b): $mv = (float)$b['movement']; ?>
				<div class="rec-line book-entry" data-journal-id="<?php echo $b['journal_id'] ?>" data-movement="<?php echo $mv ?>">
					<div>
						<div><b><?php echo htmlspecialchars($b['code']) ?></b> <?php echo htmlspecialchars(mb_strimwidth((string)$b['description'], 0, 60, '…')) ?></div>
						<div class="meta"><?php echo date("d M Y", strtotime($b['journal_date'])) ?></div>
					</div>
					<div class="text-right" style="white-space:nowrap">
						<span class="amt <?php echo $mv >= 0 ? 'in' : 'out' ?>"><?php echo ($mv >= 0 ? '+' : '') . $fmt($mv) ?></span>
						<?php if (!$is_done): ?>
						<div class="mt-1"><button class="btn btn-xs btn-success confirm_match_btn" data-journal-id="<?php echo $b['journal_id'] ?>" style="display:none">Confirm match</button></div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; else: ?>
				<p class="text-muted small">No unmatched bank entries up to the statement date. All book movements are matched, or none exist yet.</p>
			<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<!-- Manual add modal -->
<div class="modal fade" id="modal_manual" tabindex="-1" role="dialog">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content rounded-0">
			<div class="modal-header py-2"><h5 class="modal-title">Add Statement Line</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
			<form id="form_manual">
				<input type="hidden" name="reconciliation_id" value="<?php echo $id ?>">
				<div class="modal-body">
					<div class="form-group"><label class="small font-weight-bold">Date</label><input type="date" name="line_date[]" class="form-control form-control-sm rounded-0" value="<?php echo date('Y-m-d') ?>" required></div>
					<div class="form-group"><label class="small font-weight-bold">Description</label><input type="text" name="description[]" class="form-control form-control-sm rounded-0"></div>
					<div class="form-group"><label class="small font-weight-bold">Reference</label><input type="text" name="reference[]" class="form-control form-control-sm rounded-0"></div>
					<div class="form-group"><label class="small font-weight-bold">Amount <span class="text-muted">(negative = money out)</span></label><input type="number" step="any" name="amount[]" class="form-control form-control-sm rounded-0" required></div>
				</div>
				<div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-primary">Add</button></div>
			</form>
		</div>
	</div>
</div>

<script>
	var REC_ID = <?php echo (int)$id ?>;
	var selected_line_id = null, selected_line_amount = null;

	$(function(){
		// --- Select a statement line to match ---
		$(document).on('click', '.select_line_btn', function(){
			selected_line_id = $(this).data('line-id');
			selected_line_amount = parseFloat($(this).data('amount'));
			$('.rec-line').removeClass('rec-sel');
			$(this).closest('.rec-line').addClass('rec-sel');
			$('#match_hint').show();
			// Show confirm buttons on book entries whose movement equals the line amount (within a cent), highlight exact matches.
			$('.book-entry').each(function(){
				var mv = parseFloat($(this).data('movement'));
				var $btn = $(this).find('.confirm_match_btn');
				$btn.show();
				if (Math.abs(mv - selected_line_amount) <= 0.01) { $(this).addClass('rec-sel'); } else { $(this).removeClass('rec-sel'); }
			});
		});

		// --- Confirm a match ---
		$(document).on('click', '.confirm_match_btn', function(){
			if (!selected_line_id) { alert_toast("Pick a statement line on the left first.", 'warning'); return; }
			var journal_id = $(this).data('journal-id');
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=match_statement_line",
				method:"POST", data:{ statement_line_id:selected_line_id, journal_id:journal_id }, dataType:"json",
				error:err=>{ console.log(err); alert_toast("An error occurred.",'error'); end_loader(); },
				success:function(resp){ if(resp.status=='success'){ location.reload(); } else { alert_toast(resp.msg || "Could not match.",'error'); end_loader(); } }
			});
		});

		// --- Unmatch ---
		$(document).on('click', '.unmatch_btn', function(){
			var lid = $(this).data('line-id'); start_loader();
			$.ajax({ url:_base_url_+"classes/Master.php?f=unmatch_statement_line", method:"POST", data:{ statement_line_id:lid }, dataType:"json",
				error:err=>{ console.log(err); end_loader(); },
				success:function(resp){ location.reload(); } });
		});

		// --- Delete statement line ---
		$(document).on('click', '.del_line_btn', function(){
			var lid = $(this).data('line-id'); if(!confirm('Delete this statement line?')) return; start_loader();
			$.ajax({ url:_base_url_+"classes/Master.php?f=delete_statement_line", method:"POST", data:{ id:lid }, dataType:"json",
				error:err=>{ console.log(err); end_loader(); },
				success:function(resp){ location.reload(); } });
		});

		// --- Manual add ---
		$('#btn_add_manual').click(function(){ $('#modal_manual').modal('show'); });
		$('#form_manual').submit(function(e){
			e.preventDefault(); start_loader();
			$.ajax({ url:_base_url_+"classes/Master.php?f=add_statement_lines", method:"POST", data:$(this).serialize(), dataType:"json",
				error:err=>{ console.log(err); alert_toast("An error occurred.",'error'); end_loader(); },
				success:function(resp){ if(resp.status=='success'){ location.reload(); } else { alert_toast(resp.msg || "Could not add line.",'error'); end_loader(); } } });
		});

		// --- CSV import (parsed client-side, posted as line arrays) ---
		$('#btn_import_csv').click(function(){ $('#csv_file').click(); });
		$('#csv_file').change(function(e){
			var file = e.target.files[0]; if(!file) return;
			var reader = new FileReader();
			reader.onload = function(ev){
				var rows = ev.target.result.split(/\r?\n/).filter(r => r.trim() !== '');
				var data = { reconciliation_id: REC_ID, 'line_date[]':[], 'description[]':[], 'reference[]':[], 'amount[]':[] };
				var start = 0;
				// Skip a header row if the first cell isn't a parseable date-ish / number
				if (rows.length && /date|description|amount/i.test(rows[0])) start = 1;
				var count = 0;
				for (var r = start; r < rows.length; r++){
					var cols = rows[r].split(',');
					if (cols.length < 3) continue;
					var d = (cols[0]||'').trim().replace(/"/g,'');
					var desc = (cols[1]||'').trim().replace(/"/g,'');
					var amt = parseFloat((cols[2]||'').replace(/[^0-9.\-]/g,''));
					if (!d || isNaN(amt)) continue;
					data['line_date[]'].push(d);
					data['description[]'].push(desc);
					data['reference[]'].push((cols[3]||'').trim().replace(/"/g,''));
					data['amount[]'].push(amt);
					count++;
				}
				if (count === 0) { alert_toast("No valid rows found in the CSV.", 'warning'); return; }
				start_loader();
				$.ajax({ url:_base_url_+"classes/Master.php?f=add_statement_lines", method:"POST", data:data, dataType:"json",
					error:err=>{ console.log(err); alert_toast("Import failed.",'error'); end_loader(); },
					success:function(resp){ if(resp.status=='success'){ alert_toast(resp.added+" line(s) imported.", 'success'); setTimeout(()=>location.reload(), 600); } else { alert_toast(resp.msg||"Import failed.",'error'); end_loader(); } } });
			};
			reader.readAsText(file);
			$(this).val('');
		});

		// --- Finalize / reopen ---
		$('#btn_finalize').click(function(){
			if(!confirm('Finalize this reconciliation? It will be locked.')) return; start_loader();
			$.ajax({ url:_base_url_+"classes/Master.php?f=finalize_reconciliation", method:"POST", data:{ id:REC_ID }, dataType:"json",
				error:err=>{ console.log(err); end_loader(); },
				success:function(resp){ if(resp.status=='success'){ location.reload(); } else { alert_toast(resp.msg||"Could not finalize.",'error'); end_loader(); } } });
		});
		$('#btn_reopen').click(function(){
			start_loader();
			$.ajax({ url:_base_url_+"classes/Master.php?f=reopen_reconciliation", method:"POST", data:{ id:REC_ID }, dataType:"json",
				error:err=>{ console.log(err); end_loader(); },
				success:function(resp){ location.reload(); } });
		});
	});
</script>
