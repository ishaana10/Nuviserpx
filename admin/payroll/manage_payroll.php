<?php
require_once(__DIR__ . '/../../config.php');

$is_batch = isset($_GET['batch']) && $_GET['batch'] == 1;

if(isset($_GET['id']) && $_GET['id'] > 0){
    $qry = $conn->query("SELECT * from `payroll_list` where id = '{$_GET['id']}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

$tenant_id = $_settings->active_tenant_id();
?>

<div class="container-fluid">
<?php if($is_batch): ?>
	<form action="" id="batch-payroll-form">
		<input type="hidden" name="action" value="save_batch_payroll">

		<div class="card card-outline card-primary mb-3">
			<div class="card-header py-2">
				<h6 class="card-title font-weight-bold text-primary m-0"><i class="fas fa-calendar-alt"></i> Run Details</h6>
			</div>
			<div class="card-body p-3">
				<div class="row">
					<div class="col-md-3 form-group">
						<label for="payroll_run_ref" class="control-label">Run Reference</label>
						<input type="text" name="payroll_run_ref" id="payroll_run_ref" class="form-control form-control-sm rounded-0" value="RUN-<?php echo date('Ym') . '-' . rand(100,999) ?>" required/>
					</div>
					<div class="col-md-3 form-group">
						<label for="salary_month" class="control-label">Salary Period / Month</label>
						<input type="text" name="salary_month" id="salary_month" placeholder="e.g. September 2024" class="form-control form-control-sm rounded-0" value="<?php echo date('F Y'); ?>" required/>
					</div>
					<div class="col-md-2 form-group">
						<label for="pay_frequency" class="control-label">Pay Frequency</label>
						<select name="pay_frequency" id="pay_frequency" class="form-control form-control-sm rounded-0" required>
							<option value="Monthly">Monthly</option>
							<option value="Fortnightly">Fortnightly</option>
							<option value="Weekly">Weekly</option>
							<option value="Bi-weekly">Bi-weekly</option>
						</select>
					</div>
					<div class="col-md-2 form-group">
						<label for="period_start" class="control-label">Period Start</label>
						<input type="date" name="period_start" id="period_start" class="form-control form-control-sm rounded-0" value="<?php echo date('Y-m-01'); ?>" required/>
					</div>
					<div class="col-md-2 form-group">
						<label for="period_end" class="control-label">Period End</label>
						<input type="date" name="period_end" id="period_end" class="form-control form-control-sm rounded-0" value="<?php echo date('Y-m-t'); ?>" required/>
					</div>
				</div>
				<div class="row">
					<div class="col-md-3 form-group">
						<label for="pay_date" class="control-label">Pay Date</label>
						<input type="date" name="pay_date" id="pay_date" class="form-control form-control-sm rounded-0" value="<?php echo date('Y-m-d'); ?>" required/>
					</div>
				</div>
			</div>
		</div>

		<div class="card card-outline card-info">
			<div class="card-header py-2 d-flex align-items-center justify-content-between">
				<h6 class="card-title font-weight-bold text-info m-0"><i class="fas fa-users"></i> Select Employees & Earnings Overrides</h6>
				<div>
					<button type="button" class="btn btn-xs btn-outline-primary" id="select_all_emp"><i class="fas fa-check-square"></i> Select All</button>
					<button type="button" class="btn btn-xs btn-outline-secondary" id="deselect_all_emp"><i class="fas fa-square"></i> Deselect All</button>
				</div>
			</div>
			<div class="card-body p-0 table-responsive" style="max-height: 450px;">
				<table class="table table-sm table-hover table-bordered m-0">
					<thead class="bg-light sticky-top">
						<tr>
							<th class="text-center" width="40"><input type="checkbox" id="check_master"></th>
							<th>Employee</th>
							<th width="120">Basic Salary</th>
							<th width="110">Overtime</th>
							<th width="110">Allowances</th>
							<th width="110">Other Earnings</th>
							<th width="110">Deductions</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$emp_qry = $conn->query("SELECT *, concat(firstname, ' ', lastname) as name FROM `employee_list` WHERE tenant_id = '{$tenant_id}' AND status = 1 AND delete_flag = 0 ORDER BY firstname ASC");
						while($emp = $emp_qry->fetch_assoc()):
							$eid = $emp['id'];
						?>
						<tr>
							<td class="text-center align-middle">
								<input type="checkbox" name="employee_ids[]" value="<?php echo $eid ?>" class="emp-checkbox" checked>
							</td>
							<td class="align-middle">
								<p class="m-0 font-weight-bold small"><?php echo $emp['employee_code'] . ' - ' . $emp['name'] ?></p>
								<small class="text-muted">TIN: <?php echo !empty($emp['tin']) ? $emp['tin'] : 'N/A' ?> | Bank: <?php echo strtoupper($emp['bank_code']) ?></small>
							</td>
							<td>
								<input type="number" step="any" name="emp_basic[<?php echo $eid ?>]" class="form-control form-control-sm text-right rounded-0" value="<?php echo $emp['salary'] ?>">
							</td>
							<td>
								<input type="number" step="any" name="emp_overtime[<?php echo $eid ?>]" class="form-control form-control-sm text-right rounded-0" value="0">
							</td>
							<td>
								<input type="number" step="any" name="emp_allowances[<?php echo $eid ?>]" class="form-control form-control-sm text-right rounded-0" value="0">
							</td>
							<td>
								<input type="number" step="any" name="emp_other_earnings[<?php echo $eid ?>]" class="form-control form-control-sm text-right rounded-0" value="0">
							</td>
							<td>
								<input type="number" step="any" name="emp_deductions[<?php echo $eid ?>]" class="form-control form-control-sm text-right rounded-0" value="0">
							</td>
						</tr>
						<?php endwhile; ?>
					</tbody>
				</table>
			</div>
		</div>
	</form>

<?php else: ?>

	<form action="" id="payroll-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="employee_id" class="control-label">Employee</label>
				<select name="employee_id" id="employee_id" class="form-control form-control-sm rounded-0 select2" required>
					<option value="" disabled <?php echo !isset($employee_id) ? "selected" : "" ?>></option>
					<?php
					$emp_qry = $conn->query("SELECT *, concat(firstname, ' ', lastname) as name FROM `employee_list` where tenant_id = '{$tenant_id}' and status = 1 and delete_flag = 0 order by firstname asc");
					while($row = $emp_qry->fetch_assoc()):
					?>
					<option value="<?php echo $row['id'] ?>"
						data-salary="<?php echo $row['salary'] ?>"
						data-resident="<?php echo $row['is_resident'] ?>"
						data-tin="<?php echo $row['tin'] ?>"
						data-fnpf="<?php echo $row['fnpf_no'] ?>"
						<?php echo isset($employee_id) && $employee_id == $row['id'] ? 'selected' : '' ?>>
						<?php echo $row['employee_code'] . ' - ' . $row['name'] ?>
					</option>
					<?php endwhile; ?>
				</select>
			</div>
			<div class="col-md-6 form-group">
				<label for="salary_month" class="control-label">Salary Period / Month</label>
				<input type="text" name="salary_month" id="salary_month" placeholder="e.g. September 2024" class="form-control form-control-sm rounded-0" value="<?php echo isset($salary_month) ? $salary_month : date('F Y'); ?>" required/>
			</div>
		</div>

		<div class="row">
			<div class="col-md-3 form-group">
				<label for="pay_frequency" class="control-label">Pay Frequency</label>
				<select name="pay_frequency" id="pay_frequency" class="form-control form-control-sm rounded-0 calc-trigger" required>
					<option value="Monthly" <?php echo isset($pay_frequency) && $pay_frequency == 'Monthly' ? 'selected' : '' ?>>Monthly (12)</option>
					<option value="Fortnightly" <?php echo isset($pay_frequency) && $pay_frequency == 'Fortnightly' ? 'selected' : '' ?>>Fortnightly (26)</option>
					<option value="Weekly" <?php echo isset($pay_frequency) && $pay_frequency == 'Weekly' ? 'selected' : '' ?>>Weekly (52)</option>
					<option value="Bi-weekly" <?php echo isset($pay_frequency) && $pay_frequency == 'Bi-weekly' ? 'selected' : '' ?>>Bi-weekly (24)</option>
				</select>
			</div>
			<div class="col-md-3 form-group">
				<label for="period_start" class="control-label">Period Start</label>
				<input type="date" name="period_start" id="period_start" class="form-control form-control-sm rounded-0" value="<?php echo isset($period_start) ? $period_start : date('Y-m-01'); ?>" required/>
			</div>
			<div class="col-md-3 form-group">
				<label for="period_end" class="control-label">Period End</label>
				<input type="date" name="period_end" id="period_end" class="form-control form-control-sm rounded-0" value="<?php echo isset($period_end) ? $period_end : date('Y-m-t'); ?>" required/>
			</div>
			<div class="col-md-3 form-group">
				<label for="pay_date" class="control-label">Pay Date</label>
				<input type="date" name="pay_date" id="pay_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($pay_date) ? $pay_date : date('Y-m-d'); ?>" required/>
			</div>
		</div>

		<div class="row">
			<div class="col-md-3 form-group">
				<label for="basic_salary" class="control-label">Basic Salary</label>
				<input type="number" step="any" name="basic_salary" id="basic_salary" class="form-control form-control-sm rounded-0 text-right calc-trigger" value="<?php echo isset($basic_salary) ? $basic_salary : 0; ?>" required/>
			</div>
			<div class="col-md-3 form-group">
				<label for="overtime" class="control-label">Overtime</label>
				<input type="number" step="any" name="overtime" id="overtime" class="form-control form-control-sm rounded-0 text-right calc-trigger" value="<?php echo isset($overtime) ? $overtime : 0; ?>" />
			</div>
			<div class="col-md-3 form-group">
				<label for="allowances" class="control-label">Allowances</label>
				<input type="number" step="any" name="allowances" id="allowances" class="form-control form-control-sm rounded-0 text-right calc-trigger" value="<?php echo isset($allowances) ? $allowances : 0; ?>" />
			</div>
			<div class="col-md-3 form-group">
				<label for="other_earnings" class="control-label">Other Earnings</label>
				<input type="number" step="any" name="other_earnings" id="other_earnings" class="form-control form-control-sm rounded-0 text-right calc-trigger" value="<?php echo isset($other_earnings) ? $other_earnings : 0; ?>" />
			</div>
		</div>

		<div class="row">
			<div class="col-md-4 form-group">
				<label for="deductions" class="control-label">Other Deductions</label>
				<input type="number" step="any" name="deductions" id="deductions" class="form-control form-control-sm rounded-0 text-right calc-trigger" value="<?php echo isset($deductions) ? $deductions : 0; ?>" />
			</div>
			<div class="col-md-4 form-group">
				<label for="is_resident" class="control-label">Residency Status</label>
				<select name="is_resident" id="is_resident" class="form-control form-control-sm rounded-0 calc-trigger">
					<option value="1" <?php echo !isset($is_resident) || $is_resident == 1 ? 'selected' : '' ?>>Resident (Fiji Progressive Tax)</option>
					<option value="0" <?php echo isset($is_resident) && $is_resident == 0 ? 'selected' : '' ?>>Non-Resident (20% Flat)</option>
				</select>
			</div>
			<div class="col-md-4 form-group">
				<label for="payroll_run_ref" class="control-label">Payroll Run Reference</label>
				<input type="text" name="payroll_run_ref" id="payroll_run_ref" class="form-control form-control-sm rounded-0" value="<?php echo isset($payroll_run_ref) ? $payroll_run_ref : 'RUN-'.date('Ym').'-'.rand(100,999); ?>" />
			</div>
		</div>

		<div class="card bg-light border p-3 mt-2">
			<h6 class="font-weight-bold text-navy mb-2"><i class="fas fa-calculator"></i> Real-time Fiji Payroll Calculation Summary</h6>
			<div class="row text-center">
				<div class="col-md-2">
					<small class="text-muted d-block">Gross Earnings</small>
					<span class="font-weight-bold text-dark" id="lbl_gross">0.00</span> FJD
				</div>
				<div class="col-md-2">
					<small class="text-muted d-block">Employee FNPF (8%)</small>
					<span class="font-weight-bold text-info" id="lbl_emp_fnpf">0.00</span> FJD
				</div>
				<div class="col-md-2">
					<small class="text-muted d-block">PAYE Tax + SRT</small>
					<span class="font-weight-bold text-warning" id="lbl_paye">0.00</span> FJD
				</div>
				<div class="col-md-2">
					<small class="text-muted d-block">Total Deductions</small>
					<span class="font-weight-bold text-danger" id="lbl_deductions">0.00</span> FJD
				</div>
				<div class="col-md-2">
					<small class="text-muted d-block">Net Salary Payable</small>
					<span class="font-weight-bold text-success h6 mb-0" id="lbl_net">0.00</span> FJD
				</div>
				<div class="col-md-2">
					<small class="text-muted d-block">Employer FNPF (8%)</small>
					<span class="font-weight-bold text-primary" id="lbl_empr_fnpf">0.00</span> FJD
				</div>
			</div>
		</div>
	</form>

<?php endif; ?>
</div>

<script>
	function calculate_fiji_live(){
		if($('#payroll-form').length == 0) return;

		var basic = parseFloat($('#basic_salary').val()) || 0;
		var overtime = parseFloat($('#overtime').val()) || 0;
		var allowances = parseFloat($('#allowances').val()) || 0;
		var other = parseFloat($('#other_earnings').val()) || 0;
		var deductions = parseFloat($('#deductions').val()) || 0;
		var frequency = $('#pay_frequency').val();
		var resident = $('#is_resident').val();

		$.ajax({
			url: _base_url_ + "classes/Master.php?f=calculate_payroll_ajax",
			method: "POST",
			data: {
				basic_salary: basic,
				overtime: overtime,
				allowances: allowances,
				other_earnings: other,
				deductions: deductions,
				pay_frequency: frequency,
				is_resident: resident
			},
			dataType: "json",
			success: function(resp){
				if(resp.status == 'success'){
					var d = resp.data;
					$('#lbl_gross').text(parseFloat(d.gross_salary).toFixed(2));
					$('#lbl_emp_fnpf').text(parseFloat(d.employee_fnpf).toFixed(2));
					$('#lbl_paye').text(parseFloat(d.paye_tax).toFixed(2));
					$('#lbl_deductions').text((parseFloat(d.employee_fnpf) + parseFloat(d.paye_tax) + parseFloat(d.deductions)).toFixed(2));
					$('#lbl_net').text(parseFloat(d.net_salary).toFixed(2));
					$('#lbl_empr_fnpf').text(parseFloat(d.employer_fnpf).toFixed(2));
				}
			}
		});
	}

	$(document).ready(function(){
		$('.select2').select2({
			placeholder:"Please select here",
			width:'100%',
			dropdownParent:$('#uni_modal')
		});

		$('#check_master').change(function(){
			$('.emp-checkbox').prop('checked', $(this).is(':checked'));
		});

		$('#select_all_emp').click(function(){
			$('.emp-checkbox, #check_master').prop('checked', true);
		});

		$('#deselect_all_emp').click(function(){
			$('.emp-checkbox, #check_master').prop('checked', false);
		});

		$('#employee_id').change(function(){
			var sal = $(this).find(':selected').attr('data-salary');
			var res = $(this).find(':selected').attr('data-resident');
			if(sal){
				$('#basic_salary').val(sal);
			}
			if(res !== undefined){
				$('#is_resident').val(res);
			}
			calculate_fiji_live();
		});

		$('.calc-trigger').on('input change', function(){
			calculate_fiji_live();
		});

		calculate_fiji_live();

		$('#payroll-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_payroll",
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
						location.reload()
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

		$('#batch-payroll-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_batch_payroll",
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
						location.reload()
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