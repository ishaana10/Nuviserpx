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
        Tax is corrected along with the amount, and the note is limited to what is still owed on the document.</p>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (['all' => 'All', 'credit' => 'Credit notes', 'debit' => 'Debit notes'] as $k => $l): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active font-weight-bold' : '' ?>" href="./?page=credit_notes&type=<?= $k ?>"><?= $l ?></a></li>
        <?php endforeach; ?>
    </ul>

    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead><tr><th>Note</th><th>Date</th><th>Against</th><th>Party</th><th>Reason</th><th class="text-right">Net</th><th class="text-right">Tax</th><th class="text-right">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($notes as $n): $void = $n['status'] === 'void'; ?>
                <tr class="<?= $void ? 'text-muted' : '' ?>">
                    <td class="font-weight-bold"><?= htmlspecialchars($n['note_no']) ?></td>
                    <td style="white-space:nowrap"><?= date('j M Y', strtotime($n['note_date'])) ?></td>
                    <td><?= $n['note_type'] === 'credit' ? 'Invoice ' : 'Bill ' ?><?= htmlspecialchars((string)$n['doc_no']) ?></td>
                    <td><?= htmlspecialchars((string)$n['party']) ?></td>
                    <td><?= htmlspecialchars(mb_strimwidth((string)$n['reason'], 0, 50, '…')) ?></td>
                    <td class="text-right"><?= $fmt($n['subtotal']) ?></td>
                    <td class="text-right"><?= $fmt($n['tax_amount']) ?></td>
                    <td class="text-right font-weight-bold"><?= $fmt($n['total']) ?></td>
                    <td><span class="badge badge-<?= $void ? 'secondary' : 'success' ?>"><?= $void ? 'Void' : 'Issued' ?></span></td>
                    <td class="text-right"><?php if (!$void && ($n['note_type'] === 'credit' ? $_settings->can_access('invoices_edit') : $_settings->can_access('purchases_edit'))): ?><button class="btn btn-xs btn-outline-danger void-note" data-id="<?= (int)$n['id'] ?>" data-no="<?= htmlspecialchars($n['note_no'], ENT_QUOTES) ?>">Void</button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$notes): ?><tr><td colspan="10" class="text-center text-muted py-4">No notes yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<div class="modal fade" id="modal_note" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title font-weight-bold" id="note_title">Credit note</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <form id="form_note">
            <input type="hidden" name="note_type" id="note_type" value="credit">
            <div class="modal-body">
                <div class="form-group"><label class="font-weight-bold" id="doc_label">Invoice</label>
                    <select name="document_id" id="document_id" class="form-control" required></select>
                    <small class="text-muted" id="doc_hint"></small></div>
                <div class="form-row">
                    <div class="form-group col-4"><label class="font-weight-bold">Date</label><input type="date" name="note_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group col-4"><label class="font-weight-bold">Amount before tax</label><input type="number" name="amount" id="note_amount" class="form-control text-right" step="0.01" min="0.01" required></div>
                    <div class="form-group col-4"><label class="font-weight-bold">Tax rate %</label><input type="number" name="tax_rate" id="note_rate" class="form-control text-right" step="any" min="0" max="100" value="0"></div>
                </div>
                <div class="text-right text-sm mb-2">Total: <b id="note_total">0.00</b></div>
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
    var total = function () {
        var t = (parseFloat($('#note_amount').val()) || 0) * (1 + (parseFloat($('#note_rate').val()) || 0) / 100);
        $('#note_total').text(t.toFixed(2));
    };
    var pick = function () {
        var d = (docs[$('#note_type').val()] || []).find(function (x) { return String(x.id) === $('#document_id').val(); });
        if (d) {
            $('#note_rate').val(d.tax_rate);
            $('#doc_hint').text('Still owed: ' + Number(d.outstanding).toFixed(2));
            $('#note_amount').attr('max', (d.outstanding / (1 + d.tax_rate / 100)).toFixed(2));
        }
        total();
    };
    $('.new-note').on('click', function () {
        var type = $(this).data('type'), list = docs[type] || [];
        $('#note_type').val(type);
        $('#note_title').text(type === 'credit' ? 'New credit note' : 'New debit note');
        $('#doc_label').text(type === 'credit' ? 'Invoice' : 'Bill');
        var sel = $('#document_id').empty();
        list.forEach(function (d) { sel.append($('<option>').val(d.id).text(d.doc_no + ' — ' + (d.party || '') + ' — owed ' + Number(d.outstanding).toFixed(2))); });
        if (!list.length) { sel.append($('<option>').val('').text('Nothing outstanding to credit')); }
        pick();
        $('#modal_note').modal('show');
    });
    $('#document_id').on('change', pick);
    $('#note_amount, #note_rate').on('input', total);
    $('#form_note').on('submit', function (e) { e.preventDefault(); post('issue_credit_note', $(this).serialize()); });
    $('.void-note').on('click', function () {
        var id = $(this).data('id'), no = $(this).data('no');
        if (window.confirm('Void ' + no + '? Its ledger entry is reversed and the amount is owed again.')) { post('void_credit_note', { id: id }); }
    });
});
</script>
