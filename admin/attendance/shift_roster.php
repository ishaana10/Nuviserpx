<?php
$tenant_id = $_settings->active_tenant_id();

$shifts_qry = $conn->query("SELECT * FROM `shift_types` WHERE tenant_id = '{$tenant_id}' ORDER BY name ASC");

$assignments_qry = $conn->query("
    SELECT sa.*, st.name as shift_name, st.start_time, st.end_time, e.firstname, e.lastname, e.employee_code
    FROM `shift_assignments` sa
    LEFT JOIN `shift_types` st ON sa.shift_type_id = st.id
    LEFT JOIN `employee_list` e ON sa.employee_id = e.id
    WHERE sa.tenant_id = '{$tenant_id}'
    ORDER BY sa.id DESC
");
?>

<div class="row">
    <!-- Shift Definitions List -->
    <div class="col-md-5 mb-4">
        <div class="card card-outline card-primary shadow-sm rounded-0">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title font-weight-bold"><i class="fas fa-clock text-primary mr-2"></i> Shift Types & Hours</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-xs btn-primary btn-flat" onclick="add_shift_modal()"><i class="fas fa-plus mr-1"></i> New Shift</button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover table-striped mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Shift Name</th>
                            <th class="text-center">Working Hours</th>
                            <th class="text-center">Break</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if($shifts_qry && $shifts_qry->num_rows > 0):
                            while($st = $shifts_qry->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="font-weight-bold align-middle"><?= htmlspecialchars($st['name']) ?></td>
                            <td class="text-center align-middle text-xs">
                                <span class="badge badge-light border px-2 py-1">
                                    <?= date("g:i A", strtotime($st['start_time'])) ?> - <?= date("g:i A", strtotime($st['end_time'])) ?>
                                </span>
                            </td>
                            <td class="text-center align-middle text-xs"><?= $st['break_duration_mins'] ?> mins</td>
                        </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">No shifts configured yet.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Active Employee Roster -->
    <div class="col-md-7 mb-4">
        <div class="card card-outline card-info shadow-sm rounded-0">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title font-weight-bold"><i class="fas fa-calendar-alt text-info mr-2"></i> Employee Shift Roster Assignments</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-xs btn-info btn-flat text-white" onclick="assign_roster_modal()"><i class="fas fa-user-clock mr-1"></i> Assign Roster</button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered" id="roster-table">
                        <thead>
                            <tr class="bg-gradient-navy text-white">
                                <th>Employee</th>
                                <th>Assigned Shift</th>
                                <th class="text-center">Start Date</th>
                                <th class="text-center">End Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if($assignments_qry && $assignments_qry->num_rows > 0):
                                while($sa = $assignments_qry->fetch_assoc()):
                            ?>
                            <tr>
                                <td class="font-weight-bold align-middle">
                                    <?= htmlspecialchars($sa['firstname'] . ' ' . $sa['lastname']) ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($sa['employee_code']) ?></small>
                                </td>
                                <td class="align-middle">
                                    <span class="badge badge-info px-2 py-1"><?= htmlspecialchars($sa['shift_name']) ?></span>
                                    <small class="d-block text-muted"><?= date("g:i A", strtotime($sa['start_time'])) ?> - <?= date("g:i A", strtotime($sa['end_time'])) ?></small>
                                </td>
                                <td class="text-center align-middle text-xs"><?= date("d M Y", strtotime($sa['start_date'])) ?></td>
                                <td class="text-center align-middle text-xs"><?= $sa['end_date'] ? date("d M Y", strtotime($sa['end_date'])) : 'Ongoing' ?></td>
                            </tr>
                            <?php
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No roster assignments found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Shift Definition -->
<div class="modal fade" id="shiftModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="shift-form">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Create Shift Definition</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="shift_name" class="font-weight-bold text-dark text-sm">Shift Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="shift_name" class="form-control form-control-sm" placeholder="e.g. Day Shift 8AM-5PM" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="start_time" class="font-weight-bold text-dark text-sm">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="start_time" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="end_time" class="font-weight-bold text-dark text-sm">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="end_time" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label for="break_duration_mins" class="font-weight-bold text-dark text-sm">Unpaid Break Duration (Minutes)</label>
                        <input type="number" name="break_duration_mins" id="break_duration_mins" class="form-control form-control-sm" value="30">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary font-weight-bold">Save Shift</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Assign Roster -->
<div class="modal fade" id="rosterModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="roster-form">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold">Assign Employee Shift Roster</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="roster_emp_id" class="font-weight-bold text-dark text-sm">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" id="roster_emp_id" class="form-control form-control-sm" required>
                            <option value="">Select Employee...</option>
                            <?php
                            $employees = $conn->query("SELECT id, employee_code, firstname, lastname FROM `employee_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY firstname ASC");
                            while($e = $employees->fetch_assoc()):
                            ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['firstname'] . ' ' . $e['lastname'] . ' (' . $e['employee_code'] . ')') ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="roster_shift_id" class="font-weight-bold text-dark text-sm">Shift Type <span class="text-danger">*</span></label>
                        <select name="shift_type_id" id="roster_shift_id" class="form-control form-control-sm" required>
                            <option value="">Select Shift...</option>
                            <?php
                            $shifts = $conn->query("SELECT id, name, start_time, end_time FROM `shift_types` WHERE tenant_id = '{$tenant_id}' ORDER BY name ASC");
                            while($s = $shifts->fetch_assoc()):
                            ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= date("g:i A", strtotime($s['start_time'])) ?> - <?= date("g:i A", strtotime($s['end_time'])) ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-0">
                            <label for="roster_start_date" class="font-weight-bold text-dark text-sm">Roster Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="roster_start_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group mb-0">
                            <label for="roster_end_date" class="font-weight-bold text-dark text-sm">End Date <small class="text-muted">(optional)</small></label>
                            <input type="date" name="end_date" id="roster_end_date" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-info font-weight-bold text-white">Save Roster</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function add_shift_modal(){
    $('#shiftModal').modal('show');
}
function assign_roster_modal(){
    $('#rosterModal').modal('show');
}

$(document).ready(function(){
    $('#roster-table').dataTable();

    $('#shift-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_shift_type",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to save shift.", "error");
                    end_loader();
                }
            }
        });
    });

    $('#roster-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_shift_assignment",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to assign roster.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
