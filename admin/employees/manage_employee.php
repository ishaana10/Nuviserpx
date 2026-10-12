<?php
require_once(__DIR__ . '/../../config.php');
if(isset($_GET['id']) && $_GET['id'] > 0){
    $qry = $conn->query("SELECT * from `employee_list` where id = '{$_GET['id']}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
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
				<label for="phone" class="control-label">Phone</label>
				<input type="text" name="phone" id="phone" class="form-control form-control-sm rounded-0" value="<?php echo isset($phone) ? $phone : ''; ?>" />
			</div>
			<div class="col-md-6 form-group">
				<label for="salary" class="control-label">Basic Monthly Salary (FJD)</label>
				<input type="number" step="any" name="salary" id="salary" class="form-control form-control-sm rounded-0 text-right" value="<?php echo isset($salary) ? $salary : 0; ?>" required/>
			</div>
		</div>

		<hr class="my-3">
		<h6 class="text-navy font-weight-bold"><i class="fas fa-file-invoice"></i> Fiji Tax & FNPF Information</h6>

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
					<option value="anz" <?php echo isset($bank_code) && $bank_code == 'anz' ? 'selected' : '' ?>>ANZ Fiji</option>
					<option value="hfc" <?php echo isset($bank_code) && $bank_code == 'hfc' ? 'selected' : '' ?>>HFC Bank (Fiji)</option>
					<option value="bred" <?php echo isset($bank_code) && $bank_code == 'bred' ? 'selected' : '' ?>>BRED Bank (Fiji)</option>
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
				<input type="date" name="date_of_joining" id="date_of_joining" class="form-control form-control-sm rounded-0" value="<?php echo isset($date_of_joining) ? $date_of_joining : date('Y-m-d'); ?>" />
			</div>
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
</script>