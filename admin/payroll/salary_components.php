<?php
$tenant_id = $_settings->active_tenant_id();

$qry = $conn->query("SELECT * FROM `salary_components` WHERE tenant_id = '{$tenant_id}' ORDER BY type ASC, name ASC");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-sliders-h text-primary mr-2"></i> Fiji Salary Components & Payhead Builder</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" onclick="add_component_modal()"><i class="fas fa-plus mr-1"></i> Add Salary Component</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm mb-4">
            <i class="fas fa-info-circle mr-2"></i> Configure custom earnings, allowances, and deductions. Set statutory flags for Fiji PAYE Tax and FNPF Provident Fund inclusion.
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="components-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Component Code</th>
                        <th>Component Name</th>
                        <th class="text-center">Category</th>
                        <th class="text-center">Fiji PAYE Taxable</th>
                        <th class="text-center">FNPF Subject</th>
                        <th class="text-right">Default Amount</th>
                        <th class="text-center" style="width: 12%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['code']) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="text-center align-middle">
                            <?php if($row['type'] == 'earning'): ?>
                                <span class="badge badge-success px-2 py-1"><i class="fas fa-plus-circle mr-1"></i> Earning / Allowance</span>
                            <?php else: ?>
                                <span class="badge badge-danger px-2 py-1"><i class="fas fa-minus-circle mr-1"></i> Deduction</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if($row['is_taxable'] == 1): ?>
                                <span class="badge badge-warning px-2 py-1">Taxable</span>
                            <?php else: ?>
                                <span class="badge badge-light border px-2 py-1 text-muted">Tax Exempt</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if($row['is_fnpf_subject'] == 1): ?>
                                <span class="badge badge-info px-2 py-1">FNPF Subject</span>
                            <?php else: ?>
                                <span class="badge badge-light border px-2 py-1 text-muted">Exempt</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right align-middle font-weight-bold font-monospace">$<?= number_format($row['default_amount'], 2) ?></td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-xs btn-outline-danger btn-flat delete_component" data-id="<?= $row['id'] ?>"><i class="fas fa-trash mr-1"></i> Delete</button>
                        </td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No custom salary components created yet. Basic Salary, Overtime, and FNPF default components are active.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Salary Component -->
<div class="modal fade" id="compModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="comp-form">
                <input type="hidden" name="id" id="comp_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Create Salary Component / Payhead</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="comp_code" class="font-weight-bold text-dark text-sm">Code <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="comp_code" class="form-control form-control-sm text-uppercase" placeholder="e.g. HSG_ALLOW" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="comp_type" class="font-weight-bold text-dark text-sm">Category <span class="text-danger">*</span></label>
                            <select name="type" id="comp_type" class="form-control form-control-sm" required>
                                <option value="earning">Earning / Allowance</option>
                                <option value="deduction">Deduction</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="comp_name" class="font-weight-bold text-dark text-sm">Component Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="comp_name" class="form-control form-control-sm" placeholder="e.g. Housing & Utilities Allowance" required>
                    </div>

                    <div class="form-group">
                        <label for="comp_default_amount" class="font-weight-bold text-dark text-sm">Default Amount ($)</label>
                        <input type="number" step="0.01" name="default_amount" id="comp_default_amount" class="form-control form-control-sm text-right" value="0.00">
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" name="is_taxable" id="comp_is_taxable" value="1" checked>
                            <label class="custom-control-label font-weight-bold text-dark" for="comp_is_taxable">Subject to Fiji PAYE Tax</label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" name="is_fnpf_subject" id="comp_is_fnpf_subject" value="1" checked>
                            <label class="custom-control-label font-weight-bold text-dark" for="comp_is_fnpf_subject">Included in FNPF 8%/8% Calculation</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary font-weight-bold">Save Component</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function add_component_modal(){
    $('#comp_id').val('');
    $('#comp_code').val('');
    $('#comp_name').val('');
    $('#comp_default_amount').val('0.00');
    $('#comp_is_taxable').prop('checked', true);
    $('#comp_is_fnpf_subject').prop('checked', true);
    $('#compModal').modal('show');
}

$(document).ready(function(){
    $('#components-table').dataTable();

    $('#comp-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_salary_component",
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
                    alert_toast(resp.msg || "Failed to save salary component.", "error");
                    end_loader();
                }
            }
        });
    });

    $(document).on('click', '.delete_component', function(){
        var id = $(this).data('id');
        if(confirm("Delete this salary component?")){
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=delete_salary_component",
                method: "POST",
                data: {id: id},
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
                        alert_toast(resp.msg || "Failed to delete component.", "error");
                        end_loader();
                    }
                }
            });
        }
    });
});
</script>
