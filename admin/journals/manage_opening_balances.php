<?php
/**
 * Opening balances: one dated journal. Anything left unbalanced goes to Opening Balance Equity.
 * A line can be in a foreign currency (e.g. a USD bank account): the foreign amount is kept so the
 * account can be reconciled and revalued in that currency.
 */
require_once(__DIR__ . '/../../config.php');

use App\Services\LedgerService;

$tenant_id = (int)$_settings->active_tenant_id();
$fx = new \App\Services\FxService($conn, $_settings);
$base_currency = $fx->baseCurrency();
$foreign_currencies = $fx->foreignCurrencies();

$accounts = [];
$st = $conn->prepare("SELECT id, code, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 AND name <> 'Opening Balance Equity'
                      ORDER BY code IS NULL, code = '', CAST(code AS UNSIGNED), code, name");
$st->bind_param('i', $tenant_id);
$st->execute();
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
    $accounts[] = $a;
}
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-door-open text-primary mr-2"></i> Opening Balances</h3></div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            Enter each account's balance as at the day before your first transaction. The difference is posted to
            <b>Opening Balance Equity</b> automatically. For a foreign-currency account (for example a USD bank account),
            choose the currency and enter the amount <b>in that currency</b>; it is converted at the as-at rate unless you give a rate.
            Amounts in <?= $e($base_currency ?: 'the base currency') ?> need no currency.
        </div>
        <form id="ob-form">
            <div class="form-group" style="max-width:240px">
                <label class="font-weight-bold">As at</label>
                <input type="date" name="as_at" class="form-control" required>
            </div>
            <table class="table table-sm table-bordered" id="ob-table">
                <thead><tr class="bg-light">
                    <th>Account</th><th class="text-right" style="width:140px">Debit</th><th class="text-right" style="width:140px">Credit</th>
                    <?php if ($foreign_currencies): ?><th style="width:120px">Currency</th><th style="width:130px">Rate (optional)</th><?php endif; ?>
                    <th style="width:40px"></th></tr></thead>
                <tbody></tbody>
            </table>
            <button type="button" id="ob-add" class="btn btn-sm btn-outline-primary btn-flat"><i class="fas fa-plus"></i> Add line</button>
        </form>
    </div>
    <div class="card-footer text-right">
        <button type="button" id="ob-save" class="btn btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Post opening balances</button>
    </div>
</div>
<script>
(function(){
    var accounts = <?= json_encode($accounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var currencies = <?= json_encode(array_values($foreign_currencies), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function row(){
        var $tr = $('<tr>');
        var $sel = $('<select name="account_id[]" class="form-control form-control-sm"><option value="">Select account</option></select>');
        accounts.forEach(function(a){ $sel.append($('<option>').val(a.id).text((a.code ? a.code + ' - ' : '') + a.name)); });
        $tr.append($('<td>').append($sel));
        $tr.append($('<td>').append('<input type="number" step="0.01" min="0" name="debit[]" class="form-control form-control-sm text-right">'));
        $tr.append($('<td>').append('<input type="number" step="0.01" min="0" name="credit[]" class="form-control form-control-sm text-right">'));
        if (currencies.length) {
            var $c = $('<select name="currency[]" class="form-control form-control-sm"><option value=""><?= $e($base_currency) ?></option></select>');
            currencies.forEach(function(c){ var code = (typeof c === 'string') ? c : (c.code || c.currency_code); $c.append($('<option>').val(code).text(code)); });
            $tr.append($('<td>').append($c));
            $tr.append($('<td>').append('<input type="number" step="0.00000001" min="0" name="fx_rate[]" class="form-control form-control-sm text-right">'));
        }
        $tr.append($('<td>').append('<button type="button" class="btn btn-xs btn-outline-danger rm"><i class="fas fa-times"></i></button>'));
        return $tr;
    }
    for (var i = 0; i < 5; i++) $('#ob-table tbody').append(row());
    $('#ob-add').on('click', function(){ $('#ob-table tbody').append(row()); });
    $(document).on('click', '#ob-table .rm', function(){ $(this).closest('tr').remove(); });
    $('#ob-save').on('click', function(){
        start_loader();
        $.ajax({url: _base_url_ + 'classes/Master.php?f=post_opening_balances', method: 'POST', data: $('#ob-form').serialize(), dataType: 'json',
            error: function(){ end_loader(); alert_toast('An error occurred', 'error'); },
            success: function(r){
                end_loader();
                if (r && r.status === 'success') { location.href = './?page=journals'; }
                else { alert_toast((r && r.msg) || 'Could not post', 'error'); }
            }});
    });
})();
</script>
