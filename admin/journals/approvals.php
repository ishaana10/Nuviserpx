<?php
/**
 * Journal approvals: the queue of journals waiting for sign-off, and the company's approval policy.
 */
$svc = new \App\Services\ApprovalService($conn, $_settings);
$policy = $svc->policy();
$queue = $svc->pending();
$me = (int)$_settings->userdata('id');
$can_approve = $_settings->can_access('journals_approve');
$can_configure = $_settings->can_access('settings_manage');
?>
<div class="container-fluid py-3">
    <h3 class="font-weight-bold text-navy mb-1">Journal approvals</h3>
    <p class="text-muted text-sm mb-3">
        When approval is on, a manual journal at or above the threshold is held here instead of posting. It does not appear in any report until an approver
        signs it off, and that approver cannot be the person who prepared it.
    </p>

    <div class="row">
        <div class="col-lg-8 mb-3">
            <div class="card shadow-sm">
                <div class="card-header font-weight-bold">Waiting for approval <span class="badge badge-warning ml-1"><?= count($queue) ?></span></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Date</th><th>Journal</th><th>Narration</th><th class="text-right">Amount</th><th>Prepared by</th><th class="text-center" width="22%">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($queue as $q): $mine = (int)$q['user_id'] === $me; ?>
                            <tr>
                                <td style="white-space:nowrap"><?= date('j M Y', strtotime($q['journal_date'])) ?></td>
                                <td><a href="javascript:void(0)" class="view_data font-weight-bold" data-id="<?= (int)$q['id'] ?>" data-code="<?= htmlspecialchars($q['code']) ?>"><?= htmlspecialchars($q['code']) ?></a></td>
                                <td><?= htmlspecialchars(mb_strimwidth((string)$q['description'], 0, 80, '…')) ?></td>
                                <td class="text-right"><?= number_format((float)$q['total'], 2) ?></td>
                                <td><?= htmlspecialchars(trim((string)$q['author']) ?: '—') ?><?= $mine ? ' <span class="badge badge-light">you</span>' : '' ?></td>
                                <td class="text-center" style="white-space:nowrap">
                                    <?php if ($can_approve): ?>
                                        <?php if ($mine && !$policy['allow_self']): ?>
                                            <span class="text-muted text-xs">Needs another approver</span>
                                        <?php else: ?>
                                            <button class="btn btn-xs btn-success approve-j" data-id="<?= (int)$q['id'] ?>">Approve</button>
                                        <?php endif; ?>
                                        <button class="btn btn-xs btn-outline-secondary return-j" data-id="<?= (int)$q['id'] ?>">Return</button>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">Awaiting an approver</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$queue): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing is waiting for approval.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-header font-weight-bold">Approval policy</div>
                <form id="form_policy" class="card-body">
                    <fieldset <?= $can_configure ? '' : 'disabled' ?>>
                        <div class="custom-control custom-switch mb-3">
                            <input type="checkbox" class="custom-control-input" id="pol_enabled" name="enabled" value="1" <?= $policy['enabled'] ? 'checked' : '' ?>>
                            <label class="custom-control-label font-weight-bold" for="pol_enabled">Require approval for manual journals</label>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Approve journals of</label>
                            <input type="number" name="threshold" class="form-control text-right" step="0.01" min="0" value="<?= number_format($policy['threshold'], 2, '.', '') ?>">
                            <small class="text-muted">or more. 0 means every manual journal.</small>
                        </div>
                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="pol_self" name="allow_self" value="1" <?= $policy['allow_self'] ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="pol_self">Let people approve their own journals</label>
                            <small class="d-block text-muted">For one-person companies. Defeats the separation of duties.</small>
                        </div>
                        <?php if ($can_configure): ?><button class="btn btn-primary btn-sm font-weight-bold" type="submit">Save policy</button><?php endif; ?>
                    </fieldset>
                    <?php if (!$can_configure): ?><div class="text-muted text-xs mt-2">Only people who can manage company settings can change this.</div><?php endif; ?>
                </form>
            </div>
            <p class="text-xs text-muted mt-2">Journals from invoices, bills, payments and payroll post as usual. Approving needs the “Approve manual journals” permission on the role.</p>
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
    $('.view_data').on('click', function () { uni_modal('Journal ' + $(this).data('code'), 'journals/view_details.php?id=' + $(this).data('id'), 'modal-lg'); });
    $('.approve-j').on('click', function () { post('approve_journal', { id: $(this).data('id') }); });
    $('.return-j').on('click', function () {
        var note = window.prompt('Why is this journal being returned?');
        if (note && note.trim()) { post('reject_journal', { id: $(this).data('id'), note: note }); }
    });
    $('#form_policy').on('submit', function (e) { e.preventDefault(); post('save_approval_policy', $(this).serialize()); });
});
</script>
