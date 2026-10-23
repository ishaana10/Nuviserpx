<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id > 0){
    $qry = $conn->query("SELECT * from `attendance_list` where id = '{$id}' and tenant_id = '{$tenant_id}' ");
    if($qry && $qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
?>
<div class="container-fluid">
	<form action="" id="attendance-form">
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
			<label for="attendance_date" class="control-label">Date</label>
			<input type="date" name="attendance_date" id="attendance_date" class="form-control form-control-sm rounded-0" value="<?php echo isset($attendance_date) ? $attendance_date : date('Y-m-d'); ?>" required/>
		</div>
		<div class="row">
			<div class="col-md-6 form-group">
				<label for="check_in" class="control-label">Check In</label>
				<input type="time" name="check_in" id="check_in" class="form-control form-control-sm rounded-0" value="<?php echo isset($check_in) ? $check_in : ''; ?>" />
			</div>
			<div class="col-md-6 form-group">
				<label for="check_out" class="control-label">Check Out</label>
				<input type="time" name="check_out" id="check_out" class="form-control form-control-sm rounded-0" value="<?php echo isset($check_out) ? $check_out : ''; ?>" />
			</div>
		</div>
		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="present" <?php echo isset($status) && $status == 'present' ? 'selected' : '' ?>>Present</option>
				<option value="late" <?php echo isset($status) && $status == 'late' ? 'selected' : '' ?>>Late</option>
				<option value="half_day" <?php echo isset($status) && $status == 'half_day' ? 'selected' : '' ?>>Half Day</option>
				<option value="absent" <?php echo isset($status) && $status == 'absent' ? 'selected' : '' ?>>Absent</option>
			</select>
		</div>
	</form>
</div>
<script>
	$(document).ready(function(){
		$('.select2').select2({
			placeholder:"Please select here",
			width:'100%',
			dropdownParent:$('#uni_modal')
		})
		$('#attendance-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			 $('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_attendance",
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
