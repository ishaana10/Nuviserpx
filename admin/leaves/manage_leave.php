<?php
require_once(__DIR__ . '/../../config.php');
if(isset($_GET['id']) && $_GET['id'] > 0){
    $qry = $conn->query("SELECT * from `leave_list` where id = '{$_GET['id']}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
?>
<div class="container-fluid">
	<form action="" id="leave-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<div class="form-group">
			<label for="employee_id" class="control-label">Employee</label>
			<select name="employee_id" id="employee_id" class="form-control form-control-sm rounded-0 select2" required>
				<option value="" disabled <?php echo !isset($employee_id) ? "selected" : "" ?>></option>
				<?php
				$tenant_id = $_settings->active_tenant_id();
				$emp_qry = $conn->query("SELECT *, concat(firstname, ' ', lastname) as name FROM `employee_list` where tenant_id = '{$tenant_id}' and status = 1 and delete_flag = 0 order by firstname asc");
				while($row = $emp_qry->fetch_assoc()):
				?>
				<option value="<?php echo $row['id'] ?>" <?php echo isset($employee_id) && $employee_id == $row['id'] ? 'selected' : '' ?>><?php echo $row['employee_code'] . ' - ' . $row['name'] ?></option>
				<?php endwhile; ?>
			</select>
		</div>
		<div class="form-group">
			<label for="leave_type" class="control-label">Leave Type</label>
			<select name="leave_type" id="leave_type" class="form-control form-control-sm rounded-0" required>
				<option value="casual" <?php echo isset($leave_type) && $leave_type == 'casual' ? 'selected' : '' ?>>Casual Leave</option>
				<option value="sick" <?php echo isset($leave_type) && $leave_type == 'sick' ? 'selected' : '' ?>>Sick Leave</option>
				<option value="paid" <?php echo isset($leave_type) && $leave_type == 'paid' ? 'selected' : '' ?>>Paid Leave</option>
				<option value="unpaid" <?php echo isset($leave_type) && $leave_type == 'unpaid' ? 'selected' : '' ?>>Unpaid Leave</option>
			</select>
		</div>
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="start_date" class="control-label">Start Date</label>
				<input type="date" name="start_date" id="start_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($start_date) ? $start_date : date('Y-m-d'); ?>" required/>
			</div>
			<div class="col-md-6 form-group">
				<label for="end_date" class="control-label">End Date</label>
				<input type="date" name="end_date" id="end_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($end_date) ? $end_date : date('Y-m-d'); ?>" required/>
			</div>
		</div>
		<div class="form-group">
			<label for="reason" class="control-label">Reason</label>
			<textarea name="reason" id="reason" rows="3" class="form-control form-control-sm rounded-0" required><?php echo isset($reason) ? $reason : ''; ?></textarea>
		</div>
		<?php if(isset($id)): ?>
		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="pending" <?php echo isset($status) && $status == 'pending' ? 'selected' : '' ?>>Pending</option>
				<option value="approved" <?php echo isset($status) && $status == 'approved' ? 'selected' : '' ?>>Approved</option>
				<option value="rejected" <?php echo isset($status) && $status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
			</select>
		</div>
		<?php endif; ?>
	</form>
</div>
<script>
	$(document).ready(function(){
		$('.select2').select2({
			placeholder:"Please select here",
			width:'100%',
			dropdownParent:$('#uni_modal')
		})
		$('#leave-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			 $('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_leave",
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
