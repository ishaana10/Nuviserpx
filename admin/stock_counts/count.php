<?php
require_once(__DIR__ . '/../../config.php');

use App\Services\StockTakeService as S;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$svc = new S($conn, $_settings);
$count = $svc->find((int)($_GET['id'] ?? 0));
if (!$count) { echo '<div class="alert alert-warning">That count was not found.</div>'; return; }
$lines = $svc->lines((int)$count['id']);
$open = $count['status'] === 'draft';
$sum = S::summary($lines);
$wname = (int)$count['warehouse_id'] > 0 ? (new \App\Services\WarehouseStockService($conn, $_settings))->warehouseName((int)$count['warehouse_id']) : 'Main / unassigned';
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.') ?: '0';
?>
<div class="card card-outline card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-clipboard-check text-info"></i> <?= $h($count['count_no']) ?> &middot; <?= $h($wname) ?> &middot; <?= $h($count['count_date']) ?> <span class="badge badge-<?= $open ? 'warning' : ($count['status'] === 'posted' ? 'success' : 'secondary') ?>"><?= $h($count['status']) ?></span></h3>
        <div class="card-tools">
            <button class="btn btn-sm btn-flat btn-default" onclick="window.print()"><i class="fas fa-print"></i> Print sheet</button>
            <a class="btn btn-sm btn-flat btn-default" href="<?= base_url ?>admin/?page=stock_counts">Back</a>
        </div>
    </div>
    <div class="card-body">
        <?php if ($count['notes']): ?><p class="text-muted"><?= $h($count['notes']) ?></p><?php endif; ?>
        <p class="text-sm" id="sum"><?= $sum['counted'] ?> of <?= $sum['lines'] ?> counted &middot; <?= $sum['over'] ?> over &middot; <?= $sum['short'] ?> short &middot; value of difference <b><?= number_format($sum['value'], 2) ?></b></p>
        <form id="sheet">
            <input type="hidden" name="id" value="<?= (int)$count['id'] ?>">
            <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead><tr><th>Code</th><th>Product</th><th class="text-right">System</th><th style="width:140px">Counted</th><th class="text-right">Difference</th><th class="text-right">Value</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $l):
                    $has = $l['counted_qty'] !== null;
                    $v = $has ? S::variance((float)$l['counted_qty'], (float)$l['system_qty']) : null; ?>
                    <tr data-sys="<?= $h($l['system_qty']) ?>" data-cost="<?= $h($l['unit_cost']) ?>" class="<?= $l['line_status'] === 'failed' ? 'table-danger' : '' ?>">
                        <td><?= $h($l['product_code']) ?></td><td><?= $h($l['name']) ?></td>
                        <td class="text-right"><?= $fmt($l['system_qty']) ?></td>
                        <td><?php if ($open && $l['line_status'] !== 'posted'): ?>
                            <input type="text" inputmode="decimal" class="form-control form-control-sm cnt" name="counted[<?= (int)$l['product_id'] ?>]" value="<?= $has ? $h($fmt($l['counted_qty'])) : '' ?>">
                            <?php else: ?><?= $has ? $fmt($l['counted_qty']) : '-' ?><?php endif; ?></td>
                        <td class="text-right diff"><?= $v === null ? '' : ($v > 0 ? '+' : '') . $fmt($v) ?></td>
                        <td class="text-right val"><?= $v === null ? '' : number_format($v * (float)$l['unit_cost'], 2) ?></td>
                        <td class="text-xs"><?= $h($l['result_note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </form>
        <?php if ($open): ?>
        <button class="btn btn-sm btn-flat btn-primary" id="btn-save"><i class="fas fa-save"></i> Save counts</button>
        <button class="btn btn-sm btn-flat btn-success" id="btn-post"><i class="fas fa-check"></i> Post differences</button>
        <button class="btn btn-sm btn-flat btn-outline-danger float-right" id="btn-cancel">Cancel count</button>
        <p class="text-xs text-muted mt-2 mb-0">Leave a row blank to skip it. Posting adds or removes the difference from the system figure recorded when the count started, so stock sold or received since is kept. Items you did not count are not changed.</p>
        <?php endif; ?>
    </div>
</div>
<style>@media print{.main-sidebar,.main-header,.card-tools,#btn-save,#btn-post,#btn-cancel{display:none!important}.content-wrapper{margin-left:0!important}}</style>
<script>
$(function(){
    function recalc(){
        $('#sheet tbody tr').each(function(){
            var inp = $(this).find('.cnt'); if (!inp.length) return;
            var t = $.trim(inp.val()).replace(/,/g,''), d = $(this).find('.diff'), v = $(this).find('.val');
            if (t === '' || isNaN(t)) { d.text(''); v.text(''); return; }
            var diff = Math.round((parseFloat(t) - parseFloat($(this).data('sys'))) * 10000) / 10000;
            d.text((diff > 0 ? '+' : '') + diff); v.text((diff * parseFloat($(this).data('cost'))).toFixed(2));
        });
    }
    $('.cnt').on('input', recalc);
    function send(f, extra){
        start_loader();
        $.ajax({url:_base_url_+'classes/Master.php?f='+f, method:'POST', data:$('#sheet').serialize()+(extra||''), dataType:'json',
            error:function(){ alert_toast('An error occurred.','error'); end_loader(); },
            success:function(r){ alert_toast(r.msg, r.status==='success'?'success':'error'); end_loader(); if (f !== 'save_stock_counts' || r.status==='success') setTimeout(function(){ location.reload(); }, f==='save_stock_counts'?600:1500); }});
    }
    $('#btn-save').click(function(){ send('save_stock_counts'); });
    $('#btn-post').click(function(){ if (confirm('Post the differences? Stock will be adjusted and the ledger updated.')) send('post_stock_count'); });
    $('#btn-cancel').click(function(){ if (confirm('Cancel this count?')) send('cancel_stock_count'); });
});
</script>
