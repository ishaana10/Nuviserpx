<?php
/** Filed VAT returns: what was filed, when, and by whom. */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\TaxReturnService($conn, $_settings);
$rows = $svc->returns();
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tag = $_settings->currency_tag();
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-stamp text-primary mr-2"></i> Filed VAT Returns</h3></div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            Open the <a href="./?page=tax/report">VAT Return</a> for a finished period and record it as filed. A return can only be filed
            once the books are closed up to the end of its period, so the figures behind it cannot change. Filed figures are kept as filed.
            Voiding a return (with a reason) lets the period be filed again.
        </div>
        <table class="table table-sm table-bordered">
            <thead><tr class="bg-light"><th>Period</th><th class="text-right">Taxable sales</th><th class="text-right">Output VAT</th><th class="text-right">Input VAT</th><th class="text-right">Net VAT<?= $tag ?></th><th>Filed</th><th>Reference</th><th>Status</th><th style="width:70px"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="./?page=tax/report&from=<?= $e($r['period_from']) ?>&to=<?= $e($r['period_to']) ?>"><?= $e($r['period_from']) ?> to <?= $e($r['period_to']) ?></a></td>
                    <td class="text-right"><?= number_format((float)$r['taxable_sales'], 2) ?></td>
                    <td class="text-right"><?= number_format((float)$r['output_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format((float)$r['input_tax'], 2) ?></td>
                    <td class="text-right font-weight-bold"><?= number_format((float)$r['net_tax'], 2) ?> <small class="text-muted"><?= (float)$r['net_tax'] >= 0 ? 'payable' : 'refundable' ?></small></td>
                    <td><?= $e($r['filed_date']) ?><br><small class="text-muted"><?= $e(trim((string)$r['filer'])) ?></small></td>
                    <td><?= $e($r['reference']) ?></td>
                    <td><?php if ($r['status'] === 'filed'): ?><span class="badge badge-success">Filed</span>
                        <?php else: ?><span class="badge badge-secondary">Voided</span><br><small class="text-muted"><?= $e($r['void_reason']) ?></small><?php endif; ?></td>
                    <td><?php if ($r['status'] === 'filed'): ?><button type="button" class="btn btn-xs btn-outline-danger void-btn" data-id="<?= (int)$r['id'] ?>">Void</button><?php endif; ?></td>
                </tr>
            <?php endforeach; if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted">No returns recorded as filed yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$('.void-btn').on('click', function(){
    var reason = prompt('Why is this return being voided?', '');
    if (!reason) return;
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=void_tax_return', {id: $(this).data('id'), reason: reason}, function(r){
        end_loader();
        if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 800); }
        else { alert_toast((r && r.msg) || 'Failed', 'error'); }
    }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
});
</script>
