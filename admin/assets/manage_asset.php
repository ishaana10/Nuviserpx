<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$id = isset($_GET['id']) ? $_GET['id'] : '';
if(!empty($id)){
    $qry = $conn->query("SELECT * FROM `fixed_assets` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        $res = $qry->fetch_assoc();
        foreach($res as $k => $v){
            $$k = $v;
        }
    }
}

// Generate auto asset number if creating new asset
if(empty($asset_number)){
    $last_asset = $conn->query("SELECT asset_number FROM `fixed_assets` WHERE tenant_id = '{$tenant_id}' ORDER BY id DESC LIMIT 1");
    if($last_asset && $last_asset->num_rows > 0){
        $num = $last_asset->fetch_assoc()['asset_number'];
        $num_code = (int)filter_var($num, FILTER_SANITIZE_NUMBER_INT);
        $asset_number = 'FA-' . sprintf('%04d', $num_code + 1);
    } else {
        $asset_number = 'FA-0001';
    }
}
?>

<style>
.asset-container {
    max-width: 1100px;
    margin: 0 auto;
}
.asset-card-section {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.asset-section-header {
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 18px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
}
.form-label-custom {
    font-weight: 600;
    font-size: 0.88rem;
    color: #334155;
    margin-bottom: 6px;
}
.calc-radio-box {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 10px 16px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    gap: 20px;
}
</style>

<div class="asset-container my-3">
    <!-- Header bar -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="./?page=assets" class="text-secondary mr-2" title="Back to Fixed Assets"><i class="fas fa-times fa-lg"></i></a>
            <h3 class="font-weight-bold text-dark m-0"><?= empty($id) ? 'New asset' : 'Edit asset' ?></h3>
        </div>
        <div>
            <a href="./?page=assets" class="btn btn-outline-secondary btn-flat mr-2">Cancel</a>
        </div>
    </div>

    <form action="" id="asset-form" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= isset($id) ? $id : '' ?>">
        <input type="hidden" name="status" id="asset_status" value="<?= isset($status) ? $status : 'draft' ?>">

        <!-- Details Section -->
        <div class="asset-card-section">
            <div class="d-flex align-items-center justify-content-between asset-section-header">
                <span>Details</span>
                <div>
                    <label for="attachment_file" class="btn btn-sm btn-outline-secondary btn-flat m-0"><i class="fas fa-paperclip mr-1"></i> Attach file</label>
                    <input type="file" id="attachment_file" name="attachment" class="d-none" onchange="$('#attachment-file-name').text(this.files[0] ? this.files[0].name : '')">
                    <span id="attachment-file-name" class="text-muted text-xs ml-2"><?= !empty($attachment_path) ? basename($attachment_path) : '' ?></span>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8 form-group">
                    <label class="form-label-custom">Asset name <span class="text-danger">*</span></label>
                    <input type="text" name="asset_name" class="form-control form-control-sm rounded-0" value="<?= isset($asset_name) ? htmlspecialchars($asset_name) : '' ?>" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label-custom">Asset number <span class="text-danger">*</span></label>
                    <input type="text" name="asset_number" class="form-control form-control-sm rounded-0" value="<?= isset($asset_number) ? htmlspecialchars($asset_number) : '' ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Purchase date</label>
                    <input type="date" name="purchase_date" class="form-control form-control-sm rounded-0" value="<?= isset($purchase_date) ? $purchase_date : date('Y-m-d') ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Purchase price</label>
                    <input type="number" step="any" name="purchase_price" class="form-control form-control-sm rounded-0 text-right" placeholder="0.00" value="<?= isset($purchase_price) ? $purchase_price : '' ?>">
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 form-group">
                    <label class="form-label-custom">Asset type</label>
                    <select name="asset_type" class="form-control form-control-sm rounded-0 select2">
                        <option value="">Select asset type...</option>
                        <option value="Computer Equipment" <?= isset($asset_type) && $asset_type == 'Computer Equipment' ? 'selected' : '' ?>>Computer Equipment</option>
                        <option value="Office Equipment" <?= isset($asset_type) && $asset_type == 'Office Equipment' ? 'selected' : '' ?>>Office Equipment</option>
                        <option value="Furniture & Fittings" <?= isset($asset_type) && $asset_type == 'Furniture & Fittings' ? 'selected' : '' ?>>Furniture & Fittings</option>
                        <option value="Motor Vehicles" <?= isset($asset_type) && $asset_type == 'Motor Vehicles' ? 'selected' : '' ?>>Motor Vehicles</option>
                        <option value="Plant & Machinery" <?= isset($asset_type) && $asset_type == 'Plant & Machinery' ? 'selected' : '' ?>>Plant & Machinery</option>
                        <option value="Buildings" <?= isset($asset_type) && $asset_type == 'Buildings' ? 'selected' : '' ?>>Buildings</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Warranty expiry</label>
                    <input type="date" name="warranty_expiry" class="form-control form-control-sm rounded-0" value="<?= isset($warranty_expiry) ? $warranty_expiry : '' ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Serial number</label>
                    <input type="text" name="serial_number" class="form-control form-control-sm rounded-0" value="<?= isset($serial_number) ? htmlspecialchars($serial_number) : '' ?>">
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 form-group mb-0">
                    <label class="form-label-custom">Description</label>
                    <textarea name="description" rows="3" class="form-control form-control-sm rounded-0"><?= isset($description) ? htmlspecialchars($description) : '' ?></textarea>
                </div>
            </div>
        </div>

        <!-- Book Depreciation Settings Section -->
        <div class="asset-card-section">
            <div class="asset-section-header">Book depreciation settings</div>

            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="form-label-custom">Depreciation start date</label>
                    <input type="date" name="dep_start_date" class="form-control form-control-sm rounded-0" value="<?= isset($dep_start_date) ? $dep_start_date : date('Y-m-d') ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label-custom">Depreciation method</label>
                    <select name="dep_method" class="form-control form-control-sm rounded-0 select2">
                        <option value="">Select a depreciation method</option>
                        <option value="Straight Line" <?= isset($dep_method) && $dep_method == 'Straight Line' ? 'selected' : '' ?>>Straight Line</option>
                        <option value="Diminishing Value" <?= isset($dep_method) && $dep_method == 'Diminishing Value' ? 'selected' : '' ?>>Diminishing Value</option>
                        <option value="Full Depreciation" <?= isset($dep_method) && $dep_method == 'Full Depreciation' ? 'selected' : '' ?>>Full Depreciation</option>
                        <option value="No Depreciation" <?= isset($dep_method) && $dep_method == 'No Depreciation' ? 'selected' : '' ?>>No Depreciation</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label-custom">Averaging method</label>
                    <select name="averaging_method" class="form-control form-control-sm rounded-0 select2">
                        <option value="">Select averaging method...</option>
                        <option value="Full Month" <?= isset($averaging_method) && $averaging_method == 'Full Month' ? 'selected' : '' ?>>Full Month</option>
                        <option value="Actual Days" <?= isset($averaging_method) && $averaging_method == 'Actual Days' ? 'selected' : '' ?>>Actual Days</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 form-group">
                    <label class="form-label-custom">Calculate depreciation based on</label>
                    <div class="calc-radio-box">
                        <div class="custom-control custom-radio mr-4">
                            <input type="radio" id="dep_calc_rate" name="dep_calc_by" value="rate" class="custom-control-input" <?= (!isset($dep_calc_by) || $dep_calc_by == 'rate') ? 'checked' : '' ?> onchange="toggleDepInput()">
                            <label class="custom-control-label font-weight-normal" for="dep_calc_rate">Rate (%)</label>
                        </div>
                        <div class="custom-control custom-radio">
                            <input type="radio" id="dep_calc_life" name="dep_calc_by" value="effective_life" class="custom-control-input" <?= (isset($dep_calc_by) && $dep_calc_by == 'effective_life') ? 'checked' : '' ?> onchange="toggleDepInput()">
                            <label class="custom-control-label font-weight-normal" for="dep_calc_life">Effective life (years)</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row" id="rate_input_row">
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Depreciation rate (%)</label>
                    <input type="number" step="any" name="dep_rate" class="form-control form-control-sm rounded-0" placeholder="e.g. 20.00" value="<?= isset($dep_rate) ? $dep_rate : '' ?>">
                </div>
            </div>

            <div class="row" id="life_input_row" style="display: none;">
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Effective life (years)</label>
                    <input type="number" step="any" name="effective_life_years" class="form-control form-control-sm rounded-0" placeholder="e.g. 5" value="<?= isset($effective_life_years) ? $effective_life_years : '' ?>">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Cost limit</label>
                    <input type="number" step="any" name="cost_limit" class="form-control form-control-sm rounded-0 text-right" placeholder="0.00" value="<?= isset($cost_limit) ? $cost_limit : '' ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label-custom">Residual value</label>
                    <input type="number" step="any" name="residual_value" class="form-control form-control-sm rounded-0 text-right" placeholder="0.00" value="<?= isset($residual_value) ? $residual_value : '' ?>">
                </div>
            </div>
        </div>

        <!-- Action Footer -->
        <div class="d-flex align-items-center justify-content-end gap-2 pb-4">
            <button type="button" class="btn btn-outline-secondary btn-flat mr-2" id="btn-save-draft"><i class="fas fa-save mr-1"></i> Save as draft</button>
            <button type="button" class="btn btn-secondary btn-flat" style="background-color: #64748b; border-color: #64748b;" id="btn-register"><i class="fas fa-check-circle mr-1"></i> Register</button>
        </div>
    </form>
</div>

<script>
function toggleDepInput(){
    if($('#dep_calc_life').is(':checked')){
        $('#life_input_row').show();
        $('#rate_input_row').hide();
    } else {
        $('#rate_input_row').show();
        $('#life_input_row').hide();
    }
}

$(document).ready(function(){
    $('.select2').select2({ width: '100%' });
    toggleDepInput();

    $('#btn-save-draft').click(function(){
        $('#asset_status').val('draft');
        $('#asset-form').submit();
    });

    $('#btn-register').click(function(){
        $('#asset_status').val('registered');
        $('#asset-form').submit();
    });

    $('#asset-form').submit(function(e){
        e.preventDefault();
        start_loader();
        var formData = new FormData($(this)[0]);
        $.ajax({
            url: _base_url_+"classes/Master.php?f=save_asset",
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            type: 'POST',
            dataType: 'json',
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", 'error');
                end_loader();
            },
            success: function(resp){
                if(typeof resp == 'object' && resp.status == 'success'){
                    location.href = "./?page=assets/view_asset&id=" + resp.id;
                } else if(!!resp.msg){
                    alert_toast(resp.msg, 'error');
                } else {
                    alert_toast("An error occurred due to unknown reason.", 'error');
                }
                end_loader();
            }
        });
    });
});
</script>
