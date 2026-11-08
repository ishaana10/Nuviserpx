<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\ExpenseClaimService;
use App\Services\LedgerService;

$svc = new ExpenseClaimService($conn, $_settings);
$t = (int)$_settings->active_tenant_id();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cats = $svc->categories(false);
$accounts = [];
$q = $conn->prepare("SELECT id, code, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), name");
$q->bind_param('i', $t);
$q->execute();
foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $a) { if (LedgerService::classify($a['type'])['class'] === 'expense') { $accounts[] = $a; } }
$opts = function (int $sel) use ($accounts, $h) {
    $o = '<option value="">Choose at approval</option>';
    foreach ($accounts as $a) { $o .= '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === $sel ? ' selected' : '') . '>' . $h(trim(($a['code'] ? $a['code'] . ' - ' : '') . $a['name'])) . '</option>'; }
    return $o;
};
?>
<p class="text-sm text-muted">Map each category to the expense account it is booked to, so approving is one click.</p>
<table class="table table-sm">
    <thead><tr><th>Category</th><th>Expense account</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): ?>
    <tr class="cat-row" data-id="<?= (int)$c['id'] ?>">
        <td><input type="text" class="form-control form-control-sm c-name" maxlength="100" value="<?= $h($c['name']) ?>"></td>
        <td><select class="form-control form-control-sm c-acc"><?= $opts((int)$c['account_id']) ?></select></td>
        <td class="text-center"><input type="checkbox" class="c-on" <?= (int)$c['status'] === 1 ? 'checked' : '' ?>></td>
        <td><button type="button" class="btn btn-xs btn-primary c-save">Save</button></td>
    </tr>
    <?php endforeach; ?>
    <tr class="cat-row" data-id="0">
        <td><input type="text" class="form-control form-control-sm c-name" maxlength="100" placeholder="New category"></td>
        <td><select class="form-control form-control-sm c-acc"><?= $opts(0) ?></select></td>
        <td class="text-center"><input type="checkbox" class="c-on" checked></td>
        <td><button type="button" class="btn btn-xs btn-success c-save">Add</button></td>
    </tr>
    </tbody>
</table>
<script>
$(function(){
    $('.c-save').off('click').on('click', function(){
        var r = $(this).closest('tr');
        start_loader();
        $.post(_base_url_ + "classes/Master.php?f=save_expense_category", {id: r.data('id'), name: r.find('.c-name').val(), account_id: r.find('.c-acc').val(), status: r.find('.c-on').is(':checked') ? 1 : 0}, function(resp){
            end_loader();
            if (resp.status == 'success') { alert_toast(resp.msg, 'success'); uni_modal('Expense categories', 'expense_reports/categories.php'); }
            else { alert_toast(resp.msg || 'That did not work.', 'error'); }
        }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
    });
});
</script>
