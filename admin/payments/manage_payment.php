<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $pay_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `payment_list` where id = '{$pay_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

$fx_svc = new \App\Services\FxService($conn, $_settings);
$fx_base = $fx_svc->baseCurrency();
$fx_currencies = $fx_svc->foreignCurrencies();
$fx_codes = array_map(fn($c) => strtoupper($c['code']), $fx_currencies);
$pay_currency = (isset($amount_fc) && $amount_fc !== null && !empty($currency)) ? strtoupper($currency) : $fx_base;
$preset_inv_currency = '';
if(isset($_GET['invoice_id']) && (int)$_GET['invoice_id'] > 0 && !isset($id)){
    $ic = $conn->query("SELECT currency FROM `invoice_list` WHERE id = '".(int)$_GET['invoice_id']."' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
    if(!empty($ic['currency'])) $pay_currency = strtoupper($ic['currency']);
}

$preset_party_id = isset($_GET['party_id']) ? (int)$_GET['party_id'] : (isset($party_id) ? $party_id : 0);
$preset_invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
?>

<div class="card card-outline card-success">
	<div class="card-header">
		<h3 class="card-title"><i class="fas fa-receipt text-success"></i> <?php echo isset($id) ? 'Edit Payment Receipt #'.$payment_no : 'Record New Payment Receipt' ?></h3>
	</div>
	<div class="card-body">
		<form action="" id="payment-form">
			<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
			<input type="hidden" name="type" value="receipt">

			<div class="row">
				<div class="col-md-4 form-group">
					<label for="party_id" class="control-label">Customer / Party</label>
					<select name="party_id" id="party_id" class="form-control form-control-sm rounded-0 select2" required>
						<option value="" disabled <?php echo empty($preset_party_id) ? 'selected' : '' ?>></option>
						<?php
						$parties = $conn->query("SELECT * FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
						while($p = $parties->fetch_assoc()):
						?>
						<option value="<?php echo $p['id'] ?>" <?php echo $preset_party_id == $p['id'] ? 'selected' : '' ?>>
							<?php echo $p['party_code'] . ' - ' . $p['name'] ?>
						</option>
						<?php endwhile; ?>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label for="payment_no" class="control-label">Receipt Number</label>
					<input type="text" name="payment_no" id="payment_no" class="form-control form-control-sm rounded-0" value="<?php echo isset($payment_no) ? $payment_no : 'REC-'.date('Ym').'-'.rand(1000,9999); ?>" required/>
				</div>
				<div class="col-md-2 form-group">
					<label for="payment_date" class="control-label">Payment Date</label>
					<input type="date" name="payment_date" id="payment_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($payment_date) ? $payment_date : date('Y-m-d'); ?>" required/>
				</div>
				<div class="col-md-3 form-group">
					<label for="amount" class="control-label">Amount Received (<span class="pay-cur"><?php echo htmlspecialchars($pay_currency) ?></span>)</label>
					<input type="number" step="any" name="amount" id="amount" class="form-control form-control-sm rounded-0 font-weight-bold text-success" value="<?php echo isset($amount_fc) && $amount_fc !== null ? rtrim(rtrim((string)$amount_fc, '0'), '.') : (isset($amount) ? $amount : '0.00'); ?>" required/>
				</div>
			</div>

			<div class="row">
				<div class="col-md-3 form-group">
					<label for="currency" class="control-label">Currency</label>
					<select name="currency" id="currency" class="form-control form-control-sm rounded-0">
						<option value="<?php echo htmlspecialchars($fx_base) ?>"><?php echo htmlspecialchars($fx_base) ?> (company currency)</option>
						<?php foreach($fx_currencies as $fc): ?>
						<option value="<?php echo htmlspecialchars($fc['code']) ?>" <?php echo $pay_currency === strtoupper($fc['code']) ? 'selected' : '' ?>><?php echo htmlspecialchars($fc['code'] . ' - ' . $fc['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-3 form-group" id="fx_rate_box" style="display:none">
					<label for="fx_rate" class="control-label">Rate on receipt date</label>
					<input type="number" step="any" min="0" name="fx_rate" id="fx_rate" class="form-control form-control-sm rounded-0" placeholder="Blank = rate on the date" value="<?php echo !empty($fx_rate) ? htmlspecialchars(rtrim(rtrim((string)$fx_rate, '0'), '.')) : '' ?>">
					<small class="text-muted">1 <span class="pay-cur"></span> = ? <?php echo htmlspecialchars($fx_base) ?>. Any difference from the invoice rate is booked as realised exchange gain or loss.</small>
				</div>
			</div>

			<div class="row">
				<div class="col-md-3 form-group">
					<label for="method" class="control-label">Payment Method</label>
					<select name="method" id="method" class="form-control form-control-sm rounded-0" required>
						<option value="cash" <?php echo !isset($method) || $method == 'cash' ? 'selected' : '' ?>>Cash</option>
						<option value="bank_transfer" <?php echo isset($method) && $method == 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer / Direct Deposit</option>
						<option value="cheque" <?php echo isset($method) && $method == 'cheque' ? 'selected' : '' ?>>Cheque</option>
						<option value="card" <?php echo isset($method) && $method == 'card' ? 'selected' : '' ?>>Credit / Debit Card</option>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label for="bank_account_id" class="control-label">Bank / Cash Account</label>
					<select name="bank_account_id" id="bank_account_id" class="form-control form-control-sm rounded-0">
						<option value="">Default Cash Account</option>
						<?php
						$accounts = $conn->query("SELECT * FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
						while($acc = $accounts->fetch_assoc()):
						?>
						<option value="<?php echo $acc['id'] ?>" <?php echo isset($bank_account_id) && $bank_account_id == $acc['id'] ? 'selected' : '' ?>>
							<?php echo $acc['name'] ?><?php echo in_array(strtoupper((string)$acc['currency']), $fx_codes, true) ? ' (' . strtoupper($acc['currency']) . ')' : '' ?>
						</option>
						<?php endwhile; ?>
					</select>
				</div>
				<div class="col-md-3 form-group">
					<label for="reference" class="control-label">Reference / Cheque No</label>
					<input type="text" name="reference" id="reference" class="form-control form-control-sm rounded-0" value="<?php echo isset($reference) ? $reference : ''; ?>" placeholder="e.g. TXN12345" />
				</div>
				<div class="col-md-3 form-group">
					<label for="status" class="control-label">Status</label>
					<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
						<option value="posted" <?php echo !isset($status) || $status == 'posted' ? 'selected' : '' ?>>Posted</option>
						<option value="draft" <?php echo isset($status) && $status == 'draft' ? 'selected' : '' ?>>Draft</option>
						<option value="cancelled" <?php echo isset($status) && $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
					</select>
				</div>
			</div>

			<hr class="my-3">
			<h6 class="font-weight-bold text-navy mb-3"><i class="fas fa-tasks"></i> Allocate Receipt to Open Invoices</h6>

			<div id="unpaid-invoices-container">
				<p class="text-muted small">Select a Customer / Party above to load open unpaid invoices.</p>
			</div>

			<div class="form-group mt-3">
				<label for="notes" class="control-label">Notes / Remarks</label>
				<textarea name="notes" id="notes" rows="2" class="form-control form-control-sm rounded-0" placeholder="Optional notes..."><?php echo isset($notes) ? $notes : ''; ?></textarea>
			</div>

			<div class="card-footer text-right bg-transparent border-top mt-3">
				<button class="btn btn-success btn-flat" type="submit"><i class="fas fa-save"></i> Save Payment Receipt</button>
				<a href="./?page=payments" class="btn btn-default btn-flat"><i class="fas fa-arrow-left"></i> Cancel</a>
			</div>
		</form>
	</div>
</div>

<script>
	var fx_base = <?php echo json_encode($fx_base) ?>;
	function load_party_invoices(party_id, payment_id, preset_inv_id){
		if(!party_id) return;

		$('#unpaid-invoices-container').html('<div class="text-center p-3"><i class="fas fa-spinner fa-spin text-primary"></i> Loading open invoices...</div>');

		$.ajax({
			url: _base_url_ + "admin/payments/get_unpaid_invoices.php",
			method: "POST",
			data: { party_id: party_id, payment_id: payment_id, preset_invoice_id: preset_inv_id, currency: $('#currency').val() },
			success: function(html){
				$('#unpaid-invoices-container').html(html);
			}
		});
	}

	$(document).ready(function(){
		$('.select2').select2({
			placeholder:"Please select customer",
			width:'100%'
		});

		var payment_id = '<?php echo isset($id) ? $id : 0 ?>';
		var preset_inv_id = '<?php echo $preset_invoice_id ?>';

		function sync_pay_currency(){
			var c = $('#currency').val();
			$('.pay-cur').text(c);
			$('#fx_rate_box').toggle(c !== fx_base);
		}
		$('#currency').on('change', function(){
			sync_pay_currency();
			load_party_invoices($('#party_id').val(), payment_id, preset_inv_id);
		});
		sync_pay_currency();

		$('#party_id').change(function(){
			load_party_invoices($(this).val(), payment_id, preset_inv_id);
		});

		if($('#party_id').val()){
			load_party_invoices($('#party_id').val(), payment_id, preset_inv_id);
		}

		$('#payment-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_payment",
				data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
				error:err=>{
					console.log(err)
					alert_toast("An error occurred",'error');
					end_loader();
				},
				success:function(resp){
					if(typeof resp =='object' && resp.status == 'success'){
						location.href = './?page=payments';
					}else if(resp.status == 'failed' && !!resp.msg){
                        var el = $('<div>')
                            el.addClass("alert alert-danger err-msg").text(resp.msg)
                            _this.prepend(el)
                            el.show('slow')
                            end_loader()
                    }else{
						alert_toast("An error occurred",'error');
						end_loader();
					}
				}
			})
		});
	});
</script>