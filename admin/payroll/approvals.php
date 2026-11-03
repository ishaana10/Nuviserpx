<?php
/**
 * Payroll approvals: payroll runs and payroll payments waiting for sign-off, and the policy for each.
 */
$svc = new \App\Services\PayrollApprovalService($conn, $_settings);
$apv = new \App\Services\ApprovalService($conn, $_settings);
$runPolicy = $apv->policy(\App\Services\ApprovalService::DOC_PAYROLL_RUN);
$remPolicy = $apv->policy(\App\Services\ApprovalService::DOC_PAYROLL_REMITTANCE);
$runs = $svc->pendingRuns();
$rems = $svc->pendingRemittances();
$me = (int)$_settings->userdata('id');
$can_approve = $_settings->can_access('payroll_approve');
$can_configure = $_settings->can_access('settings_manage');
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tag = $_settings->currency_tag();
$labels = \App\Services\PayrollRemittanceService::LIABILITIES;
?>
<div class="container-fluid py-3">
    <h3 class="font-weight-bold text-navy mb-1">Payroll approvals</h3>
    <p class="text-muted text-sm mb-3">
        When approval is on, a payroll run or a payroll payment at or above the threshold is held here. Until someone else approves it,
        it stays out of every report, export and schedule and does not reach the ledger.
    </p>

    <div class="row">
        <div class="col-lg-8 mb-3">
            <div class="card shadow-sm">
                <div class="card-header font-weight-bold">Payroll runs waiting for approval <span class="badge badge-warning ml-1"><?= count($runs) ?></span></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Run</th><th>Period</th><th class="text-center">Staff</th><th class="text-right">Gross<?= $tag ?></th><th class="text-right">Net pay<?= $tag ?></th><th>Prepared by</th><th class="text-center" width="22%">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($runs as $r): $mine = (int)$r['created_by'] === $me; ?>
                            <tr>
                                <td class="font-weight-bold"><?= $e($r['payroll_run_ref']) ?></td>
                                <td><?= $e($r['salary_month']) ?></td>
                                <td class="text-center"><?= (int)$r['employees'] ?></td>
                                <td class="text-right"><?= number_format((float)$r['gross'], 2) ?></td>
                                <td class="text-right"><?= number_format((float)$r['net'], 2) ?></td>
                                <td><?= $e(trim((string)$r['author']) ?: '—') ?><?= $mine ? ' <span class="badge badge-light">you</span>' : '' ?></td>
                                <td class="text-center" style="white-space:nowrap">
                                    <?php if ($can_approve): ?>
                                        <?php if ($mine && !$runPolicy['allow_self']): ?><span class="text-muted text-xs">Needs another approver</span>
                                        <?php else: ?><button class="btn btn-xs btn-success approve-run" data-ref="<?= $e($r['payroll_run_ref']) ?>">Approve</button><?php endif; ?>
                                        <button class="btn btn-xs btn-outline-secondary return-run" data-ref="<?= $e($r['payroll_run_ref']) ?>">Return</button>
                                    <?php else: ?><span class="text-muted text-xs">Awaiting an approver</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; if (!$runs): ?><tr><td colspan="7" class="text-center text-muted py-4">No payroll runs are waiting for approval.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-header font-weight-bold">Payroll run policy</div>
                <form id="form_run_policy" class="card-body">
                    <input type="hidden" name="doc_type" value="payroll_run">
                    <fieldset <?= $can_configure ? '' : 'disabled' ?>>
                        <div class="custom-control custom-switch mb-3">
                            <input type="checkbox" class="custom-control-input" id="rpol_enabled" name="enabled" value="1" <?= $runPolicy['enabled'] ? 'checked' : '' ?>>
                            <label class="custom-control-label font-weight-bold" for="rpol_enabled">Require approval for payroll runs</label>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Approve runs with net pay of</label>
                            <input type="number" name="threshold" class="form-control text-right" step="0.01" min="0" value="<?= number_format($runPolicy['threshold'], 2, '.', '') ?>">
                            <small class="text-muted">or more. 0 means every run.</small>
                        </div>
                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="rpol_self" name="allow_self" value="1" <?= $runPolicy['allow_self'] ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="rpol_self">Let people approve their own runs</label>
                        </div>
                        <?php if ($can_configure): ?><button class="btn btn-primary btn-sm font-weight-bold" type="submit">Save policy</button><?php endif; ?>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-3">
            <div class="card shadow-sm">
                <div class="card-header font-weight-bold">Payroll payments waiting for approval <span class="badge badge-warning ml-1"><?= count($rems) ?></span></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Date</th><th>For</th><th class="text-right">Amount<?= $tag ?></th><th>Reference</th><th>Entered by</th><th class="text-center" width="22%">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($rems as $r): $mine = (int)$r['created_by'] === $me; ?>
                            <tr>
                                <td style="white-space:nowrap"><?= date('j M Y', strtotime($r['remit_date'])) ?></td>
                                <td><?= $e($labels[$r['liability']][0] ?? $r['liability']) ?></td>
                                <td class="text-right"><?= number_format((float)$r['amount'], 2) ?></td>
                                <td><?= $e($r['reference']) ?></td>
                                <td><?= $e(trim((string)$r['author']) ?: '—') ?><?= $mine ? ' <span class="badge badge-light">you</span>' : '' ?></td>
                                <td class="text-center" style="white-space:nowrap">
                                    <?php if ($can_approve): ?>
                                        <?php if ($mine && !$remPolicy['allow_self']): ?><span class="text-muted text-xs">Needs another approver</span>
                                        <?php else: ?><button class="btn btn-xs btn-success approve-rem" data-id="<?= (int)$r['id'] ?>">Approve</button><?php endif; ?>
                                        <button class="btn btn-xs btn-outline-secondary return-rem" data-id="<?= (int)$r['id'] ?>">Return</button>
                                    <?php else: ?><span class="text-muted text-xs">Awaiting an approver</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; if (!$rems): ?><tr><td colspan="6" class="text-center text-muted py-4">No payroll payments are waiting for approval.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-header font-weight-bold">Payroll payment policy</div>
                <form id="form_rem_policy" class="card-body">
                    <input type="hidden" name="doc_type" value="payroll_remittance">
                    <fieldset <?= $can_configure ? '' : 'disabled' ?>>
                        <div class="custom-control custom-switch mb-3">
                            <input type="checkbox" class="custom-control-input" id="mpol_enabled" name="enabled" value="1" <?= $remPolicy['enabled'] ? 'checked' : '' ?>>
                            <label class="custom-control-label font-weight-bold" for="mpol_enabled">Require approval for payroll payments</label>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Approve payments of</label>
                            <input type="number" name="threshold" class="form-control text-right" step="0.01" min="0" value="<?= number_format($remPolicy['threshold'], 2, '.', '') ?>">
                            <small class="text-muted">or more. 0 means every payment.</small>
                        </div>
                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="mpol_self" name="allow_self" value="1" <?= $remPolicy['allow_self'] ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="mpol_self">Let people approve their own payments</label>
                        </div>
                        <?php if ($can_configure): ?><button class="btn btn-primary btn-sm font-weight-bold" type="submit">Save policy</button><?php endif; ?>
                    </fieldset>
                </form>
            </div>
            <p class="text-xs text-muted mt-2">Needs the “Approve payroll” permission on the role.</p>
        </div>
    </div>
</div>
<script>
$(function () {
    var post = function (f, data) {
        start_loader();
        $.ajax({ url: _base_url_ + 'classes/Master.php?f=' + f, method: 'POST', data: data, dataType: 'json',
            error: function () { alert_toast('An error occurred.', 'error'); end_loader(); },
            success: function (r) {
                if (r && r.status === 'success') { location.reload(); }
                else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
            } });
    };
    $('.approve-run').on('click', function () { post('approve_payroll_run', { ref: $(this).data('ref') }); });
    $('.return-run').on('click', function () {
        var note = window.prompt('Why is this payroll run being returned?');
        if (note && note.trim()) { post('reject_payroll_run', { ref: $(this).data('ref'), note: note }); }
    });
    $('.approve-rem').on('click', function () { post('approve_payroll_remittance', { id: $(this).data('id') }); });
    $('.return-rem').on('click', function () {
        var note = window.prompt('Why is this payment being returned?');
        if (note && note.trim()) { post('reject_payroll_remittance', { id: $(this).data('id'), note: note }); }
    });
    $('#form_run_policy, #form_rem_policy').on('submit', function (e) { e.preventDefault(); post('save_approval_policy', $(this).serialize()); });
});
</script>
