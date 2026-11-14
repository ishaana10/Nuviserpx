<?php
/**
 * Unpaid invoices by customer: who owes what, invoice by invoice, with a printable statement per customer and
 * an Email button (one customer, or every customer who has an email address on file).
 * Draft and cancelled invoices are not receivables and are excluded.
 */
require_once __DIR__ . '/_kit.php';

use App\Services\CustomerStatementService;
use App\Services\LedgerService;

$svc = new CustomerStatementService($conn, $_settings);
$as_of = CustomerStatementService::cleanDate($_GET['as_of'] ?? null, date('Y-m-d'));
$party_id = (int)($_GET['party_id'] ?? 0);
$can_email = $_settings->can_access('invoices_view');
$can_email_all = $_settings->can_access('invoices_edit');
$buckets = LedgerService::AGING_BUCKETS;
$ar = $svc->statements($as_of, $party_id > 0 ? $party_id : null);
$t = $ar['totals'];
$overdue = $t['total'] - $t['current'];
$brand = $_settings->tenant_branding();
$company = $brand['name'] !== '' ? $brand['name'] : $_settings->info('name');
$no_email = 0;
foreach ($ar['customers'] as $c) {
    if (CustomerStatementService::validEmail($c['email']) === null) {
        $no_email++;
    }
}
$single = $party_id > 0 && count($ar['customers']) === 1 ? $ar['customers'][0] : null;
$recent = 0; // customers already emailed a statement in the last 7 days
foreach ($ar['customers'] as $c) {
    if (!empty($c['last_sent']) && strtotime($c['last_sent']) >= strtotime('-7 days')) {
        $recent++;
    }
}
$nv_last = function (?string $d): string {
    return $d ? date('j M Y', strtotime($d)) : 'Never';
};
$pdf_url = function (int $pid, bool $download = false) use ($as_of): string {
    return base_url . 'admin/reports/customer_statement_pdf.php?party_id=' . $pid . '&as_of=' . urlencode($as_of) . ($download ? '&download=1' : '');
};

nvr_header([
    'page' => 'reports/customer_statements',
    'title' => $single ? 'Statement: ' . $single['name'] : 'Customer Statements',
    'icon' => 'fas fa-file-invoice-dollar',
    'mode' => 'asat',
    'asat' => $as_of,
    'extra' => $party_id > 0 ? '<input type="hidden" name="party_id" value="' . $party_id . '">' : '',
    'subtitle' => 'Unpaid invoices as at ' . nvr_nice_date($as_of),
]);
?>
<?php if ($single): ?>
    <?php $c = $single; $cur = (string)$_settings->currency_tag(); ?>
    <div class="d-print-none mb-3 d-flex flex-wrap" style="gap:.5rem">
        <a class="btn btn-default" href="./?page=reports/customer_statements&as_of=<?= urlencode($as_of) ?>"><i class="fas fa-arrow-left"></i> All customers</a>
        <?php if ($can_email): ?>
            <a class="btn btn-default" href="<?= htmlspecialchars($pdf_url((int)$c['id'])) ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> View PDF</a>
            <a class="btn btn-default" href="<?= htmlspecialchars($pdf_url((int)$c['id'], true)) ?>"><i class="fas fa-download"></i> Download PDF</a>
        <?php endif; ?>
        <?php if ($can_email): ?>
            <button type="button" class="btn btn-primary" id="nvs-email-one" data-party="<?= (int)$c['id'] ?>" data-email="<?= htmlspecialchars((string)$c['email']) ?>" data-name="<?= htmlspecialchars($c['name']) ?>"><i class="fas fa-envelope"></i> Email this statement</button>
        <?php endif; ?>
    </div>
    <div class="mb-3" style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div>
            <?php if (!empty($brand['logo'])): ?><img src="<?= validate_image($brand['logo']) ?>" alt="" style="max-height:60px;width:auto;margin-bottom:6px"><br><?php endif; ?>
            <b><?= htmlspecialchars($company) ?></b>
            <?php if ($brand['address'] !== ''): ?><div class="muted" style="font-size:.85rem"><?= nl2br(htmlspecialchars($brand['address'])) ?></div><?php endif; ?>
            <?php if ($brand['email'] !== '' || $brand['phone'] !== ''): ?><div class="muted" style="font-size:.85rem"><?= htmlspecialchars(trim($brand['email'] . ' ' . $brand['phone'])) ?></div><?php endif; ?>
        </div>
        <div style="text-align:right">
            <div class="muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Statement for</div>
            <b><?= htmlspecialchars($c['name']) ?></b> <span class="muted"><?= htmlspecialchars($c['code']) ?></span>
            <div class="muted" style="font-size:.85rem"><?= $c['email'] !== '' ? htmlspecialchars($c['email']) : 'No email on file' ?></div>
            <div class="muted d-print-none" style="font-size:.8rem">Last emailed: <?= htmlspecialchars($nv_last($c['last_sent'] ?? null)) ?></div>
        </div>
    </div>
    <div class="nvr-scroll">
    <table class="nvr-table">
        <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="num">Invoice total</th><th class="num">Balance due</th><th class="num">Age</th></tr></thead>
        <tbody>
        <?php foreach ($c['invoices'] as $i): ?>
            <tr class="acct">
                <td><a class="nvr-drill" href="./?page=invoices/view&id=<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['invoice_no']) ?></a></td>
                <td><?= nvr_nice_date($i['invoice_date']) ?></td>
                <td><?= $i['due_date'] ? nvr_nice_date($i['due_date']) : '–' ?></td>
                <td class="num"><?= nvr_money($i['total']) ?></td>
                <td class="num<?= $i['bucket'] !== 'current' ? ' neg' : '' ?>"><b><?= nvr_money($i['balance']) ?></b></td>
                <td class="num"><?= htmlspecialchars($buckets[$i['bucket']]) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="grand"><td colspan="4">Total outstanding <?= htmlspecialchars($cur) ?></td><td class="num"><?= nvr_money($c['total'], false) ?></td><td></td></tr></tfoot>
    </table>
    </div>
<?php elseif ($party_id > 0): ?>
    <div class="d-print-none mb-3"><a class="btn btn-default" href="./?page=reports/customer_statements&as_of=<?= urlencode($as_of) ?>"><i class="fas fa-arrow-left"></i> All customers</a></div>
    <div class="nvr-empty"><i class="fas fa-check-circle"></i><b>Nothing owed</b>This customer has no unpaid invoice dated on or before <?= nvr_nice_date($as_of) ?>.</div>
<?php else: ?>
    <div class="nvr-summary">
        <div class="nvr-stat"><div class="k">Customers owing</div><div class="v"><?= count($ar['customers']) ?></div><div class="s"><?= $no_email ?> without an email address</div></div>
        <div class="nvr-stat"><div class="k">Total unpaid</div><div class="v"><?= nvr_money($t['total'], false) ?></div></div>
        <div class="nvr-stat"><div class="k">Overdue</div><div class="v<?= $overdue > 0 ? ' neg' : '' ?>"><?= nvr_money($overdue, false) ?></div></div>
    </div>
    <?php if (!$ar['customers']): ?>
        <div class="nvr-empty"><i class="fas fa-check-circle"></i><b>Nothing owed</b>No customer has an unpaid invoice dated on or before <?= nvr_nice_date($as_of) ?>.</div>
    <?php else: ?>
        <?php if ($can_email_all): ?>
        <div class="d-print-none mb-3" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:220px"><label class="mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:600">Message to include (optional)</label>
                <input type="text" id="nvs-note" class="form-control" maxlength="<?= CustomerStatementService::MAX_NOTE ?>" placeholder="e.g. Please settle by the end of the month."></div>
            <button type="button" class="btn btn-primary" id="nvs-email-all" data-count="<?= count($ar['customers']) - $no_email ?>" data-recent="<?= (int)$recent ?>"><i class="fas fa-paper-plane"></i> Email all (<?= count($ar['customers']) - $no_email ?>)</button>
        </div>
        <?php endif; ?>
        <div class="nvr-scroll">
        <table class="nvr-table">
            <thead><tr><th>Customer</th><th>Email</th><th class="num">Unpaid invoices</th><th class="num">Overdue</th><th class="num">Total owed</th><th>Last emailed</th><th class="d-print-none"></th></tr></thead>
            <tbody>
            <?php foreach ($ar['customers'] as $c): $od = $c['total'] - $c['current']; $ok = CustomerStatementService::validEmail($c['email']) !== null; ?>
                <tr class="acct">
                    <td><b><?= htmlspecialchars($c['name']) ?></b> <span class="muted" style="font-size:.78rem"><?= htmlspecialchars($c['code']) ?></span></td>
                    <td><?= $ok ? htmlspecialchars($c['email']) : '<span class="nvr-pill">No email</span>' ?></td>
                    <td class="num"><?= count($c['invoices']) ?></td>
                    <td class="num<?= $od > 0 ? ' neg' : '' ?>"><?= nvr_money($od) ?></td>
                    <td class="num"><b><?= nvr_money($c['total']) ?></b></td>
                    <td><?php if (!empty($c['last_sent']) && strtotime($c['last_sent']) >= strtotime('-7 days')): ?><span class="nvr-pill" title="Emailed in the last 7 days"><?= htmlspecialchars($nv_last($c['last_sent'])) ?></span><?php else: ?><span class="muted"><?= htmlspecialchars($nv_last($c['last_sent'] ?? null)) ?></span><?php endif; ?></td>
                    <td class="d-print-none" style="white-space:nowrap">
                        <a class="btn btn-sm btn-default" href="./?page=reports/customer_statements&party_id=<?= (int)$c['id'] ?>&as_of=<?= urlencode($as_of) ?>"><i class="fas fa-eye"></i> Statement</a>
                        <?php if ($can_email): ?><a class="btn btn-sm btn-default" href="<?= htmlspecialchars($pdf_url((int)$c['id'])) ?>" target="_blank" rel="noopener" title="View PDF"><i class="fas fa-file-pdf"></i></a><?php endif; ?>
                        <?php if ($can_email): ?><button type="button" class="btn btn-sm btn-primary nvs-email" data-party="<?= (int)$c['id'] ?>" data-email="<?= htmlspecialchars((string)$c['email']) ?>" data-name="<?= htmlspecialchars($c['name']) ?>"><i class="fas fa-envelope"></i> Email</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="grand"><td colspan="4">Total</td><td class="num"><?= nvr_money($t['total'], false) ?></td><td></td><td class="d-print-none"></td></tr></tfoot>
        </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php
nvr_footer(['Balances are as they stand today for invoices issued on or before the selected date. Each email, with a PDF copy attached, is recorded in Settings &gt; Company Email &gt; Email log. PDFs are made on the spot and never stored on the server.'], false);
?>
<script>
$(function(){
    var asOf = <?= json_encode($as_of) ?>;
    function send(url, data){
        start_loader();
        $.ajax({ url: _base_url_ + "classes/Master.php?f=" + url, method: "POST", data: data, dataType: "json",
            error: function(){ end_loader(); alert_toast("An error occurred.", 'error'); },
            success: function(r){ end_loader(); alert_toast((r && r.msg) || "Done.", r && r.status === 'success' ? 'success' : 'error'); }
        });
    }
    $(document).on('click', '.nvs-email, #nvs-email-one', function(){
        var $b = $(this), email = $b.attr('data-email') || '';
        var to = prompt("Send " + $b.attr('data-name') + "'s statement to:", email);
        if (to) { send('email_customer_statement', { party_id: $b.attr('data-party'), as_of: asOf, custom_email: to, note: $('#nvs-note').val() || '' }); }
    });
    $('#nvs-email-all').on('click', function(){
        var n = $(this).attr('data-count'), r = parseInt($(this).attr('data-recent'), 10) || 0;
        var warn = r > 0 ? "\n\n" + r + " of them were already emailed a statement in the last 7 days." : "";
        if (n > 0 && confirm("Email a statement (with PDF) to " + n + " customer(s) who have an email address?" + warn)) {
            send('email_customer_statements_all', { as_of: asOf, note: $('#nvs-note').val() || '' });
        }
    });
});
</script>
