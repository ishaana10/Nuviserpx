<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\ExpenseClaimService;
use App\Services\LedgerService;

$svc = new ExpenseClaimService($conn, $_settings);
$claim = $svc->claim((int)($_GET['id'] ?? 0));
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (!$claim || $claim['status'] !== 'pending') { echo '<div class="alert alert-warning">This claim is no longer pending.</div>'; return; }
$t = (int)$_settings->active_tenant_id();
$catAcc = 0;
foreach ($svc->categories(false) as $c) { if ($c['name'] === $claim['category']) { $catAcc = (int)$c['account_id']; } }
$accounts = [];
$q = $conn->prepare("SELECT id, code, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), name");
$q->bind_param('i', $t);
$q->execute();
foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $a) { if (LedgerService::classify($a['type'])['class'] === 'expense') { $accounts[] = $a; } }
?>
<form id="approve-frm">
    <input type="hidden" name="id" value="<?= (int)$claim['id'] ?>">
    <p class="mb-2"><b><?= $h($claim['claim_no']) ?></b> &middot; <?= $h($claim['category']) ?> &middot; <b><?= number_format((float)$claim['amount'], 2) ?></b><br><small class="text-muted"><?= $h($claim['description']) ?></small></p>
    <div class="form-group">
        <label>Expense account</label>
        <select name="account_id" class="form-control form-control-sm" required>
            <option value="">Select account...</option>
            <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $catAcc ? 'selected' : '' ?>><?= $h(trim(($a['code'] ? $a['code'] . ' - ' : '') . $a['name'])) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted"><?= $catAcc ? 'Mapped from the category.' : 'This category has no account yet. Map it under Categories to skip this step.' ?></small>
    </div>
    <div class="form-group"><label>Note (optional)</label><input type="text" name="note" maxlength="500" class="form-control form-control-sm"></div>
    <p class="text-sm text-muted mb-0">Approving posts the expense and records what is owed to the employee.</p>
</form>
<script>
$(function(){
    $('#uni_modal').find('.modal-footer').find('#submit').off('click').on('click', function(){ $('#approve-frm').submit(); });
    $('#approve-frm').off('submit').on('submit', function(e){
        e.preventDefault(); start_loader();
        $.post(_base_url_ + "classes/Master.php?f=approve_expense_claim", $(this).serialize(), function(resp){
            if (resp.status == 'success') { alert_toast(resp.msg, 'success'); setTimeout(function(){ location.reload(); }, 700); }
            else { alert_toast(resp.msg || 'That did not work.', 'error'); end_loader(); }
        }, 'json').fail(function(){ alert_toast('An error occurred.', 'error'); end_loader(); });
    });
});
</script>
