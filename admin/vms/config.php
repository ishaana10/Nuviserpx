<?php
use App\Core\Container;
use App\Services\VmsService;

$vmsService = Container::getInstance()->make(VmsService::class);
$tenant_id = $_settings->active_tenant_id();
$config = $vmsService->getConfig($tenant_id);
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-sliders-h text-primary mr-2"></i>FRCS VMS 3.0 Integration Settings</h3>
        <div class="card-tools">
            <a href="./?page=vms" class="btn btn-sm btn-default"><i class="fas fa-arrow-left mr-1"></i> Back to Dashboard</a>
        </div>
    </div>
    <div class="card-body">
        <form id="vms-config-form">
            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="sdc_url" class="control-label">SDC Endpoint URL <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="sdc_url" id="sdc_url" value="<?php echo htmlspecialchars($config['sdc_url'] ?? ''); ?>" required placeholder="https://vsdc.vms.frcs.org.fj/api/v3/sdc">
                    <small class="form-text text-muted">Virtual Sales Data Controller (V-SDC) API URL provided by FRCS.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label for="pac_code" class="control-label">PAC Code</label>
                    <input type="text" class="form-control" name="pac_code" id="pac_code" value="<?php echo htmlspecialchars($config['pac_code'] ?? ''); ?>" placeholder="Enter POS Authentication Code">
                    <small class="form-text text-muted">POS Access Code for V-SDC authentication.</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="cashier_tin" class="control-label">Cashier TIN</label>
                    <input type="text" class="form-control" name="cashier_tin" id="cashier_tin" value="<?php echo htmlspecialchars($config['cashier_tin'] ?? ''); ?>" placeholder="e.g. 999999999">
                    <small class="form-text text-muted">Tax Identification Number of default cashier or business unit.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label for="pos_number" class="control-label">POS Identification Number</label>
                    <input type="text" class="form-control" name="pos_number" id="pos_number" value="<?php echo htmlspecialchars($config['pos_number'] ?? 'POS-01'); ?>" required>
                    <small class="form-text text-muted">Unique POS registration identifier assigned in TAP.</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="cert_path" class="control-label">Digital Certificate (.pfx / .pem Path)</label>
                    <input type="text" class="form-control" name="cert_path" id="cert_path" value="<?php echo htmlspecialchars($config['cert_path'] ?? ''); ?>" placeholder="/path/to/certificate.pfx">
                    <small class="form-text text-muted">Server local file path to taxpayer PFX digital certificate.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label for="cert_pass" class="control-label">Certificate Passphrase</label>
                    <input type="password" class="form-control" name="cert_pass" id="cert_pass" value="<?php echo htmlspecialchars($config['cert_pass'] ?? ''); ?>" placeholder="••••••••">
                    <small class="form-text text-muted">Password to unlock PFX digital certificate file.</small>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-3 form-group">
                    <label for="max_retries" class="control-label">Max Auto Retries</label>
                    <input type="number" min="1" max="20" class="form-control" name="max_retries" id="max_retries" value="<?php echo (int)($config['max_retries'] ?? 5); ?>">
                    <small class="form-text text-muted">Failed transmission retry limit.</small>
                </div>
                <div class="col-md-3 form-group">
                    <label for="retry_interval_sec" class="control-label">Retry Interval (Seconds)</label>
                    <input type="number" min="10" max="86400" class="form-control" name="retry_interval_sec" id="retry_interval_sec" value="<?php echo (int)($config['retry_interval_sec'] ?? 300); ?>">
                    <small class="form-text text-muted">Delay before re-attempting failed items.</small>
                </div>
                <div class="col-md-3 form-group pt-4">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="is_sandbox" name="is_sandbox" value="1" <?php echo !empty($config['is_sandbox']) ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="is_sandbox">Sandbox / Mock Mode</label>
                    </div>
                    <small class="form-text text-muted">Simulate SDC responses without sending real FRCS tax network calls.</small>
                </div>
                <div class="col-md-3 form-group pt-4">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="auto_fiscalize" name="auto_fiscalize" value="1" <?php echo !empty($config['auto_fiscalize']) ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="auto_fiscalize">Auto Fiscalize Invoices</label>
                    </div>
                    <small class="form-text text-muted">Automatically queue invoices for fiscalization on creation.</small>
                </div>
            </div>

            <div class="card-footer text-right bg-transparent border-top">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Configuration</button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#vms-config-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=save_vms_config',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            error: function(err){
                console.error(err);
                alert_toast("An error occurred while saving configuration.", 'error');
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    alert_toast(resp.msg, 'success');
                    setTimeout(function(){
                        location.reload();
                    }, 1000);
                } else {
                    alert_toast(resp.msg || "Failed to save configuration.", 'error');
                }
            }
        });
    });
});
</script>
