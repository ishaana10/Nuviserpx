<?php
/**
 * VAT return worksheet.
 *
 * Output VAT comes from issued invoices (draft and cancelled excluded) plus any
 * manual journal lines tagged "VAT on Income". Input VAT comes from vendor bills
 * (draft and cancelled excluded) plus posted journal lines tagged "VAT on Expenses".
 * Nothing is estimated. A reconciliation to the VAT Output and VAT Input ledger
 * accounts at the foot of the page shows whether the return agrees with the books.
 */
require_once __DIR__ . '/../reports/_kit.php';

use App\Services\LedgerService;

$periods = nvr_periods();
$from = nvr_date($_GET['from'] ?? null, $periods['this_month'][1]);
$to = nvr_date($_GET['to'] ?? null, $periods['this_month'][2]);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$tid = (int)$_settings->active_tenant_id();

$svc = new \App\Services\TaxReturnService($conn, $_settings);
$ret = $svc->compute($from, $to);
$invoices = $ret['invoices'];
$bills = $ret['bills'];
$jv = $ret['jv'];
$inv_net = $inv_tax = $bill_net = $bill_tax = 0.0;
foreach ($invoices as $i) {
    $inv_net += (float)$i['total'] - (float)$i['tax_amount'];
    $inv_tax += (float)$i['tax_amount'];
}
foreach ($bills as $b) {
    $bill_net += (float)$b['total'] - (float)$b['tax_amount'];
    $bill_tax += (float)$b['tax_amount'];
}
$output_tax = $ret['totals']['output_tax'];
$input_tax = $ret['totals']['input_tax'];
$net = $ret['totals']['net_tax'];
$filed = $svc->filedOverlapping($from, $to);
$lock_date = \App\Services\PeriodLockService::lockDate($conn, $tid);

nvr_header([
    'page' => 'tax/report',
    'title' => 'VAT Return',
    'icon' => 'fas fa-receipt',
    'mode' => 'range',
    'from' => $from,
    'to' => $to,
    'subtitle' => nvr_nice_date($from) . ' – ' . nvr_nice_date($to),
]);
?>
<?php if ($filed): ?>
<div class="alert alert-success text-sm d-print-none">
    A return for <?= htmlspecialchars($filed['period_from']) ?> to <?= htmlspecialchars($filed['period_to']) ?> was filed on <?= htmlspecialchars($filed['filed_date']) ?><?= $filed['reference'] ? ' (ref ' . htmlspecialchars($filed['reference']) . ')' : '' ?>
    with net VAT <?= nvr_money($filed['net_tax'], false) ?>. <a href="./?page=tax/returns">See filed returns</a>.
</div>
<?php elseif ($_settings->can_access('tax_file') && $to <= date('Y-m-d')): ?>
<div class="d-print-none mb-2">
    <?php if ($lock_date !== null && $lock_date >= $to): ?>
    <button type="button" class="btn btn-sm btn-success btn-flat" id="file-return"><i class="fas fa-stamp mr-1"></i> Record this return as filed</button>
    <?php else: ?>
    <span class="text-muted text-sm">To record this return as filed, first close the books up to <?= htmlspecialchars($to) ?>.</span>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="nvr-summary">
    <div class="nvr-stat"><div class="k">Taxable sales (net)</div><div class="v"><?= nvr_money($inv_net + $jv['output_net'], false) ?></div><div class="s"><?= count($invoices) ?> invoice<?= count($invoices) === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k">Output VAT</div><div class="v"><?= nvr_money($output_tax, false) ?></div><div class="s">Collected on sales</div></div>
    <div class="nvr-stat"><div class="k">Input VAT</div><div class="v"><?= nvr_money($input_tax, false) ?></div><div class="s"><?= count($bills) + count($jv['input']) ?> purchase document<?= count($bills) + count($jv['input']) === 1 ? '' : 's' ?></div></div>
    <div class="nvr-stat"><div class="k"><?= $net >= 0 ? 'VAT payable' : 'VAT refundable' ?></div><div class="v<?= $net < 0 ? ' neg' : '' ?>"><?= nvr_money(abs($net), false) ?></div><div class="s">Output − input</div></div>
</div>

<?php if (!$invoices && !$bills && !$jv['output'] && !$jv['input']): ?>
    <div class="nvr-empty"><i class="fas fa-receipt"></i><b>No VAT transactions in this period</b>Issued invoices and journal lines tagged with a VAT rate appear here.</div>
<?php else: ?>
<div class="nvr-scroll">
<table class="nvr-table">
    <thead><tr><th>Date</th><th>Reference</th><th>Contact / description</th><th class="num">Net</th><th class="num">VAT</th><th class="num">Gross</th></tr></thead>
    <tbody>
        <tr class="sec"><td colspan="6">Output VAT — sales</td></tr>
        <?php foreach ($invoices as $i): $n = (float)$i['total'] - (float)$i['tax_amount']; ?>
        <tr class="acct">
            <td style="white-space:nowrap"><?= date('j M Y', strtotime($i['invoice_date'])) ?></td>
            <td style="white-space:nowrap"><a class="nvr-drill" href="./?page=invoices/view&id=<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['invoice_no']) ?></a></td>
            <td><?= htmlspecialchars($i['party'] ?? '—') ?></td>
            <td class="num"><?= nvr_money($n) ?></td>
            <td class="num"><?= nvr_money($i['tax_amount']) ?></td>
            <td class="num"><?= nvr_money($i['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach ($jv['output'] as $l): ?>
        <tr class="acct">
            <td style="white-space:nowrap"><?= date('j M Y', strtotime($l['date'])) ?></td>
            <td style="white-space:nowrap"><a class="nvr-drill" href="javascript:void(0)" onclick="uni_modal('Journal <?= htmlspecialchars($l['code'], ENT_QUOTES) ?>','journals/view_details.php?id=<?= $l['journal_id'] ?>','modal-lg')"><?= htmlspecialchars($l['code']) ?></a></td>
            <td><?= htmlspecialchars($l['description']) ?> <span class="nvr-pill">Journal<?= $l['inclusive'] ? ' · tax incl.' : '' ?></span></td>
            <td class="num"><?= nvr_money($l['net']) ?></td>
            <td class="num"><?= nvr_money($l['tax']) ?></td>
            <td class="num"><?= nvr_money($l['net'] + $l['tax']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$invoices && !$jv['output']): ?><tr class="acct"><td colspan="6" class="muted">No taxable sales in this period</td></tr><?php endif; ?>
        <tr class="sub"><td colspan="3">Total output VAT</td><td class="num"><?= nvr_money($inv_net + $jv['output_net'], false) ?></td><td class="num"><?= nvr_money($output_tax, false) ?></td><td class="num"><?= nvr_money($inv_net + $jv['output_net'] + $output_tax, false) ?></td></tr>

        <tr class="sec"><td colspan="6">Input VAT — purchases and expenses</td></tr>
        <?php foreach ($bills as $b): $n = (float)$b['total'] - (float)$b['tax_amount']; ?>
        <tr class="acct">
            <td style="white-space:nowrap"><?= date('j M Y', strtotime($b['bill_date'])) ?></td>
            <td style="white-space:nowrap"><?= htmlspecialchars($b['bill_no']) ?></td>
            <td><?= htmlspecialchars($b['party'] ?? '—') ?> <span class="nvr-pill">Bill</span></td>
            <td class="num"><?= nvr_money($n) ?></td>
            <td class="num"><?= nvr_money($b['tax_amount']) ?></td>
            <td class="num"><?= nvr_money($b['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach ($jv['input'] as $l): ?>
        <tr class="acct">
            <td style="white-space:nowrap"><?= date('j M Y', strtotime($l['date'])) ?></td>
            <td style="white-space:nowrap"><a class="nvr-drill" href="javascript:void(0)" onclick="uni_modal('Journal <?= htmlspecialchars($l['code'], ENT_QUOTES) ?>','journals/view_details.php?id=<?= $l['journal_id'] ?>','modal-lg')"><?= htmlspecialchars($l['code']) ?></a></td>
            <td><?= htmlspecialchars($l['description']) ?><?= $l['inclusive'] ? ' <span class="nvr-pill">tax incl.</span>' : '' ?></td>
            <td class="num<?= $l['net'] < 0 ? ' neg' : '' ?>"><?= nvr_money($l['net']) ?></td>
            <td class="num<?= $l['tax'] < 0 ? ' neg' : '' ?>"><?= nvr_money($l['tax']) ?></td>
            <td class="num"><?= nvr_money($l['net'] + $l['tax']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$bills && !$jv['input']): ?><tr class="acct"><td colspan="6" class="muted">No input VAT recorded in this period. Tag expense journal lines with “VAT on Expenses (15%)” to claim it.</td></tr><?php endif; ?>
        <tr class="sub"><td colspan="3">Total input VAT</td><td class="num"><?= nvr_money($bill_net + $jv['input_net'], false) ?></td><td class="num"><?= nvr_money($input_tax, false) ?></td><td class="num"><?= nvr_money($bill_net + $jv['input_net'] + $input_tax, false) ?></td></tr>

        <tr class="grand"><td colspan="4"><?= $net >= 0 ? 'Net VAT payable to the tax authority' : 'Net VAT refundable from the tax authority' ?></td><td class="num<?= $net < 0 ? ' neg' : '' ?>"><?= nvr_money($net, false) ?></td><td></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
// Reconcile to the ledger: what the VAT control accounts say moved in the same period.
$ob = nvr_ledger()->balances($from, $to, false);
$ledger = ['output' => null, 'input' => null];
foreach ($ob as $acc) {
    if ($acc['name'] === \App\Services\TaxService::OUTPUT_ACCOUNT) {
        $ledger['output'] = round((float)$acc['cr'] - (float)$acc['dr'], 2);
    } elseif ($acc['name'] === \App\Services\TaxService::INPUT_ACCOUNT) {
        $ledger['input'] = round((float)$acc['dr'] - (float)$acc['cr'], 2);
    }
}
if ($ledger['output'] !== null || $ledger['input'] !== null):
    $dOut = $ledger['output'] === null ? null : round($ledger['output'] - $output_tax, 2);
    $dIn = $ledger['input'] === null ? null : round($ledger['input'] - $input_tax, 2);
?>
<div class="nvr-scroll" style="margin-top:18px">
<table class="nvr-table">
    <thead><tr><th>Reconciliation to the ledger</th><th class="num">This return</th><th class="num">VAT account</th><th class="num">Difference</th></tr></thead>
    <tbody>
        <tr class="acct"><td>Output VAT (<?= htmlspecialchars(\App\Services\TaxService::OUTPUT_ACCOUNT) ?>)</td><td class="num"><?= nvr_money($output_tax, false) ?></td><td class="num"><?= $ledger['output'] === null ? '—' : nvr_money($ledger['output'], false) ?></td><td class="num<?= $dOut ? ' neg' : '' ?>"><?= $dOut === null ? '—' : nvr_money($dOut, false) ?></td></tr>
        <tr class="acct"><td>Input VAT (<?= htmlspecialchars(\App\Services\TaxService::INPUT_ACCOUNT) ?>)</td><td class="num"><?= nvr_money($input_tax, false) ?></td><td class="num"><?= $ledger['input'] === null ? '—' : nvr_money($ledger['input'], false) ?></td><td class="num<?= $dIn ? ' neg' : '' ?>"><?= $dIn === null ? '—' : nvr_money($dIn, false) ?></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php if (!$filed && $_settings->can_access('tax_file') && $lock_date !== null && $lock_date >= $to && $to <= date('Y-m-d')): ?>
<script>
$('#file-return').on('click', function(){
    var ref = prompt('Reference given by the tax authority (optional):', '');
    if (ref === null) return;
    if (!confirm('Record the return for <?= $from ?> to <?= $to ?> as filed? The figures shown are saved as filed.')) return;
    start_loader();
    $.post(_base_url_ + 'classes/Master.php?f=file_tax_return', {period_from: '<?= $from ?>', period_to: '<?= $to ?>', filed_date: '<?= date('Y-m-d') ?>', reference: ref}, function(r){
        end_loader();
        if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 800); }
        else { alert_toast((r && r.msg) || 'Failed', 'error'); }
    }, 'json').fail(function(){ end_loader(); alert_toast('An error occurred.', 'error'); });
});
</script>
<?php endif; ?>
<?php
nvr_footer([
    'Output VAT is taken from each invoice’s tax amount and input VAT from each bill’s. A difference in the reconciliation usually means documents saved before VAT accounts existed, or VAT journalled by hand. Journal lines are treated as net amounts unless the journal says amounts are tax inclusive. Check figures against your records before filing. <a href="./?page=tax/rates">Manage tax rates</a>.',
]);
