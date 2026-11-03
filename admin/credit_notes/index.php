<?php
/**
 * Credit notes (customers, against invoices) and debit notes (suppliers, against bills).
 */
$svc = new \App\Services\CreditNoteService($conn, $_settings);
$notes = $svc->notes();
$tab = in_array($_GET['type'] ?? '', ['credit', 'debit'], true) ? $_GET['type'] : 'all';
if ($tab !== 'all') {
    $notes = array_values(array_filter($notes, fn ($n) => $n['note_type'] === $tab));
}
$docs = ['credit' => $svc->documents('credit'), 'debit' => $svc->documents('debit')];
$can_credit = $_settings->can_access('invoices_create');
$can_debit = $_settings->can_access('purchases_create');
$fmt = fn ($n) => number_format((float)$n, 2);
$refunds = $svc->refunds();
$accounts = $conn->query("SELECT id, name FROM `account_list` WHERE tenant_id = '" . (int)$_settings->active_tenant_id() . "' AND delete_flag = 0 AND status = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
        <h3 class="font-weight-bold text-navy m-0"><i class="fas fa-file-invoice-dollar mr-2"></i>Credit &amp; debit notes</h3>
        <div>
            <?php if ($can_credit): ?><button class="btn btn-sm btn-primary new-note" data-type="credit"><i class="fas fa-plus mr-1"></i> Credit note</button><?php endif; ?>
            <?php if ($can_debit): ?><button class="btn btn-sm btn-outline-primary new-note" data-type="debit"><i class="fas fa-plus mr-1"></i> Debit note</button><?php endif; ?>
        </div>
    </div>
    <p class="text-muted text-sm">A <b>credit note</b> reduces what a customer owes on an invoice (returns, discounts, billing errors). A <b>debit note</b> reduces what you owe a supplier on a bill.
        Tax is corrected along with the amount. A note can be raised for up to the document's total less credit already given;
        if the document has already been paid, the excess becomes money to <b>refund</b>.</p>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (['all' => 'All', 'credit' => 'Credit notes', 'debit' => 'Debit notes'] as $k => $l): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active font-weight-bold' : '' ?>" href="./?page=credit_notes&type=<?= $k ?>"><?= $l ?></a></li>
        <?php endforeach; ?>
    </ul>

    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead><tr><th>Note</th><th>Date</th><th>Against</th><th>Party</th><th>Reason</th><th class="text-right">Net</th><th class="text-right">Tax</th><th class="text-right">Total</th><th>Status</th><th class="text-right">To refund</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($notes as $n): $void = $n['status'] === 'void'; ?>
                <tr class="<?= $void ? 'text-muted' : '' ?>">
                    <td class="font-weight-bold"><?= htmlspecialchars($n['note_no']) ?></td>
                    <td style="white-space:nowrap"><?= date('j M Y', strtotime($n['note_date'])) ?></td>
                    <td><?= $n['note_type'] === 'credit' ? 'Invoice ' : 'Bill ' ?><?= htmlspecialchars((string)$n['doc_no']) ?></td>
                    <td><?= htmlspecialchars((string)$n['party']) ?></td>
                    <td><?= htmlspecialchars(mb_strimwidth((string)$n['reason'], 0, 50, '…')) ?>
                        <?php foreach ($svc->lines((int)$n['id']) as $l): ?><br><small class="text-muted"><?= htmlspecialchars($l['description']) ?> × <?= rtrim(rtrim(number_format((float)$l['qty'], 4, '.', ''), '0'), '.') ?> @ <?= $fmt($l['unit_price']) ?><?= (float)$l['tax_rate'] > 0 ? ' +' . rtrim(rtrim(number_format((float)$l['tax_rate'], 4, '.', ''), '0'), '.') . '%' : '' ?></small><?php endforeach; ?></td>
                    <td class="text-right"><?= $fmt($n['subtotal']) ?></td>
                    <td class="text-right"><?= $fmt($n['tax_amount']) ?></td>
                    <td class="text-right font-weight-bold"><?= $fmt($n['total']) ?><?= !empty($n['currency']) ? '<br><small class="text-muted">' . htmlspecialchars($n['currency']) . ' ' . $fmt($n['total_fc']) . '</small>' : '' ?></td>
                    <td><span class="badge badge-<?= $void ? 'secondary' : 'success' ?>"><?= $void ? 'Void' : 'Issued' ?></span></td>
                    <td class="text-right"><?= $n['refundable'] > 0 ? '<b class="text-danger">' . $fmt($n['refundable']) . '</b>' : '—' ?></td>
                    <td class="text-right" style="white-space:nowrap"><?php if ($n['refundable'] > 0 && ($n['note_type'] === 'credit' ? $_settings->can_access('invoices_edit') : $_settings->can_access('purchases_edit'))): ?><button class="btn btn-xs btn-success refund-note mr-1" data-id="<?= (int)$n['id'] ?>" data-no="<?= htmlspecialchars($n['note_no'], ENT_QUOTES) ?>" data-max="<?= number_format((float)$n['refundable'], 2, '.', '') ?>" data-type="<?= $n['note_type'] ?>">Refund</button><?php endif; ?><?php if (!$void && ($n['note_type'] === 'credit' ? $_settings->can_access('invoices_edit') : $_settings->can_access('purchases_edit'))): ?><button class="btn btn-xs btn-outline-danger void-note" data-id="<?= (int)$n['id'] ?>" data-no="<?= htmlspecialchars($n['note_no'], ENT_QUOTES) ?>">Void</button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$notes): ?><tr><td colspan="11" class="text-center text-muted py-4">No notes yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>

    <?php if ($refunds): ?>
    <div class="card shadow-sm mt-3"><div class="card-header font-weight-bold">Refunds</div><div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Refund</th><th>Date</th><th>Note</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($refunds as $r): $rv = $r['status'] === 'void'; ?>
                <tr class="<?= $rv ? 'text-muted' : '' ?>">
                    <td class="font-weight-bold"><?= htmlspecialchars($r['refund_no']) ?></td>
                    <td><?= date('j M Y', strtotime($r['refund_date'])) ?></td>
                    <td><?= htmlspecialchars($r['note_no']) ?> <small class="text-muted">(<?= $r['note_type'] === 'credit' ? 'paid to customer' : 'received from supplier' ?>)</small></td>
                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['method']))) ?></td>
                    <td><?= htmlspecialchars((string)$r['reference']) ?></td>
                    <td class="text-right"><?= $fmt($r['amount']) ?></td>
                    <td><span class="badge badge-<?= $rv ? 'secondary' : 'success' ?>"><?= $rv ? 'Void' : 'Issued' ?></span></td>
                    <td class="text-right"><?php if (!$rv && ($r['note_type'] === 'credit' ? $_settings->can_access('invoices_edit') : $_settings->can_access('purchases_edit'))): ?><button class="btn btn-xs btn-outline-danger void-refund" data-id="<?= (int)$r['id'] ?>" data-no="<?= htmlspecialchars($r['refund_no'], ENT_QUOTES) ?>">Void</button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modal_refund" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title font-weight-bold" id="refund_title">Refund</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <form id="form_refund">
            <input type="hidden" name="note_id" id="refund_note_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-6"><label class="font-weight-bold">Date</label><input type="date" name="refund_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group col-6"><label class="font-weight-bold">Amount</label><input type="number" name="amount" id="refund_amount" class="form-control text-right" step="0.01" min="0.01" required><small class="text-muted" id="refund_hint"></small></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6"><label class="font-weight-bold" id="refund_bank_label">Paid from</label>
                        <select name="bank_account_id" class="form-control"><option value="">Default bank / cash account</option>
                        <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group col-6"><label class="font-weight-bold">Method</label>
                        <select name="method" class="form-control"><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option><option value="cash">Cash</option><option value="card">Card</option></select></div>
                </div>
                <div class="form-group"><label class="font-weight-bold">Reference</label><input type="text" name="reference" class="form-control" maxlength="100"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-success font-weight-bold">Record refund</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="modal_note" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title font-weight-bold" id="note_title">Credit note</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <form id="form_note">
            <input type="hidden" name="note_type" id="note_type" value="credit">
            <div class="modal-body">
                <div class="form-group"><label class="font-weight-bold" id="doc_label">Invoice</label>
                    <select name="document_id" id="document_id" class="form-control" required></select>
                    <small class="text-muted" id="doc_hint"></small></div>
                <div class="form-group"><label class="font-weight-bold">Date</label><input type="date" name="note_date" class="form-control" value="<?= date('Y-m-d') ?>" required style="max-width:200px"></div>
                <table class="table table-sm mb-1" id="note_lines">
                    <thead><tr><th>What is being credited</th><th width="14%" class="text-right">Qty</th><th width="20%" class="text-right">Price</th><th width="14%" class="text-right">Tax %</th><th width="6%"></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-xs btn-outline-secondary mb-2" id="add_line"><i class="fas fa-plus"></i> Add line</button>
                <div class="text-right text-sm mb-2">Before tax <b id="note_net">0.00</b> · Tax <b id="note_tax">0.00</b> · Total <b id="note_total">0.00</b></div>
                <div class="form-group"><label class="font-weight-bold">Reason</label><input type="text" name="reason" class="form-control" maxlength="500" required placeholder="Goods returned, pricing error…"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-primary font-weight-bold">Raise note</button></div>
        </form>
    </div></div>
</div>
<script>
$(function () {
    var docs = <?= json_encode($docs, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var post = function (f, data) {
        start_loader();
        $.ajax({ url: _base_url_ + 'classes/Master.php?f=' + f, method: 'POST', data: data, dataType: 'json',
            error: function () { alert_toast('An error occurred.', 'error'); end_loader(); },
            success: function (r) {
                if (r && r.status === 'success') { location.reload(); }
                else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
            } });
    };
    var addLine = function (rate) {
        var tr = $('<tr>').append(
            $('<td>').append($('<input>', { type: 'text', name: 'line_desc[]', 'class': 'form-control form-control-sm', maxlength: 250, placeholder: 'e.g. Returned goods' })),
            $('<td>').append($('<input>', { type: 'number', name: 'line_qty[]', 'class': 'form-control form-control-sm text-right ln', step: 'any', min: '0', value: '1' })),
            $('<td>').append($('<input>', { type: 'number', name: 'line_price[]', 'class': 'form-control form-control-sm text-right ln', step: '0.01', min: '0' })),
            $('<td>').append($('<input>', { type: 'number', name: 'line_tax[]', 'class': 'form-control form-control-sm text-right ln', step: 'any', min: '0', max: '100', value: rate || 0 })),
            $('<td>').append($('<button>', { type: 'button', 'class': 'btn btn-xs btn-link text-danger rm-line', html: '&times;' }))
        );
        $('#note_lines tbody').append(tr);
    };
    var total = function () {
        var net = 0, tax = 0;
        $('#note_lines tbody tr').each(function () {
            var q = parseFloat($(this).find('[name="line_qty[]"]').val()) || 0,
                p = parseFloat($(this).find('[name="line_price[]"]').val()) || 0,
                r = parseFloat($(this).find('[name="line_tax[]"]').val()) || 0,
                n = Math.round(q * p * 100) / 100;
            net += n; tax += Math.round(n * r) / 100;
        });
        $('#note_net').text(net.toFixed(2)); $('#note_tax').text(tax.toFixed(2)); $('#note_total').text((net + tax).toFixed(2));
    };
    var pick = function () {
        var d = (docs[$('#note_type').val()] || []).find(function (x) { return String(x.id) === $('#document_id').val(); });
        if (d) {
            $('#doc_hint').text('Can still be credited: ' + (d.currency ? d.currency + ' ' : '') + Number(d.outstanding).toFixed(2) + (d.currency ? ' — enter this note in ' + d.currency + '; it is recorded at the invoice rate.' : ''));
            $('#note_lines tbody tr:first [name="line_tax[]"]').val(d.tax_rate);
        }
        total();
    };
    $('#add_line').on('click', function () { var d = (docs[$('#note_type').val()] || []).find(function (x) { return String(x.id) === $('#document_id').val(); }); addLine(d ? d.tax_rate : 0); });
    $('#note_lines').on('input', '.ln', total).on('click', '.rm-line', function () { if ($('#note_lines tbody tr').length > 1) { $(this).closest('tr').remove(); total(); } });
    $('.refund-note').on('click', function () {
        var t = $(this).data('type');
        $('#refund_note_id').val($(this).data('id'));
        $('#refund_title').text('Refund on ' + $(this).data('no'));
        $('#refund_bank_label').text(t === 'credit' ? 'Paid from' : 'Received into');
        $('#refund_amount').val($(this).data('max')).attr('max', $(this).data('max'));
        $('#refund_hint').text('Up to ' + $(this).data('max'));
        $('#modal_refund').modal('show');
    });
    $('#form_refund').on('submit', function (e) { e.preventDefault(); post('refund_credit_note', $(this).serialize()); });
    $('.void-refund').on('click', function () {
        if (window.confirm('Void refund ' + $(this).data('no') + '? The money is owed again.')) { post('void_note_refund', { id: $(this).data('id') }); }
    });
    $('.new-note').on('click', function () {
        var type = $(this).data('type'), list = docs[type] || [];
        $('#note_type').val(type);
        $('#note_title').text(type === 'credit' ? 'New credit note' : 'New debit note');
        $('#doc_label').text(type === 'credit' ? 'Invoice' : 'Bill');
        var sel = $('#document_id').empty();
        list.forEach(function (d) { sel.append($('<option>').val(d.id).text(d.doc_no + ' — ' + (d.party || '') + ' — can credit ' + (d.currency ? d.currency + ' ' : '') + Number(d.outstanding).toFixed(2))); });
        if (!list.length) { sel.append($('<option>').val('').text('Nothing outstanding to credit')); }
        $('#note_lines tbody').empty(); addLine(0);
        pick();
        $('#modal_note').modal('show');
    });
    $('#document_id').on('change', pick);
        $('#form_note').on('submit', function (e) { e.preventDefault(); post('issue_credit_note', $(this).serialize()); });
    $('.void-note').on('click', function () {
        var id = $(this).data('id'), no = $(this).data('no');
        if (window.confirm('Void ' + no + '? Its ledger entry is reversed and the amount is owed again.')) { post('void_credit_note', { id: id }); }
    });
});
</script>
