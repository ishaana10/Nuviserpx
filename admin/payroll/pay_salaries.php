<?php
/**
 * Pay approved salaries. Payroll leaves net pay in Net Salaries Payable; paying a run moves it to the bank.
 */
require_once(__DIR__ . '/../../config.php');

use App\Services\PayrollPaymentService;

$tenant_id = (int)$_settings->active_tenant_id();
$svc = new PayrollPaymentService($conn, $_settings);
$fx = new \App\Services\FxService($conn, $_settings);
$runs = $svc->unpaidRuns();

$banks = [];
$bq = $conn->prepare("SELECT id, code, name FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank'
                      ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), name");
$bq->bind_param('i', $tenant_id);
$bq->execute();
foreach ($bq->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
    if ($fx->bankCurrency((int)$b['id']) === null) {
        $banks[] = $b;
    }
}
$hist = $conn->prepare("SELECT p.*, a.name bank_name FROM payroll_payments p LEFT JOIN account_list a ON a.id = p.bank_account_id
                        WHERE p.tenant_id = ? ORDER BY p.pay_date DESC, p.id DESC LIMIT 100");
$hist->bind_param('i', $tenant_id);
$hist->execute();
$history = $hist->get_result()->fetch_all(MYSQLI_ASSOC);
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tag = $_settings->currency_tag();
?>
<div class="card card-outline card-success shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-money-check-alt text-success mr-2"></i> Pay Salaries</h3></div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            Processed and approved payroll is owed to employees but not yet paid. Choose the runs to pay and the bank account they come
            from. Paid payroll cannot be edited or deleted unless the payment is reversed.
        </div>
        <form id="paysal-form">
            <table class="table table-sm table-bordered">
                <thead><tr class="bg-light"><th style="width:30px"><input type="checkbox" id="chk-all"></th><th>Run</th><th>Month</th><th>Pay date</th><th class="text-right">Employees</th><th class="text-right">Net pay<?= $tag ?></th></tr></thead>
                <tbody>
                <?php foreach ($runs as $r): ?>
                    <tr>
                        <td><input type="checkbox" class="chk" name="refs[]" value="<?= $e($r['payroll_run_ref']) ?>" data-net="<?= $e(number_format((float)$r['net'], 2, '.', '')) ?>"></td>
                        <td><?= $e($r['payroll_run_ref']) ?></td><td><?= $e($r['salary_month']) ?></td><td><?= $e($r['pay_date']) ?></td>
                        <td class="text-right"><?= (int)$r['employees'] ?></td><td class="text-right"><?= number_format((float)$r['net'], 2) ?></td>
                    </tr>
                <?php endforeach; if (!$runs): ?>
                    <tr><td colspan="6" class="text-center text-muted">No approved payroll is waiting to be paid.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($runs): ?>
            <div class="row" style="max-width:760px">
                <div class="col-md-4 form-group"><label>Payment date</label><input type="date" name="pay_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-5 form-group"><label>Paid from</label>
                    <select name="bank_account_id" class="form-control" required>
                        <option value="">Select bank account</option>
                        <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= $e(($b['code'] ? $b['code'] . ' - ' : '') . $b['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3 form-group"><label>Reference</label><input type="text" name="reference" maxlength="100" class="form-control" placeholder="Bank batch no."></div>
            </div>
            <button type="submit" class="btn btn-success btn-flat font-weight-bold">Pay selected <span id="sel-total"></span></button>
            <?php endif; ?>
        </form>

        <h5 class="font-weight-bold border-bottom pb-2 mt-4">Salary payments</h5>
        <table class="table table-sm table-bordered">
            <thead><tr class="bg-light"><th>Date</th><th class="text-right">Employees</th><th class="text-right">Amount</th><th>Paid from</th><th>Reference</th><th>Status</th><th style="width:90px"></th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= $e($h['pay_date']) ?></td><td class="text-right"><?= (int)$h['employees'] ?></td>
                    <td class="text-right"><?= number_format((float)$h['amount'], 2) ?></td><td><?= $e($h['bank_name']) ?></td><td><?= $e($h['reference']) ?></td>
                    <td><span class="badge badge-<?= $h['status'] === 'posted' ? 'success' : 'secondary' ?>"><?= $h['status'] === 'posted' ? 'Paid' : 'Reversed' ?></span></td>
                    <td><?php if ($h['status'] === 'posted'): ?><button type="button" class="btn btn-xs btn-outline-danger rev-btn" data-id="<?= (int)$h['id'] ?>">Reverse</button><?php endif; ?></td>
                </tr>
            <?php endforeach; if (!$history): ?>
                <tr><td colspan="7" class="text-center text-muted">No salary payments recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function(){
    function call(f, data){
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=' + f, data, function(r){
            end_loader();
            if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 800); }
            else { alert_toast((r && r.msg) || 'Failed', 'error'); }
        }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
    }
    function total(){ var t = 0; $('.chk:checked').each(function(){ t += parseFloat($(this).data('net')) || 0; }); $('#sel-total').text(t ? '(' + t.toFixed(2) + ')' : ''); }
    $('#chk-all').on('change', function(){ $('.chk').prop('checked', this.checked); total(); });
    $('.chk').on('change', total);
    $('#paysal-form').on('submit', function(e){
        e.preventDefault();
        if (!$('.chk:checked').length) { alert_toast('Choose at least one run.', 'warning'); return; }
        if (confirm('Pay the selected salaries from the chosen bank account?')) call('pay_payroll_salaries', $(this).serialize());
    });
    $('.rev-btn').on('click', function(){
        if (confirm('Reverse this salary payment? The salaries will be owed again.')) call('reverse_payroll_payment', {id: $(this).data('id')});
    });
})();
</script>
