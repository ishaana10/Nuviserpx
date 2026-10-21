<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-shield-alt text-primary mr-2"></i> System Security & Password Policy Settings</h3>
        <div class="card-tools">
            <button type="submit" form="security-settings-form" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save Security Policy</button>
        </div>
    </div>
    <div class="card-body">
        <form action="" id="security-settings-form">
            <div id="msg"></div>

            <!-- 1. Authentication & 2FA Enforcement -->
            <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-key text-secondary mr-2"></i> Authentication & Multi-Factor Security</h5>
            <div class="row mb-4">
                <div class="col-md-6 form-group">
                    <label for="sec_2fa_enforcement" class="font-weight-bold text-dark text-sm">Two-Factor Authentication (2FA) Requirement</label>
                    <select name="sec_2fa_enforcement" id="sec_2fa_enforcement" class="form-control form-control-sm rounded-0">
                        <option value="disabled" <?= $_settings->info('sec_2fa_enforcement') == 'disabled' || !$_settings->info('sec_2fa_enforcement') ? 'selected' : '' ?>>Disabled</option>
                        <option value="optional" <?= $_settings->info('sec_2fa_enforcement') == 'optional' ? 'selected' : '' ?>>Optional (User Configurable)</option>
                        <option value="admins" <?= $_settings->info('sec_2fa_enforcement') == 'admins' ? 'selected' : '' ?>>Mandatory for Administrators & Super Users</option>
                        <option value="all" <?= $_settings->info('sec_2fa_enforcement') == 'all' ? 'selected' : '' ?>>Mandatory for All Staff & System Users</option>
                    </select>
                </div>
                <div class="col-md-6 form-group">
                    <label for="sec_session_timeout" class="font-weight-bold text-dark text-sm">Session Inactivity Timeout Limit</label>
                    <select name="sec_session_timeout" id="sec_session_timeout" class="form-control form-control-sm rounded-0">
                        <option value="15" <?= $_settings->info('sec_session_timeout') == '15' ? 'selected' : '' ?>>15 Minutes Inactivity</option>
                        <option value="30" <?= $_settings->info('sec_session_timeout') == '30' || !$_settings->info('sec_session_timeout') ? 'selected' : '' ?>>30 Minutes Inactivity (Recommended)</option>
                        <option value="60" <?= $_settings->info('sec_session_timeout') == '60' ? 'selected' : '' ?>>60 Minutes Inactivity</option>
                        <option value="120" <?= $_settings->info('sec_session_timeout') == '120' ? 'selected' : '' ?>>2 Hours Inactivity</option>
                        <option value="0" <?= $_settings->info('sec_session_timeout') == '0' ? 'selected' : '' ?>>Never (Disabled)</option>
                    </select>
                </div>
            </div>

            <!-- 2. Password Complexity Rules -->
            <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-lock text-secondary mr-2"></i> Password Complexity Rules</h5>
            <div class="row mb-4">
                <div class="col-md-3 form-group">
                    <label for="sec_pwd_min_len" class="font-weight-bold text-dark text-sm">Minimum Length</label>
                    <select name="sec_pwd_min_len" id="sec_pwd_min_len" class="form-control form-control-sm rounded-0">
                        <option value="8" <?= $_settings->info('sec_pwd_min_len') == '8' || !$_settings->info('sec_pwd_min_len') ? 'selected' : '' ?>>8 Characters</option>
                        <option value="10" <?= $_settings->info('sec_pwd_min_len') == '10' ? 'selected' : '' ?>>10 Characters</option>
                        <option value="12" <?= $_settings->info('sec_pwd_min_len') == '12' ? 'selected' : '' ?>>12 Characters (Strong)</option>
                    </select>
                </div>
                <div class="col-md-9 form-group pt-4">
                    <div class="d-flex align-items-center gap-4">
                        <div class="custom-control custom-checkbox mr-3">
                            <input type="checkbox" class="custom-control-input" name="sec_pwd_uppercase" id="sec_pwd_uppercase" value="1" <?= $_settings->info('sec_pwd_uppercase') !== '0' ? 'checked' : '' ?>>
                            <label class="custom-control-label font-weight-bold text-dark text-sm" for="sec_pwd_uppercase">Require Uppercase (A-Z)</label>
                        </div>
                        <div class="custom-control custom-checkbox mr-3">
                            <input type="checkbox" class="custom-control-input" name="sec_pwd_numbers" id="sec_pwd_numbers" value="1" <?= $_settings->info('sec_pwd_numbers') !== '0' ? 'checked' : '' ?>>
                            <label class="custom-control-label font-weight-bold text-dark text-sm" for="sec_pwd_numbers">Require Digits (0-9)</label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" name="sec_pwd_symbols" id="sec_pwd_symbols" value="1" <?= $_settings->info('sec_pwd_symbols') == '1' ? 'checked' : '' ?>>
                            <label class="custom-control-label font-weight-bold text-dark text-sm" for="sec_pwd_symbols">Require Symbols (!@#$%^&*)</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Brute-force Lockout & IP Whitelist -->
            <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-user-shield text-secondary mr-2"></i> Brute-Force Protection & IP Access Control</h5>
            <div class="row mb-3">
                <div class="col-md-4 form-group">
                    <label for="sec_max_failed_attempts" class="font-weight-bold text-dark text-sm">Max Failed Login Attempts</label>
                    <select name="sec_max_failed_attempts" id="sec_max_failed_attempts" class="form-control form-control-sm rounded-0">
                        <option value="3" <?= $_settings->info('sec_max_failed_attempts') == '3' ? 'selected' : '' ?>>3 Attempts</option>
                        <option value="5" <?= $_settings->info('sec_max_failed_attempts') == '5' || !$_settings->info('sec_max_failed_attempts') ? 'selected' : '' ?>>5 Attempts (Standard)</option>
                        <option value="10" <?= $_settings->info('sec_max_failed_attempts') == '10' ? 'selected' : '' ?>>10 Attempts</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label for="sec_lockout_duration_mins" class="font-weight-bold text-dark text-sm">Lockout Duration (Minutes)</label>
                    <input type="number" name="sec_lockout_duration_mins" id="sec_lockout_duration_mins" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('sec_lockout_duration_mins') ?: '15') ?>" placeholder="15">
                </div>
                <div class="col-md-4 form-group">
                    <label for="sec_allowed_ip_list" class="font-weight-bold text-dark text-sm">Admin Allowed IP List <small class="text-muted">(Optional)</small></label>
                    <input type="text" name="sec_allowed_ip_list" id="sec_allowed_ip_list" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('sec_allowed_ip_list')) ?>" placeholder="e.g. 192.168.1.1, 10.0.0.1 (Comma separated)">
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#security-settings-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/SystemSettings.php?f=update_settings",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred saving security settings.", "error");
                end_loader();
            },
            success: function(resp){
                alert_toast("Security policy successfully updated.", "success");
                end_loader();
            }
        });
    });
});
</script>
