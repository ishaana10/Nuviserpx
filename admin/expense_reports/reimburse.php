<?php
require_once(__DIR__ . '/../../config.php');

$t = (int)$_settings->active_tenant_id();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
if (!$ids) { echo '<div class="alert alert-warning">Select claims first.</div>'; return; }
$marks = implode(',', array_fill(0, count($ids), '?'));
$q = $conn->prepare("SELECT er.id, er.claim_no, er.amount, er.claim_date, e.firstname, e.lastname FROM expense_reports er LEFT JOIN employee_list e ON e.id = er.employee_id
    WHERE er.tenant_id = ? AND er.status = 'approved' AND er.id IN ({$marks}) ORDER BY e.firstname, er.id");
$q->bind_param('i' . str_repeat('i', count($ids)), $t, ...$ids);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
if (!$rows) { echo '<div class="alert alert-warning">None of those claims is waiting for payment.</div>'; return; }
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
$latest = max(array_column($rows, 'claim_date'));
$fx = new \App\Services\FxService($conn, $_settings);
$banks = [];
$bq = $conn->prepare("SELECT id, code, name FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND type = 'Bank' ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), name");
$bq->bind_param('i', $t);
$bq->execute();
foreach ($bq->get_result()->fetch_all(MYSQLI_ASSOC) as $b) { if ($fx->bankCurrency((int)$b['id']) === null) { $banks[] = $b; } }
?>
<form id="reimburse-frm">
    <?php foreach ($rows as $r): ?><input type="hidden" name="ids[]" value="<?= (int)$r['id'] ?>"><?php endforeach; ?>
    <table class="table table-sm">
        <?php foreach ($rows as $r): ?>
        <tr><td><?= $h($r['claim_no']) ?></td><td><?= $h($r['firstname'] . ' ' . $r['lastname']) ?></td><td class="text-right"><?= number_format((float)$r['amount'], 2) ?></td></tr>
        <?php endforeach; ?>
        <tr class="font-weight-bold"><td colspan="2">Total</td><td class="text-right"><?= number_format($total, 2) ?></td></tr>
    </table>
    <div class="row">
        <div class="col-md-6 form-group"><label>Pay from</label>
            <select name="bank_account_id" class="form-control form-control-sm" required>
                <option value="">Select bank account...</option>
                <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= $h(trim(($b['code'] ? $b['code'] . ' - ' : '') . $b['name'])) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-6 form-group"><label>Payment date</label><input type="date" name="pay_date" class="form-control form-control-sm" min="<?= $h($latest) ?>" max="<?= $h(date('Y-m-d')) ?>" value="<?= $h(date('Y-m-d')) ?>" required></div>
    </div>
    <div class="form-group"><label>Reference (optional)</label><input type="text" name="reference" maxlength="100" class="form-control form-control-sm" placeholder="e.g. bank transfer number"></div>
</form>
<script>
$(function(){
    $('#uni_modal').find('.modal-footer').find('#submit').off('click').on('click', function(){ $('#reimburse-frm').submit(); });
    $('#reimburse-frm').off('submit').on('submit', function(e){
        e.preventDefault(); start_loader();
        $.post(_base_url_ + "classes/Master.php?f=reimburse_expense_claims", $(this).serialize(), function(resp){
            if (resp.status == 'success') { alert_toast(resp.msg, 'success'); setTimeout(function(){ location.reload(); }, 700); }
            else { alert_toast(resp.msg || 'That did not work.', 'error'); end_loader(); }
        }, 'json').fail(function(){ alert_toast('An error occurred.', 'error'); end_loader(); });
    });
});
</script>
