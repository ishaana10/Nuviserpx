<?php
require_once(__DIR__ . '/../../config.php');

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? (string)$d : null;
$from = $valid($_GET['from'] ?? '') ?? date('Y-m-01');
$to = $valid($_GET['to'] ?? '') ?? date('Y-m-t');
$rows = (new \App\Services\BillPaymentService($conn, $_settings))->withholdingReport($from, $to);
$byVendor = [];
$total = 0.0;
foreach ($rows as $r) {
    $k = $r['vendor'] . '|' . $r['tin'];
    $byVendor[$k] = ($byVendor[$k] ?? 0) + (float)$r['wht_amount'];
    $total += (float)$r['wht_amount'];
}
?>
<div class="card card-outline card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-percent text-info"></i> Withholding Tax on Supplier Payments</h3>
        <div class="card-tools"><button class="btn btn-sm btn-flat btn-default" id="csv"><i class="fas fa-download"></i> CSV</button></div>
    </div>
    <div class="card-body">
        <form class="form-inline mb-3" method="get">
            <input type="hidden" name="page" value="purchases/wht_report">
            <label class="mr-2 text-sm">From</label><input type="date" name="from" value="<?= $h($from) ?>" class="form-control form-control-sm mr-3">
            <label class="mr-2 text-sm">To</label><input type="date" name="to" value="<?= $h($to) ?>" class="form-control form-control-sm mr-3">
            <button class="btn btn-sm btn-primary btn-flat">Show</button>
        </form>
        <p class="text-sm text-muted">Tax kept back from supplier payments in this period (posted payments only). It sits in the <b>Withholding Tax Payable</b> account until you pay it over to the tax authority with a journal or payment from that account.</p>
        <table class="table table-sm table-bordered" id="wht">
            <thead><tr><th>Supplier</th><th>TIN</th><th>Payment</th><th>Date</th><th class="text-right">Payment (gross)</th><th class="text-right">Tax withheld</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= $h($r['vendor']) ?></td><td><?= $h($r['tin']) ?></td><td><?= $h($r['payment_no']) ?></td><td><?= $h($r['payment_date']) ?></td>
                    <td class="text-right"><?= number_format((float)$r['amount'], 2) ?></td><td class="text-right"><?= number_format((float)$r['wht_amount'], 2) ?></td></tr>
            <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted">No tax was withheld in this period.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($rows): ?><tfoot><tr><th colspan="5" class="text-right">Total withheld</th><th class="text-right"><?= number_format($total, 2) ?></th></tr></tfoot><?php endif; ?>
        </table>
        <?php if (count($byVendor) > 1): ?>
        <h6 class="font-weight-bold mt-3">By supplier</h6>
        <table class="table table-sm" style="max-width:520px"><tbody>
        <?php foreach ($byVendor as $k => $v): [$n, $tin] = explode('|', $k, 2); ?><tr><td><?= $h($n) ?> <small class="text-muted"><?= $h($tin) ?></small></td><td class="text-right"><?= number_format($v, 2) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>
</div>
<script>
$('#csv').click(function(){
    var lines = [];
    $('#wht tr').each(function(){ var c = []; $(this).find('th,td').each(function(){ c.push('"' + $(this).text().replace(/"/g, '""') + '"'); }); if (c.length > 1) lines.push(c.join(',')); });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], {type: 'text/csv'}));
    a.download = 'withholding_tax_<?= $h($from) ?>_<?= $h($to) ?>.csv';
    document.body.appendChild(a); a.click(); a.remove();
});
</script>
