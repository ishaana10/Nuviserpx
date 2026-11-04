<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-shopping-cart text-primary"></i> On-The-Go Orders & Proforma Invoices</h3>
		<div class="card-tools">
			<a href="./?page=sales_orders/manage_sales_order" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-plus"></span> New Order / Proforma</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-hover table-striped table-bordered" id="list">
				<colgroup>
					<col width="5%">
					<col width="12%">
					<col width="20%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
					<col width="12%">
					<col width="15%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Order No</th>
						<th>Customer / Party</th>
						<th>Order Date</th>
						<th>Type</th>
						<th>Total<?php echo $_settings->currency_tag() ?></th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$i = 1;
						$tenant_id = $_settings->active_tenant_id();
						$qry = $conn->query("SELECT s.*, p.name as party_name, p.party_code from `sales_order_list` s inner join `party_list` p on s.party_id = p.id where s.tenant_id = '{$tenant_id}' order by s.id desc ");
						while($row = $qry->fetch_assoc()):
							$badge_class = 'info';
							if($row['status'] == 'confirmed') $badge_class = 'primary';
							elseif($row['status'] == 'converted_to_invoice') $badge_class = 'success';
							elseif($row['status'] == 'draft') $badge_class = 'secondary';
							elseif($row['status'] == 'cancelled') $badge_class = 'dark';
							elseif($row['status'] == 'declined') $badge_class = 'danger';
							elseif($row['status'] == 'superseded') $badge_class = 'light';
							$isQuote = \App\Services\QuotationService::isQuote((string)$row['type']);
							$isOpen = in_array($row['status'], ['proforma', 'draft'], true);
							$expired = $isQuote && $isOpen && \App\Services\QuotationService::isExpired($row['valid_until'] ?? null, date('Y-m-d'));
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><b><?php echo htmlspecialchars(\App\Services\QuotationService::label((string)$row['order_no'], (int)($row['version'] ?? 1))) ?></b></td>
							<td>
								<p class="m-0 font-weight-bold"><?php echo $row['party_name'] ?></p>
								<small class="text-muted"><?php echo $row['party_code'] ?></small>
							</td>
							<td><?php echo date("d M Y", strtotime($row['order_date'])) ?></td>
							<td>
								<span class="badge badge-light border text-dark px-2 py-1">
									<?php echo strtoupper($row['type']) ?>
								</span>
							</td>
							<td class="text-right font-weight-bold">$<?php echo number_format($row['total'], 2) ?></td>
							<td class="text-center">
                                <span class="badge badge-<?php echo $badge_class ?> px-2 py-1">
                                    <?php echo strtoupper(str_replace('_', ' ', $row['status'])) ?>
                                </span>
                                <?php if($isQuote && $isOpen && !empty($row['valid_until'])): ?>
                                    <br><small class="<?php echo $expired ? 'text-danger font-weight-bold' : 'text-muted' ?>"><?php echo $expired ? 'Expired ' : 'Valid until ' ?><?php echo date('d M Y', strtotime($row['valid_until'])) ?></small>
                                <?php endif; ?>
                            </td>
							<td align="center">
								 <button type="button" class="btn btn-flat p-1 btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
							Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item" href="./?page=sales_orders/view&id=<?php echo $row['id'] ?>"><span class="fa fa-eye text-info"></span> View Order / Proforma</a>
				                    <?php if($row['status'] == 'proforma' || $row['status'] == 'draft'): ?>
				                    <?php if(!$expired): ?><a class="dropdown-item confirm_order" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-check-circle text-success"></span> Confirm Sale & Auto Stock Adjust</a><?php endif; ?>
				                    <a class="dropdown-item" href="./?page=sales_orders/manage_sales_order&id=<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                    <?php if($isQuote): ?>
				                    <a class="dropdown-item extend_quote" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-calendar-plus text-info"></span> Extend validity</a>
				                    <a class="dropdown-item decline_quote" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-times-circle text-warning"></span> Mark declined</a>
				                    <?php endif; ?>
				                    <?php endif; ?>
				                    <?php if($isQuote && in_array($row['status'], ['proforma','draft','declined'], true)): ?>
				                    <a class="dropdown-item revise_quote" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-code-branch text-primary"></span> Revise (new version)</a>
				                    <?php endif; ?>
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
</div>
<script>
	$(document).ready(function(){
		$('.delete_data').click(function(){
			_conf("Are you sure to delete this order? If stock was adjusted, it will be restored.","delete_sales_order",[$(this).attr('data-id')])
		})
		var quoteAction = function(f, data){
			start_loader();
			$.post(_base_url_ + 'classes/Master.php?f=' + f, data, function(r){
				if(r && r.status === 'success'){ location.reload(); }
				else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
			}, 'json').fail(function(){ alert_toast('An error occurred.', 'error'); end_loader(); });
		};
		$('.revise_quote').click(function(){
			var id = $(this).data('id');
			if(window.confirm('Make a new version of this quote? The current version is marked superseded and can no longer be accepted.')){ quoteAction('revise_quote', {id: id}); }
		});
		$('.decline_quote').click(function(){
			var id = $(this).data('id'), reason = window.prompt('Why was the quote declined?');
			if(reason){ quoteAction('decline_quote', {id: id, reason: reason}); }
		});
		$('.extend_quote').click(function(){
			var id = $(this).data('id'), d = window.prompt('New valid-until date (YYYY-MM-DD):');
			if(d){ quoteAction('extend_quote', {id: id, valid_until: d}); }
		});
		$('.confirm_order').click(function(){
			_conf("Confirm this sale? Stock quantity will be automatically deducted and a Sales Invoice will be raised.","confirm_sales_order",[$(this).attr('data-id')])
		})
		$('#list').dataTable({
			columnDefs: [
					{ orderable: false, targets: [7] }
			],
			order: [0, 'asc']
		});
	})
	function confirm_sales_order($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=confirm_sales_order",
			method:"POST",
			data:{id: $id},
			dataType:"json",
			error:err=>{
				console.log(err)
				alert_toast("An error occurred.",'error');
				end_loader();
			},
			success:function(resp){
				if(typeof resp== 'object' && resp.status == 'success'){
					location.reload();
				}else{
					alert_toast((resp && resp.msg) || "An error occurred.",'error');
					end_loader();
				}
			}
		})
	}
	function delete_sales_order($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_sales_order",
			method:"POST",
			data:{id: $id},
			dataType:"json",
			error:err=>{
				console.log(err)
				alert_toast("An error occurred.",'error');
				end_loader();
			},
			success:function(resp){
				if(typeof resp== 'object' && resp.status == 'success'){
					location.reload();
				}else{
					alert_toast("An error occurred.",'error');
					end_loader();
				}
			}
		})
	}
</script>