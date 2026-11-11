<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\PaymentReminderService;

$svc = new PaymentReminderService($conn, $_settings);
$cfg = $svc->getSettings();
$log = $svc->recent(50);
$tag = $_settings->currency_tag();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="row">
    <div class="col-lg-5">
        <div class="card card-outline card-primary shadow-sm rounded-0">
            <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-bell text-primary mr-2"></i> Payment Reminders &amp; Late Fees</h3></div>
            <form id="reminder-frm">
                <div class="card-body">
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox" class="custom-control-input" id="enabled" name="enabled" value="1" <?= (int)$cfg['enabled'] ? 'checked' : '' ?>>
                        <label class="custom-control-label font-weight-bold" for="enabled">Email customers about overdue invoices</label>
                    </div>
                    <div class="form-group">
                        <label class="text-sm">Remind after this many days overdue</label>
                        <input type="text" name="stages" class="form-control form-control-sm" value="<?= $h($cfg['stages']) ?>" placeholder="3, 10, 20">
                        <small class="text-muted">Up to <?= PaymentReminderService::MAX_STAGES ?> days, separated by commas. Each is sent once per invoice.</small>
                    </div>
                    <div class="form-group">
                        <label class="text-sm">Extra note in the email (optional)</label>
                        <textarea name="note" rows="2" maxlength="500" class="form-control form-control-sm"><?= $h($cfg['note']) ?></textarea>
                    </div>
                    <hr>
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox" class="custom-control-input" id="fee_enabled" name="fee_enabled" value="1" <?= (int)$cfg['fee_enabled'] ? 'checked' : '' ?>>
                        <label class="custom-control-label font-weight-bold" for="fee_enabled">Charge a late fee</label>
                    </div>
                    <div class="row">
                        <div class="col-6 form-group">
                            <label class="text-sm">Fee type</label>
                            <select name="fee_type" class="form-control form-control-sm">
                                <option value="percent" <?= $cfg['fee_type'] === 'percent' ? 'selected' : '' ?>>Percent of balance</option>
                                <option value="fixed" <?= $cfg['fee_type'] === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                            </select>
                        </div>
                        <div class="col-6 form-group">
                            <label class="text-sm">Fee value</label>
                            <input type="number" step="0.01" min="0" name="fee_value" class="form-control form-control-sm text-right" value="<?= $h($cfg['fee_value']) ?>">
                        </div>
                        <div class="col-6 form-group">
                            <label class="text-sm">Charge after (days overdue)</label>
                            <input type="number" min="1" max="365" name="fee_grace_days" class="form-control form-control-sm" value="<?= (int)$cfg['fee_grace_days'] ?>">
                        </div>
                        <div class="col-6 form-group">
                            <label class="text-sm">Cap<?= $h($tag) ?> (0 = none)</label>
                            <input type="number" step="0.01" min="0" name="fee_cap" class="form-control form-control-sm text-right" value="<?= $h($cfg['fee_cap']) ?>">
                        </div>
                    </div>
                    <small class="text-muted">The fee is raised once per invoice as its own invoice to the customer. Fee invoices never attract a further fee or reminder.</small>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" id="run-now"><i class="fas fa-paper-plane mr-1"></i> Run now</button>
                    <button type="submit" class="btn btn-sm btn-primary btn-flat">Save</button>
                </div>
            </form>
        </div>
        <p class="text-xs text-muted">Reminders go out automatically once a day when <code>cron/payment_reminders.php</code> is scheduled on the server. Run now does the same check straight away.</p>
    </div>
    <div class="col-lg-7">
        <div class="card card-outline card-secondary shadow-sm rounded-0">
            <div class="card-header"><h3 class="card-title font-weight-bold">Recent reminders</h3></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>Sent</th><th>Invoice</th><th>Customer</th><th class="text-center">Stage</th><th class="text-right">Balance<?= $h($tag) ?></th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $r): ?>
                        <tr>
                            <td><?= $h(date('d M Y', strtotime($r['date_created']))) ?></td>
                            <td><?= $h($r['invoice_no']) ?></td>
                            <td><?= $h($r['party_name']) ?><?= $r['sent_to'] ? '<br><small class="text-muted">' . $h($r['sent_to']) . '</small>' : '' ?></td>
                            <td class="text-center"><?= (int)$r['stage_days'] ?>d</td>
                            <td class="text-right"><?= number_format((float)$r['balance'], 2) ?></td>
                            <td><span class="badge badge-<?= $r['status'] === 'sent' ? 'success' : 'secondary' ?>"><?= $h($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$log): ?><tr><td colspan="6" class="text-center text-muted py-4">No reminders yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
$(function(){
    function call(f, data, reload){
        start_loader();
        $.post(_base_url_ + "classes/Master.php?f=" + f, data, function(resp){
            alert_toast(resp.msg, resp.status == 'success' ? 'success' : 'error');
            end_loader();
            if (resp.status == 'success' && reload) setTimeout(function(){ location.reload(); }, 900);
        }, 'json').fail(function(){ alert_toast('An error occurred.', 'error'); end_loader(); });
    }
    $('#reminder-frm').on('submit', function(e){ e.preventDefault(); call('save_reminder_settings', $(this).serialize(), false); });
    $('#run-now').on('click', function(){
        if (!confirm('Send any reminders and raise any late fees that are due now? Save your settings first.')) return;
        call('run_payment_reminders', {}, true);
    });
});
</script>
