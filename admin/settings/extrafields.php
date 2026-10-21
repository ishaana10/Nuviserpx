<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$qry = $conn->query("SELECT * FROM `custom_extrafields` WHERE tenant_id = '{$tenant_id}' ORDER BY id DESC");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-sliders-h text-primary mr-2"></i> Custom Extrafields Generator</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" id="btn_new_extrafield"><i class="fas fa-plus mr-1"></i> Add Custom Attribute</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">
            Dolibarr-inspired Extrafields Engine. Dynamically attach custom input attributes to system entities (Invoices, Customers, Fixed Assets, Support Tickets) without modifying code.
        </p>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="extrafield-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Entity Target</th>
                        <th>Field Code</th>
                        <th>Field Label</th>
                        <th>Data Type</th>
                        <th class="text-center">Required?</th>
                        <th class="text-center" style="width: 10%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-uppercase text-primary"><?= htmlspecialchars($row['element_type']) ?></td>
                        <td class="align-middle font-weight-bold"><code><?= htmlspecialchars($row['field_code']) ?></code></td>
                        <td class="align-middle"><?= htmlspecialchars($row['field_label']) ?></td>
                        <td class="align-middle text-capitalize"><span class="badge badge-light border"><?= htmlspecialchars($row['field_type']) ?></span></td>
                        <td class="text-center align-middle">
                            <?= $row['is_required'] == 1 ? '<span class="badge badge-danger">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?>
                        </td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-xs btn-outline-danger btn-flat delete_extrafield" data-id="<?= $row['id'] ?>"><i class="fas fa-trash mr-1"></i> Delete</button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($qry->num_rows <= 0): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No custom extrafields created yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal for New Custom Extrafield -->
<div class="modal fade" id="extrafieldModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-plus mr-1"></i> Add Custom Extrafield</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="extrafield-form">
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-sm">Target Entity <span class="text-danger">*</span></label>
                        <select name="element_type" class="form-control form-control-sm rounded-0" required>
                            <option value="invoice">Sales Invoices & Bills</option>
                            <option value="party">Customers & Contacts</option>
                            <option value="asset">Fixed Assets</option>
                            <option value="ticket">Support Tickets</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-sm">Field Code (Unique Slug) <span class="text-danger">*</span></label>
                        <input type="text" name="field_code" class="form-control form-control-sm rounded-0" placeholder="e.g. tax_registration_no" required>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-sm">Field Label <span class="text-danger">*</span></label>
                        <input type="text" name="field_label" class="form-control form-control-sm rounded-0" placeholder="e.g. Tax Registration Number" required>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-sm">Data Type <span class="text-danger">*</span></label>
                        <select name="field_type" class="form-control form-control-sm rounded-0" required>
                            <option value="text">Short Text String</option>
                            <option value="number">Numeric Value</option>
                            <option value="date">Date Picker</option>
                            <option value="select">Dropdown Options</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-sm">Options (Comma-separated for select)</label>
                        <input type="text" name="field_options" class="form-control form-control-sm rounded-0" placeholder="Option 1, Option 2, Option 3">
                    </div>

                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="is_required" name="is_required" value="1">
                        <label class="custom-control-label text-sm" for="is_required">Mandatory field?</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-flat" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save Extrafield</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#btn_new_extrafield').click(function(){
        $('#extrafieldModal').modal('show');
    });

    $('.delete_extrafield').click(function(){
        var id = $(this).data('id');
        if(confirm("Delete this custom extrafield attribute?")){
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=delete_extrafield",
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
                        alert_toast(resp.msg || "Failed to delete extrafield.", "error");
                        end_loader();
                    }
                }
            });
        }
    });

    $('#extrafield-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_extrafield",
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
                    alert_toast(resp.msg || "Failed to save extrafield.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
