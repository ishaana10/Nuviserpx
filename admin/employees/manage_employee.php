<?php
require_once(__DIR__ . '/../../config.php');

$can_create = $_settings->can_access('employees_create');
$can_edit   = $_settings->can_access('employees_edit');
$scope      = $_settings->employee_scope('employees');

if(isset($_GET['id']) && $_GET['id'] > 0){
    $id_clean = intval($_GET['id']);
    $tenant_id = $_settings->active_tenant_id();
    // Standard users may only view/edit their own employee record
    $where = "id = '{$id_clean}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0";
    if ($scope !== null) {
        $scope_id = intval($scope);
        $where .= " AND id = '{$scope_id}'";
    }
    $qry = $conn->query("SELECT * from `employee_list` where {$where}");
    if($qry && $qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    } else {
        echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>You do not have permission to view this employee record.</div>';
        return;
    }
} else {
    // Creating a new employee requires employees_create
    if(!$can_create){
        echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>You do not have permission to add employees.</div>';
        return;
    }
}

// Read-only mode for standard users viewing their own record without edit permission
$readonly = isset($id) && !$can_edit && $scope !== null;
?>
<div class="container-fluid">
	<form action="" id="employee-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<div class="form-group">
			<label for="employee_code" class="control-label">Employee Code / Staff No</label>
			<input type="text" name="employee_code" id="employee_code" class="form-control form-control-sm rounded-0" value="<?php echo isset($employee_code) ? $employee_code : ''; ?>" required/>
		</div>
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="firstname" class="control-label">First Name</label>
				<input type="text" name="firstname" id="firstname" class="form-control form-control-sm rounded-0" value="<?php echo isset($firstname) ? $firstname : ''; ?>" required/>
			</div>
			<div class="col-md-6 form-group">
				<label for="lastname" class="control-label">Last Name</label>
				<input type="text" name="lastname" id="lastname" class="form-control form-control-sm rounded-0" value="<?php echo isset($lastname) ? $lastname : ''; ?>" required/>
			</div>
		</div>
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="email" class="control-label">Email <small class="text-muted">(used for payslip self-service)</small></label>
				<input type="email" name="email" id="email" maxlength="150" class="form-control form-control-sm rounded-0" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" />
			</div>
			<div class="col-md-6 form-group">
				<label for="phone" class="control-label">Phone</label>
				<input type="text" name="phone" id="phone" class="form-control form-control-sm rounded-0" value="<?php echo isset($phone) ? $phone : ''; ?>" />
			</div>
		</div>
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="annual_salary" class="control-label">Annual Gross Salary<?php echo $_settings->currency_tag() ?></label>
				<input type="number" step="any" min="0" name="annual_salary" id="annual_salary" class="form-control form-control-sm rounded-0 text-right" value="<?php echo isset($annual_salary) && $annual_salary !== null && $annual_salary !== '' ? $annual_salary : (isset($salary) && (float)$salary > 0 ? number_format((float)$salary * 12, 2, '.', '') : 0); ?>" required/>
				<small class="text-muted" id="salary-breakdown"></small>
				<script>
					$(function(){
						function nv_breakdown(){
							var a = parseFloat($('#annual_salary').val()) || 0;
							$('#salary-breakdown').text(a > 0
								? '≈ ' + (a/12).toFixed(2) + ' monthly · ' + (a/26).toFixed(2) + ' fortnightly · ' + (a/52).toFixed(2) + ' weekly'
								: 'Per-period pay is worked out from this figure at run time.');
						}
						$('#annual_salary').on('input', nv_breakdown); nv_breakdown();
					});
				</script>
			</div>
		</div>

		<hr class="my-3">
		<h6 class="text-navy font-weight-bold"><i class="fas fa-file-invoice"></i> Tax & FNPF Information</h6>

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="tin" class="control-label">Tax Identification Number (TIN)</label>
				<input type="text" name="tin" id="tin" class="form-control form-control-sm rounded-0" value="<?php echo isset($tin) ? $tin : ''; ?>" placeholder="e.g. 99-99999-9" />
			</div>
			<div class="col-md-6 form-group">
				<label for="fnpf_no" class="control-label">FNPF Number</label>
				<input type="text" name="fnpf_no" id="fnpf_no" class="form-control form-control-sm rounded-0" value="<?php echo isset($fnpf_no) ? $fnpf_no : ''; ?>" placeholder="e.g. 1234567" />
			</div>
		</div>

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="tax_code" class="control-label">Tax Code</label>
				<select name="tax_code" id="tax_code" class="form-control form-control-sm rounded-0" required>
					<option value="P" <?php echo !isset($tax_code) || $tax_code == 'P' ? 'selected' : '' ?>>P - Primary Employment</option>
					<option value="S" <?php echo isset($tax_code) && $tax_code == 'S' ? 'selected' : '' ?>>S - Secondary Employment</option>
				</select>
			</div>
			<div class="col-md-6 form-group">
				<label for="is_resident" class="control-label">Residency Status</label>
				<select name="is_resident" id="is_resident" class="form-control form-control-sm rounded-0" required>
					<option value="1" <?php echo !isset($is_resident) || $is_resident == 1 ? 'selected' : '' ?>>Resident (Progressive PAYE + SRT)</option>
					<option value="0" <?php echo isset($is_resident) && $is_resident == 0 ? 'selected' : '' ?>>Non-Resident (Flat 20% Tax)</option>
				</select>
			</div>
		</div>

		<hr class="my-3">
		<h6 class="text-navy font-weight-bold"><i class="fas fa-university"></i> Banking Details for Direct Salary Credits</h6>

		<div class="row">
			<div class="col-md-6 form-group">
				<label for="bank_code" class="control-label">Bank</label>
				<select name="bank_code" id="bank_code" class="form-control form-control-sm rounded-0" required>
					<option value="bsp" <?php echo !isset($bank_code) || $bank_code == 'bsp' ? 'selected' : '' ?>>Bank of South Pacific (BSP)</option>
					<option value="anz" <?php echo isset($bank_code) && $bank_code == 'anz' ? 'selected' : '' ?>>ANZ</option>
					<option value="hfc" <?php echo isset($bank_code) && $bank_code == 'hfc' ? 'selected' : '' ?>>HFC Bank</option>
					<option value="bred" <?php echo isset($bank_code) && $bank_code == 'bred' ? 'selected' : '' ?>>BRED Bank</option>
					<option value="generic" <?php echo isset($bank_code) && $bank_code == 'generic' ? 'selected' : '' ?>>Generic / Other Bank</option>
				</select>
			</div>
			<div class="col-md-6 form-group">
				<label for="bank_account" class="control-label">Bank Account Number</label>
				<input type="text" name="bank_account" id="bank_account" class="form-control form-control-sm rounded-0" value="<?php echo isset($bank_account) ? $bank_account : ''; ?>" placeholder="Account Number" />
			</div>
		</div>

		<hr class="my-3">

		<div class="row">
			<div class="col-md-4 form-group">
				<label for="department" class="control-label">Department</label>
				<input type="text" name="department" id="department" class="form-control form-control-sm rounded-0" value="<?php echo isset($department) ? $department : ''; ?>" />
			</div>
			<div class="col-md-4 form-group">
				<label for="designation" class="control-label">Designation</label>
				<input type="text" name="designation" id="designation" class="form-control form-control-sm rounded-0" value="<?php echo isset($designation) ? $designation : ''; ?>" />
			</div>
			<div class="col-md-4 form-group">
				<label for="date_of_joining" class="control-label">Date of Joining</label>
				<input type="date" name="date_of_joining" id="date_of_joining" class="form-control form-control-sm rounded-0" value="<?php echo isset($date_of_joining) ? $date_of_joining : ''; ?>" />
				<small class="text-muted">Used for leave accrual and to pay a new starter only from this date.</small>
			</div>
			<div class="col-md-4 form-group">
				<label for="termination_date" class="control-label">Last Day of Employment</label>
				<input type="date" name="termination_date" id="termination_date" class="form-control form-control-sm rounded-0" value="<?php echo !empty($termination_date) ? htmlspecialchars($termination_date) : ''; ?>" />
				<small class="text-muted">Leave blank while employed. Pay stops after this date; the last payroll is part-paid, and leave payout and redundancy can be added to it.</small>
			</div>
		</div>

		<hr class="my-3">
		<h6 class="text-navy font-weight-bold"><i class="fas fa-user-check"></i> Leave Approval</h6>
		<div class="form-group">
			<label for="approver_id" class="control-label">Assigned Leave Approver</label>
			<select name="approver_id" id="approver_id" class="form-control form-control-sm rounded-0">
				<option value="">None (Super Administrator approves)</option>
				<?php
				$__apr_tenant = $_settings->active_tenant_id();
				$__self = isset($id) ? (int)$id : 0;
				$__apr_qry = $conn->query("SELECT id, employee_code, concat(firstname,' ',lastname) as name FROM `employee_list` WHERE tenant_id = '{$__apr_tenant}' AND delete_flag = 0 " . ($__self > 0 ? " AND id != '{$__self}'" : "") . " ORDER BY firstname ASC");
				if($__apr_qry): while($__apr = $__apr_qry->fetch_assoc()):
				?>
				<option value="<?php echo $__apr['id'] ?>" <?php echo isset($approver_id) && $approver_id == $__apr['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($__apr['employee_code'].' - '.$__apr['name']) ?></option>
				<?php endwhile; endif; ?>
			</select>
			<small class="text-muted"><i>This employee's leave requests are approved or rejected by the person selected here.</i></small>
		</div>

		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="1" <?php echo isset($status) && $status == 1 ? 'selected' : '' ?>>Active</option>
				<option value="0" <?php echo isset($status) && $status == 0 ? 'selected' : '' ?>>Inactive</option>
			</select>
		</div>
	</form>
</div>
<?php if($readonly): ?>
<script>
	// Read-only mode: disable all form fields for standard users viewing their own record
	$(document).ready(function(){
		$('#employee-form :input').prop('disabled', true);
	});
</script>
<?php else: ?>
<script>
	$(document).ready(function(){
		$('#employee-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			 $('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_employee",
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
                        console.log(resp)
					}
				}
			})
		})
	})
<?php endif; ?>
</script>