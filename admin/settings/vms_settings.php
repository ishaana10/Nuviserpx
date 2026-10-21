<?php
$tenant_id = $_settings->active_tenant_id();

require_once(base_app . 'src/Services/VmsService.php');
$vmsService = new \App\Services\VmsService($conn, $_settings);
$config = $vmsService->getTenantVmsConfig($tenant_id);
?>

<div class="card card-outline card-warning shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-microchip text-warning mr-2"></i> Tenant FRCS VMS v3.0 Integration & SDC Configuration</h3>
        <div class="card-tools">
            <button type="submit" form="tenant-vms-form" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save VMS Settings</button>
        </div>
    </div>
    <div class="card-body">
        <form action="" id="tenant-vms-form">
            <div class="alert alert-warning border-warning text-sm mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i> Configure Fiji Revenue & Customs Service (FRCS) VAT Monitoring System (VMS v3.0) SDC Smartcard parameters for fiscal invoice generation.
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="mode" class="font-weight-bold text-dark text-sm">VMS Integration Mode</label>
                    <select name="mode" id="mode" class="form-control form-control-sm rounded-0" required>
                        <option value="sandbox" <?= ($config['mode'] ?? 'sandbox') == 'sandbox' ? 'selected' : '' ?>>Sandbox / Test Environment Simulator</option>
                        <option value="production" <?= ($config['mode'] ?? '') == 'production' ? 'selected' : '' ?>>Production (Live SDC Smartcard Device)</option>
                        <option value="disabled" <?= ($config['mode'] ?? '') == 'disabled' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="col-md-6 form-group">
                    <label for="tin" class="font-weight-bold text-dark text-sm">Taxpayer TIN Number <span class="text-danger">*</span></label>
                    <input type="text" name="tin" id="tin" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($config['tin'] ?? '50-00000-0-0') ?>" placeholder="e.g. 50-00000-0-0" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8 form-group">
                    <label for="sdc_url" class="font-weight-bold text-dark text-sm">SDC Server API Endpoint URL <span class="text-danger">*</span></label>
                    <input type="text" name="sdc_url" id="sdc_url" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($config['sdc_url'] ?? 'http://localhost:8088/api/v1/sdc') ?>" placeholder="e.g. http://localhost:8088/api/v1/sdc" required>
                </div>
                <div class="col-md-4 form-group">
                    <label for="default_tax_group" class="font-weight-bold text-dark text-sm">Default Tax Label</label>
                    <select name="default_tax_group" id="default_tax_group" class="form-control form-control-sm rounded-0">
                        <option value="A" <?= ($config['default_tax_group'] ?? 'A') == 'A' ? 'selected' : '' ?>>Label A (VAT Standard 15%)</option>
                        <option value="B" <?= ($config['default_tax_group'] ?? '') == 'B' ? 'selected' : '' ?>>Label B (Tax Exempt 0%)</option>
                        <option value="C" <?= ($config['default_tax_group'] ?? '') == 'C' ? 'selected' : '' ?>>Label C (Zero Rated Exports 0%)</option>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6 form-group">
                    <label for="pos_operator_id" class="font-weight-bold text-dark text-sm">POS Operator ID</label>
                    <input type="text" name="pos_operator_id" id="pos_operator_id" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($config['pos_operator_id'] ?? 'OPERATOR_01') ?>" placeholder="OPERATOR_01">
                </div>
                <div class="col-md-6 form-group">
                    <label for="pac_code" class="font-weight-bold text-dark text-sm">SDC Smartcard PAC Code</label>
                    <input type="password" name="pac_code" id="pac_code" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($config['pac_code'] ?? '') ?>" placeholder="PAC Verification Code">
                </div>
            </div>
        </form>

        <h5 class="font-weight-bold text-dark border-top pt-3 mb-3"><i class="fas fa-certificate text-secondary mr-2"></i> Tenant FRCS PKCS#12 Digital Smartcard Certificate (.p12)</h5>
        <form action="" id="vsdc-cert-form" enctype="multipart/form-data">
            <div class="row align-items-end">
                <div class="col-md-6 form-group mb-0">
                    <label for="p12_cert" class="font-weight-bold text-dark text-sm">Upload SDC Certificate (.p12 / .pfx)</label>
                    <input type="file" name="p12_cert" id="p12_cert" class="form-control-file text-sm" accept=".p12,.pfx" required>
                    <small class="text-muted">Digital certificate issued by FRCS EFD Portal</small>
                </div>
                <div class="col-md-4 form-group mb-0">
                    <label for="cert_pass" class="font-weight-bold text-dark text-sm">Certificate Password / Key</label>
                    <input type="password" name="cert_pass" id="cert_pass" class="form-control form-control-sm rounded-0" placeholder="Certificate Password">
                </div>
                <div class="col-md-2 form-group mb-0">
                    <button type="submit" class="btn btn-sm btn-outline-warning btn-flat font-weight-bold w-100"><i class="fas fa-upload mr-1"></i> Upload Cert</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#tenant-vms-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_tenant_vms_settings",
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
                    alert_toast(resp.msg, "success");
                    end_loader();
                } else {
                    alert_toast(resp.msg || "Failed to save VMS settings.", "error");
                    end_loader();
                }
            }
        });
    });

        $('#vsdc-cert-form').submit(function(e){
            e.preventDefault();
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=upload_tenant_vsdc_cert",
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: "json",
                error: err => {
                    console.log(err);
                    alert_toast("An error occurred uploading certificate.", "error");
                    end_loader();
                },
                success: function(resp){
                    end_loader();
                    if(resp.status == 'success'){
                        alert_toast(resp.msg, "success");
                    } else {
                        alert_toast(resp.msg || "Failed to upload certificate.", "error");
                    }
                }
            });
        });
});
</script>
