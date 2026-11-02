<?php
/**
 * Tax rates. Each rate is a named percentage; the default rate pre-fills new invoice and bill lines.
 * VAT collected and paid is posted to the VAT Output and VAT Input accounts automatically.
 */
require_once __DIR__ . '/../../config.php';

$taxSvc = new \App\Services\TaxService($conn, $_settings);
$codes = $taxSvc->codes();
$appliesLabel = ['both' => 'Sales and purchases', 'sale' => 'Sales only', 'purchase' => 'Purchases only'];
?>
<div class="container-fluid py-3">
    <div class="text-xs text-muted mb-1"><span>Settings</span> &gt; <strong class="text-dark">Tax rates</strong></div>
    <h3 class="font-weight-bold text-navy mb-1">Tax rates</h3>
    <p class="text-muted text-sm mb-3">
        VAT on invoices is posted to <b>VAT Output</b> (owed to the tax authority) and VAT on bills to <b>VAT Input</b> (reclaimable).
        The default rate fills new invoice and bill lines. Documents already posted keep the tax they were saved with.
    </p>

    <div class="mb-3">
        <button type="button" class="btn btn-sm btn-primary font-weight-bold" id="btn_new_tax_rate"><i class="fas fa-plus mr-1"></i> New tax rate</button>
    </div>

    <div class="card border shadow-sm rounded-lg overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Name</th><th width="12%" class="text-right">Rate</th><th width="22%">Used on</th><th width="12%">Default</th><th width="14%" class="text-center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($codes as $c): ?>
                    <tr>
                        <td class="font-weight-bold"><?= htmlspecialchars($c['name']) ?></td>
                        <td class="text-right"><?= rtrim(rtrim(number_format((float)$c['rate'], 4), '0'), '.') ?>%</td>
                        <td><?= $appliesLabel[$c['applies_to']] ?? '' ?></td>
                        <td><?= (int)$c['is_default'] ? '<span class="badge badge-success">Default</span>' : '' ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-xs btn-outline-primary edit-rate" data-id="<?= (int)$c['id'] ?>" data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>" data-rate="<?= htmlspecialchars((string)(float)$c['rate'], ENT_QUOTES) ?>" data-applies="<?= htmlspecialchars($c['applies_to'], ENT_QUOTES) ?>" data-default="<?= (int)$c['is_default'] ?>"><i class="fas fa-edit"></i></button>
                            <button type="button" class="btn btn-xs btn-outline-danger delete-rate" data-id="<?= (int)$c['id'] ?>" data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$codes): ?><tr><td colspan="5" class="text-muted text-center py-4">No tax rates yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal_tax_rate" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header"><h5 class="modal-title font-weight-bold" id="taxModalTitle">Tax rate</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
            <form id="form_tax_rate">
                <input type="hidden" name="id" id="tax_id" value="0">
                <div class="modal-body">
                    <div class="form-group"><label class="font-weight-bold">Name</label>
                        <input type="text" name="name" id="tax_name" class="form-control" required maxlength="150"></div>
                    <div class="form-row">
                        <div class="form-group col-5"><label class="font-weight-bold">Rate (%)</label>
                            <input type="number" name="rate" id="tax_rate" class="form-control text-right" step="any" min="0" max="100" required></div>
                        <div class="form-group col-7"><label class="font-weight-bold">Used on</label>
                            <select name="applies_to" id="tax_applies" class="form-control">
                                <option value="both">Sales and purchases</option><option value="sale">Sales only</option><option value="purchase">Purchases only</option>
                            </select></div>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" name="is_default" id="tax_default" value="1">
                        <label class="custom-control-label" for="tax_default">Use as the default for new invoice and bill lines</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary font-weight-bold px-4">Save</button></div>
            </form>
        </div>
    </div>
</div>

<script>
$(function () {
    var post = function (f, data) {
        start_loader();
        $.ajax({ url: _base_url_ + 'classes/Master.php?f=' + f, method: 'POST', data: data, dataType: 'json',
            error: function () { alert_toast('An error occurred.', 'error'); end_loader(); },
            success: function (r) {
                if (r && r.status === 'success') { location.reload(); }
                else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
            } });
    };
    $('#btn_new_tax_rate').on('click', function () {
        $('#taxModalTitle').text('New tax rate'); $('#tax_id').val(0); $('#tax_name').val(''); $('#tax_rate').val('');
        $('#tax_applies').val('both'); $('#tax_default').prop('checked', false); $('#modal_tax_rate').modal('show');
    });
    $('.edit-rate').on('click', function () {
        var d = $(this).data();
        $('#taxModalTitle').text('Edit tax rate'); $('#tax_id').val(d.id); $('#tax_name').val(d.name); $('#tax_rate').val(d.rate);
        $('#tax_applies').val(d.applies); $('#tax_default').prop('checked', Number(d['default']) === 1); $('#modal_tax_rate').modal('show');
    });
    $('#form_tax_rate').on('submit', function (e) { e.preventDefault(); post('save_tax_code', $(this).serialize()); });
    $('.delete-rate').on('click', function () {
        var d = $(this).data();
        _conf('Delete the tax rate “' + d.name + '”? Documents already saved are not changed.', 'delete_rate', [d.id]);
    });
    window.delete_rate = function (id) { post('delete_tax_code', { id: id }); };
});
</script>
