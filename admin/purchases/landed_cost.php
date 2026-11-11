<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\InventoryAccountingService;
use App\Services\LandedCostService;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$svc = new LandedCostService($conn, $_settings);
$grn = $svc->receipt((int)($_GET['grn_id'] ?? 0));
if (!$grn) { echo '<div class="alert alert-warning">That goods receipt was not found.</div>'; return; }
$lines = $svc->receiptLines((int)$grn['id']);
$acct = new InventoryAccountingService($conn, $_settings);
$accEnabled = $acct->isEnabled();
$choices = $acct->accountChoices();
$hist = $svc->history((int)$grn['id']);
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.') ?: '0';
?>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-truck-loading text-primary"></i> Landed cost &middot; <?= $h($grn['grn_no']) ?> &middot; <?= $h($grn['vendor_name']) ?></h3>
        <div class="card-tools"><a class="btn btn-sm btn-flat btn-default" href="./?page=purchases/view&id=<?= (int)$grn['purchase_order_id'] ?>">Back to order</a></div>
    </div>
    <div class="card-body">
        <p class="text-sm text-muted">Add freight, duty, insurance and similar charges to the cost of what this receipt brought in. Only the part still on hand is added to stock; units already sold keep their old cost. This cannot be undone from here.</p>
        <form id="lc">
            <input type="hidden" name="grn_id" value="<?= (int)$grn['id'] ?>">
            <div class="row">
                <div class="col-md-2 form-group"><label class="text-sm">Date</label><input type="date" name="cost_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                <div class="col-md-3 form-group"><label class="text-sm">Spread by</label>
                    <select name="basis" class="form-control form-control-sm"><option value="value">Value of goods received</option><option value="qty">Quantity received</option></select></div>
                <?php if ($accEnabled): ?>
                <div class="col-md-4 form-group"><label class="text-sm">Charges were booked to</label>
                    <select name="credit_account_id" class="form-control form-control-sm"><option value="0">Choose account...</option>
                        <?php foreach ($choices as $id => $a): ?><option value="<?= $id ?>"><?= $h($a['name']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="col-md-3 form-group"><label class="text-sm">Notes</label><input type="text" name="notes" maxlength="500" class="form-control form-control-sm"></div>
            </div>
            <table class="table table-sm" id="charges"><thead><tr><th>Charge</th><th style="width:160px">Amount</th><th style="width:40px"></th></tr></thead><tbody></tbody></table>
            <button type="button" class="btn btn-xs btn-flat btn-outline-secondary mb-3" id="add-row"><i class="fas fa-plus"></i> Add charge</button>
            <div>
                <button type="button" class="btn btn-sm btn-flat btn-info" id="btn-preview">Preview</button>
                <button type="button" class="btn btn-sm btn-flat btn-success" id="btn-post" disabled>Add to stock cost</button>
            </div>
        </form>
        <div id="out" class="mt-3"></div>

        <h6 class="mt-4 font-weight-bold">Items on this receipt</h6>
        <table class="table table-sm table-bordered"><thead><tr><th>Product</th><th class="text-right">Received</th><th class="text-right">On hand now</th><th class="text-right">Current cost</th></tr></thead><tbody>
        <?php foreach ($lines as $l): ?><tr><td><?= $h($l['name']) ?></td><td class="text-right"><?= $fmt($l['qty']) ?></td><td class="text-right"><?= $fmt($l['on_hand']) ?></td><td class="text-right"><?= number_format($l['cost'], 4) ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <?php if ($hist): ?>
        <h6 class="mt-4 font-weight-bold">Already added</h6>
        <table class="table table-sm"><thead><tr><th>Entry</th><th>Date</th><th class="text-right">Charges</th><th class="text-right">Added to stock</th><th>Notes</th></tr></thead><tbody>
        <?php foreach ($hist as $x): ?><tr><td><?= $h($x['landed_no']) ?></td><td><?= $h($x['cost_date']) ?></td><td class="text-right"><?= number_format((float)$x['total'], 2) ?></td><td class="text-right"><?= number_format((float)$x['capitalised'], 2) ?></td><td><?= $h($x['notes']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>
</div>
<script>
$(function(){
    function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
    function addRow(){ $('#charges tbody').append('<tr><td><input name="desc[]" class="form-control form-control-sm" maxlength="150" placeholder="Freight, customs duty..."></td><td><input name="amount[]" inputmode="decimal" class="form-control form-control-sm text-right"></td><td><button type="button" class="btn btn-xs btn-flat btn-outline-danger rm">&times;</button></td></tr>'); }
    addRow();
    $('#add-row').click(addRow);
    $(document).on('click', '.rm', function(){ if ($('#charges tbody tr').length > 1) { $(this).closest('tr').remove(); $('#btn-post').prop('disabled', true); } });
    $('#lc').on('input change', function(){ $('#btn-post').prop('disabled', true); });
    $('#btn-preview').click(function(){
        start_loader();
        $.post(_base_url_+'classes/Master.php?f=preview_landed_cost', $('#lc').serialize(), null, 'json').fail(function(){ alert_toast('An error occurred.','error'); end_loader(); }).done(function(r){
            end_loader();
            if (r.status !== 'success') { $('#out').html('<div class="alert alert-danger py-2">'+esc(r.msg)+'</div>'); return; }
            var html = '<table class="table table-sm table-bordered"><thead><tr><th>Product</th><th class="text-right">Share of charges</th><th class="text-right">Added to stock</th><th class="text-right">Cost now</th><th class="text-right">Cost after</th></tr></thead><tbody>';
            r.rows.forEach(function(x){ html += '<tr><td>'+esc(x.name)+'</td><td class="text-right">'+x.allocated.toFixed(2)+'</td><td class="text-right">'+x.capitalised.toFixed(2)+'</td><td class="text-right">'+x.cost.toFixed(4)+'</td><td class="text-right">'+x.new_cost.toFixed(4)+'</td></tr>'; });
            html += '</tbody></table><p class="text-sm">Charges '+r.total.toFixed(2)+', of which <b>'+r.capitalised.toFixed(2)+'</b> goes into stock cost. The rest stays in the account it was booked to.</p>';
            $('#out').html(html); $('#btn-post').prop('disabled', false);
        });
    });
    $('#btn-post').click(function(){
        if (!confirm('Add these charges to stock cost?')) return;
        start_loader();
        $.post(_base_url_+'classes/Master.php?f=post_landed_cost', $('#lc').serialize(), null, 'json').fail(function(){ alert_toast('An error occurred.','error'); end_loader(); }).done(function(r){
            alert_toast(r.msg, r.status==='success'?'success':'error'); end_loader();
            if (r.status==='success') setTimeout(function(){ location.reload(); }, 1200);
        });
    });
});
</script>
