<?php
/**
 * Pay what payroll owes: pension, income tax withheld, employer levies, other deductions.
 * Each payment debits the liability account and credits the chosen bank account.
 */
require_once(__DIR__ . '/../../config.php');

use App\Services\PayrollRemittanceService;

$tenant_id = (int)$_settings->active_tenant_id();
$svc = new PayrollRemittanceService($conn, $_settings);
$fx = new \App\Services\FxService($conn, $_settings);
$balances = $svc->balances();

$banks = [];
$bq = $conn->prepare("SELECT id, code, name FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank'
                      ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), name");
$bq->bind_param('i', $tenant_id);
$bq->execute();
foreach ($bq->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
    if ($fx->bankCurrency((int)$b['id']) === null) {   // payroll is paid in the company currency
        $banks[] = $b;
    }
}

$hist = $conn->prepare("SELECT r.*, a.name bank_name FROM payroll_remittances r LEFT JOIN account_list a ON a.id = r.bank_account_id
                        WHERE r.tenant_id = ? ORDER BY r.remit_date DESC, r.id DESC LIMIT 100");
$hist->bind_param('i', $tenant_id);
$hist->execute();
$history = $hist->get_result()->fetch_all(MYSQLI_ASSOC);
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tag = $_settings->currency_tag();
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-hand-holding-usd text-primary mr-2"></i> Payroll Remittances</h3></div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            Payroll records what is owed to the pension fund, the tax authority and others. Record each payment here: it reduces the
            amount owed and the chosen bank account. You cannot pay more than is owed.
        </div>

        <div class="row mb-3">
        <?php foreach ($balances as $k => $b): ?>
            <div class="col-md-3">
                <div class="info-box bg-light border">
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold"><?= $e($b['label']) ?></span>
                        <span class="info-box-number h4 mb-1"><?= number_format($b['owed'], 2) ?><small class="text-muted"><?= $tag ?></small></span>
                        <?php if ($b['owed'] > 0): ?>
                        <button type="button" class="btn btn-xs btn-primary btn-flat pay-btn" data-k="<?= $e($k) ?>" data-owed="<?= $e(number_format($b['owed'], 2, '.', '')) ?>" data-label="<?= $e($b['label']) ?>">Record payment</button>
                        <?php else: ?><small class="text-muted">Nothing owed</small><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <div id="pay-box" class="card border" style="display:none; max-width:640px">
            <div class="card-header py-2"><b id="pay-title"></b></div>
            <div class="card-body">
                <form id="pay-form">
                    <input type="hidden" name="liability" id="pf-k">
                    <div class="row">
                        <div class="col-md-6 form-group"><label>Amount<?= $tag ?></label><input type="number" step="0.01" min="0.01" name="amount" id="pf-amount" class="form-control" required></div>
                        <div class="col-md-6 form-group"><label>Payment date</label><input type="date" name="remit_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    </div>
                    <div class="form-group"><label>Paid from</label>
                        <select name="bank_account_id" class="form-control" required>
                            <option value="">Select bank account</option>
                            <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= $e(($b['code'] ? $b['code'] . ' - ' : '') . $b['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="row">
                        <div class="col-md-6 form-group"><label>Reference</label><input type="text" name="reference" maxlength="100" class="form-control" placeholder="Receipt / return number"></div>
                        <div class="col-md-6 form-group"><label>Note</label><input type="text" name="note" maxlength="500" class="form-control"></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-flat font-weight-bold">Post payment</button>
                    <button type="button" class="btn btn-default btn-flat" id="pay-cancel">Cancel</button>
                </form>
            </div>
        </div>

        <h5 class="font-weight-bold border-bottom pb-2 mt-3">Payments</h5>
        <table class="table table-sm table-bordered">
            <thead><tr class="bg-light"><th>Date</th><th>For</th><th class="text-right">Amount</th><th>Paid from</th><th>Reference</th><th>Status</th><th style="width:90px"></th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= $e($h['remit_date']) ?></td>
                    <td><?= $e(PayrollRemittanceService::LIABILITIES[$h['liability']][0] ?? $h['liability']) ?></td>
                    <td class="text-right"><?= number_format((float)$h['amount'], 2) ?></td>
                    <td><?= $e($h['bank_name']) ?></td>
                    <td><?= $e($h['reference']) ?></td>
                    <td><?php
                        $badge = ['posted' => ['success', 'Posted'], 'pending_approval' => ['warning', 'Waiting for approval'], 'rejected' => ['danger', 'Returned'], 'reversed' => ['secondary', 'Reversed']][$h['status']] ?? ['secondary', $h['status']];
                        echo '<span class="badge badge-' . $badge[0] . '">' . $e($badge[1]) . '</span>';
                        if ($h['status'] === 'rejected' && $h['approval_note']) echo ' <small class="text-muted">' . $e($h['approval_note']) . '</small>';
                    ?></td>
                    <td><?php if ($h['status'] === 'posted' && $_settings->can_access('payroll_remit')): ?><button type="button" class="btn btn-xs btn-outline-danger rev-btn" data-id="<?= (int)$h['id'] ?>">Reverse</button><?php endif; ?></td>
                </tr>
            <?php endforeach; if (!$history): ?>
                <tr><td colspan="7" class="text-center text-muted">No payments recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function(){
    function call(f, data, done){
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=' + f, data, function(r){
            end_loader();
            if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 800); }
            else { alert_toast((r && r.msg) || 'Failed', 'error'); }
        }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
    }
    $('.pay-btn').on('click', function(){
        $('#pf-k').val($(this).data('k'));
        $('#pf-amount').val($(this).data('owed')).attr('max', $(this).data('owed'));
        $('#pay-title').text('Record payment: ' + $(this).data('label'));
        $('#pay-box').show()[0].scrollIntoView({behavior: 'smooth'});
    });
    $('#pay-cancel').on('click', function(){ $('#pay-box').hide(); });
    $('#pay-form').on('submit', function(e){ e.preventDefault(); call('remit_payroll_liability', $(this).serialize()); });
    $('.rev-btn').on('click', function(){
        if (confirm('Reverse this payment? The amount will be owed again.')) call('reverse_payroll_remittance', {id: $(this).data('id')});
    });
})();
</script>
