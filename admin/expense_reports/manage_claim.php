<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();
?>

<div class="container-fluid">
    <form action="" id="expense-claim-form" enctype="multipart/form-data">
        <div class="row">
            <div class="col-md-6 form-group">
                <label for="employee_id" class="font-weight-bold text-dark text-sm">Employee <span class="text-danger">*</span></label>
                <select name="employee_id" id="employee_id" class="form-control form-control-sm select2" style="width: 100%;" required>
                    <option value="">Select Employee...</option>
                    <?php
                    $employees = $conn->query("SELECT id, employee_code, firstname, lastname FROM `employee_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY firstname ASC");
                    while($e = $employees->fetch_assoc()):
                    ?>
                        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['firstname'] . ' ' . $e['lastname'] . ' (' . $e['employee_code'] . ')') ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label for="claim_date" class="font-weight-bold text-dark text-sm">Expense Date <span class="text-danger">*</span></label>
                <input type="date" id="claim_date" name="claim_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="category" class="font-weight-bold text-dark text-sm">Category <span class="text-danger">*</span></label>
                <select name="category" id="category" class="form-control form-control-sm select2" style="width: 100%;" required>
                    <option value="Travel & Lodging">Travel & Lodging</option>
                    <option value="Meals & Entertainment">Meals & Entertainment</option>
                    <option value="Office Supplies">Office Supplies</option>
                    <option value="Vehicle & Fuel">Vehicle & Fuel</option>
                    <option value="Client Expenses">Client Expenses</option>
                    <option value="Other">Other Miscellaneous</option>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label for="amount" class="font-weight-bold text-dark text-sm">Total Claim Amount ($) <span class="text-danger">*</span></label>
                <input type="number" step="any" id="amount" name="amount" class="form-control form-control-sm text-right" placeholder="0.00" required>
            </div>
        </div>

        <div class="form-group mb-3">
            <label for="attachment" class="font-weight-bold text-dark text-sm">Receipt Attachment</label>
            <input type="file" id="attachment" name="attachment" class="form-control-file text-sm">
            <small class="text-muted">Allowed formats: PDF, JPG, PNG, DOCX (Max 10MB)</small>
        </div>

        <div class="form-group mb-3">
            <label for="description" class="font-weight-bold text-dark text-sm">Expense Description / Business Justification</label>
            <textarea id="description" name="description" rows="3" class="form-control form-control-sm rounded-0" placeholder="State business reason for expense..."></textarea>
        </div>
    </form>
</div>

<script>
$(document).ready(function(){
    $('#uni_modal').on('shown.bs.modal', function(){
        $('.select2').select2({
            dropdownParent: $('#uni_modal')
        });
    });

    $('#uni_modal #expense-claim-form').submit(function(e){
        e.preventDefault();
        start_loader();
        var formData = new FormData($(this)[0]);
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_expense_claim",
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            type: 'POST',
            dataType: 'json',
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to submit claim.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
