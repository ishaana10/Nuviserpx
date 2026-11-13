<?php
// Company Email (per-tenant SMTP) + the tenant's email log.
use App\Services\TenantMailService;

$nv_mail = new TenantMailService($conn, $_settings);
$nv_row = $nv_mail->row() ?: [];
$nv_active = $nv_mail->config() !== null;
$nv_log = $nv_mail->recentLog(50);
$nv_has_pass = trim((string)($nv_row['smtp_pass'] ?? '')) !== '';
?>
<div class="card card-outline rounded-0 shadow-sm">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-envelope-open-text mr-2"></i>Company Email (SMTP)</h3>
        <div class="card-tools">
            <button type="button" id="btn-test-smtp" class="btn btn-sm btn-outline-primary rounded-0"><i class="fas fa-paper-plane mr-1"></i>Send Test Email</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert <?php echo $nv_active ? 'alert-success' : 'alert-info' ?> rounded-0">
            <i class="fas fa-info-circle mr-1"></i>
            <?php if($nv_active): ?>
                Customer notifications for this company are sent through <b>your own email account</b> below. If it ever fails, the system mail settings are used as fallback.
            <?php else: ?>
                This company currently sends through the <b>system mail settings</b>. Enable your own SMTP below so invoices, receipts and reminders come from your company's address.
            <?php endif; ?>
        </div>
        <form id="tenant-smtp-form">
            <div class="form-row">
                <div class="form-group col-12">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="smtp_enabled" name="smtp_enabled" value="1" <?php echo !empty($nv_row['smtp_enabled']) ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="smtp_enabled">Use this company's own email account for its notifications</label>
                    </div>
                </div>
                <div class="form-group col-md-6">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars((string)($nv_row['smtp_host'] ?? '')) ?>" placeholder="mail.yourcompany.com">
                </div>
                <div class="form-group col-md-3">
                    <label>Port</label>
                    <input type="number" name="smtp_port" class="form-control form-control-sm rounded-0" value="<?php echo (int)($nv_row['smtp_port'] ?? 587) ?>">
                </div>
                <div class="form-group col-md-3">
                    <label>Encryption</label>
                    <select name="smtp_encryption" class="form-control form-control-sm rounded-0">
                        <?php foreach(['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $nv_k => $nv_l): ?>
                            <option value="<?php echo $nv_k ?>" <?php echo ($nv_row['smtp_encryption'] ?? 'tls') === $nv_k ? 'selected' : '' ?>><?php echo $nv_l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label>SMTP Username / Email</label>
                    <input type="text" name="smtp_user" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars((string)($nv_row['smtp_user'] ?? '')) ?>" placeholder="accounts@yourcompany.com" autocomplete="off">
                </div>
                <div class="form-group col-md-6">
                    <label>SMTP Password</label>
                    <input type="password" name="smtp_pass" class="form-control form-control-sm rounded-0" value="" placeholder="<?php echo $nv_has_pass ? '********  (stored encrypted - leave blank to keep)' : 'Enter the account password' ?>" autocomplete="new-password">
                </div>
                <div class="form-group col-md-6">
                    <label>From Name</label>
                    <input type="text" name="smtp_from_name" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars((string)($nv_row['smtp_from_name'] ?? '')) ?>" placeholder="Your Company Ltd">
                </div>
                <div class="form-group col-md-6">
                    <label>From Email</label>
                    <input type="email" name="smtp_from_email" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars((string)($nv_row['smtp_from_email'] ?? '')) ?>" placeholder="accounts@yourcompany.com">
                    <small class="text-muted">Use an address on the same domain as the SMTP account, with SPF and DKIM set up, or receivers may treat the mail as spam.</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm rounded-0"><i class="fas fa-save mr-1"></i>Save Email Settings</button>
        </form>
    </div>
</div>

<div class="card card-outline rounded-0 shadow-sm">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Email Log <small class="text-muted ml-1">last <?php echo count($nv_log) ?> for this company</small></h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover table-striped mb-0">
                <thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Via</th><th>Status</th><th>Error</th></tr></thead>
                <tbody>
                <?php if(!$nv_log): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No emails have been sent for this company yet.</td></tr>
                <?php endif; ?>
                <?php foreach($nv_log as $nv_r): ?>
                    <tr>
                        <td class="text-nowrap"><small><?php echo date('d M Y H:i', strtotime($nv_r['created_at'])) ?></small></td>
                        <td><small><?php echo htmlspecialchars($nv_r['to_email']) ?></small></td>
                        <td><small><?php echo htmlspecialchars((string)$nv_r['subject']) ?></small></td>
                        <td><small><?php echo htmlspecialchars($nv_r['transport']) ?></small></td>
                        <td><?php echo $nv_r['status'] === 'sent' ? '<span class="badge badge-success">sent</span>' : '<span class="badge badge-danger">failed</span>' ?></td>
                        <td><small class="text-danger"><?php echo htmlspecialchars((string)$nv_r['error']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    $(function(){
        $('#tenant-smtp-form').submit(function(e){
            e.preventDefault();
            start_loader();
            $.ajax({
                url: _base_url_ + 'classes/Master.php?f=save_tenant_smtp',
                method: 'POST', data: $(this).serialize(), dataType: 'json',
                error: function(){ alert_toast('An error occurred.', 'error'); end_loader(); },
                success: function(resp){
                    alert_toast(resp.msg || 'Done', resp.status === 'success' ? 'success' : 'error');
                    end_loader();
                    if(resp.status === 'success'){ setTimeout(function(){ location.reload(); }, 1200); }
                }
            });
        });
        $('#btn-test-smtp').click(function(){
            start_loader();
            $.ajax({
                url: _base_url_ + 'classes/Master.php?f=test_tenant_smtp',
                method: 'POST', data: {}, dataType: 'json',
                error: function(){ alert_toast('An error occurred.', 'error'); end_loader(); },
                success: function(resp){
                    alert_toast(resp.msg || 'Done', resp.status === 'success' ? 'success' : 'error');
                    end_loader();
                    setTimeout(function(){ location.reload(); }, 1500);
                }
            });
        });
    });
</script>
