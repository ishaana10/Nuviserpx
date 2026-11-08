<?php
require_once(__DIR__ . '/../../config.php');
if(isset($_GET['id']) && $_GET['id'] > 0){
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * from `leave_list` where id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $qry = $stmt->get_result();
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
    $stmt->close();
}
?>
<div class="container-fluid">
	<form action="" id="leave-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<?php $__scope = $_settings->employee_scope('leaves'); ?>
		<?php if($__scope !== null): ?>
		<div class="form-group">
			<label class="control-label">Employee</label>
			<?php
			$__e = $__scope > 0 ? $conn->query("SELECT employee_code, concat(firstname,' ',lastname) as name FROM `employee_list` WHERE id = '".(int)$__scope."'") : false;
			$__emp = ($__e && $__e->num_rows) ? $__e->fetch_assoc() : null;
			?>
			<?php if($__emp): ?>
			<input type="hidden" name="employee_id" value="<?php echo (int)$__scope ?>">
			<input type="text" class="form-control form-control-sm rounded-0 bg-light" value="<?php echo htmlspecialchars($__emp['employee_code'].' - '.$__emp['name']) ?>" readonly>
			<?php else: ?>
			<div class="alert alert-warning mb-0">Your account isn't linked to an employee profile, so you can't submit this. Please ask your administrator.</div>
			<?php endif; ?>
		</div>
		<?php else: ?>
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
		<?php endif; ?>
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
		<div class="alert alert-light border py-2 text-sm" id="leave-balance-hint" style="display:none"></div>
		<div class="form-group">
			<label for="reason" class="control-label">Reason</label>
			<textarea name="reason" id="reason" rows="3" class="form-control form-control-sm rounded-0" required><?php echo isset($reason) ? $reason : ''; ?></textarea>
		</div>
		<?php if(isset($id) && isset($status)): ?>
		<div class="form-group">
			<label class="control-label">Current Status</label>
			<div>
				<?php
				$__st = strtolower($status);
				$__badge = $__st === 'approved' ? 'success' : ($__st === 'pending' ? 'warning' : ($__st === 'cancelled' ? 'secondary' : 'danger'));
				?>
				<span class="badge badge-<?php echo $__badge ?> px-3 py-2 text-capitalize"><?php echo htmlspecialchars($status) ?></span>
				<small class="text-muted d-block mt-1"><i>Approval or rejection is done by the assigned approver from the Leave Applications list.</i></small>
			</div>
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
		function refreshBalance(){
			var emp = $('#leave-form [name=employee_id]').val();
			var box = $('#leave-balance-hint');
			if(!$('#leave_type').val() || !$('#start_date').val() || !$('#end_date').val()){ box.hide(); return; }
			$.post(_base_url_+"classes/Master.php?f=leave_balance_info", {employee_id: emp, leave_type: $('#leave_type').val(), start_date: $('#start_date').val(), end_date: $('#end_date').val(), id: $('#leave-form [name=id]').val()}, function(r){
				if(r.status !== 'success'){ box.hide(); return; }
				var t = [];
				if(r.days !== null) t.push('This request: <b>' + r.days + '</b> working day' + (r.days == 1 ? '' : 's'));
				if(r.tracked) t.push('Balance by ' + $('#end_date').val() + ': <b>' + parseFloat(r.balance).toFixed(2) + '</b> days');
				if(t.length){ box.html(t.join(' &middot; ')).show(); } else { box.hide(); }
			}, 'json');
		}
		$('#leave-form').on('change', '[name=employee_id], #leave_type, #start_date, #end_date', refreshBalance);
		refreshBalance();
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
