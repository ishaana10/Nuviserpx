<?php
/**
 * Fixed-asset depreciation: the register as at a date, what a run would post, and disposals.
 */
$svc = new \App\Services\AssetService($conn, $_settings);
$tenant_id = (int)$_settings->active_tenant_id();
$asAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['as_at'] ?? '')) ? \App\Services\DepreciationCalc::monthEnd($_GET['as_at']) : \App\Services\DepreciationCalc::monthEnd(date('Y-m-d'));
$register = $svc->register($asAt);
$plan = $svc->plan($asAt);
$names = array_column($register, 'asset_name', 'id');
$can_edit = $_settings->can_access('assets_edit');
$fmt = fn ($n) => number_format((float)$n, 2);

$accounts = [];
$q = $conn->prepare("SELECT id, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 ORDER BY name");
$q->bind_param('i', $tenant_id);
$q->execute();
foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
    $accounts[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'class' => \App\Services\LedgerService::classify($a['type'])['class']];
}
$tot = ['cost' => 0.0, 'acc' => 0.0, 'nbv' => 0.0];
foreach ($register as $r) {
    if ($r['status'] === 'registered') {
        $tot['cost'] += (float)$r['purchase_price']; $tot['acc'] += $r['accumulated']; $tot['nbv'] += $r['carrying'];
    }
}
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
        <h3 class="font-weight-bold text-navy m-0"><i class="fas fa-chart-line mr-2"></i>Depreciation</h3>
        <a href="./?page=assets" class="btn btn-sm btn-default">Back to assets</a>
    </div>
    <p class="text-muted text-sm">Depreciation is charged monthly: Dr Depreciation, Cr Accumulated Depreciation, one journal a month. An asset’s cost reaches the ledger through the bill or journal that bought it.</p>

    <form method="get" class="form-inline mb-3">
        <input type="hidden" name="page" value="assets/depreciation">
        <label class="mr-2 font-weight-bold">As at</label>
        <input type="date" name="as_at" class="form-control form-control-sm mr-2" value="<?= htmlspecialchars($asAt) ?>">
        <button class="btn btn-sm btn-outline-secondary">Show</button>
    </form>

    <?php if ($plan): ?>
    <div class="card shadow-sm mb-3 border-warning">
        <div class="card-header font-weight-bold">To post through <?= date('F Y', strtotime($asAt)) ?></div>
        <div class="card-body">
            <table class="table table-sm mb-3">
                <thead><tr><th>Month</th><th>Assets</th><th class="text-right">Depreciation</th></tr></thead>
                <tbody>
                <?php foreach ($plan as $m): ?>
                    <tr><td><?= date('F Y', strtotime($m['period_end'])) ?></td><td><?= count($m['charges']) ?></td><td class="text-right"><?= $fmt($m['total']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($can_edit): ?><button class="btn btn-primary btn-sm font-weight-bold" id="btn_run" data-through="<?= htmlspecialchars($asAt) ?>">Post depreciation</button><?php endif; ?>
            <span class="text-muted text-sm ml-2">Months are posted oldest first. A locked period stops the run at that month.</span>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-light border text-sm">Depreciation is up to date to <?= date('F Y', strtotime($asAt)) ?>.</div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header font-weight-bold">Register as at <?= date('j M Y', strtotime($asAt)) ?></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Asset</th><th>Method</th><th>Started</th><th class="text-right">Cost</th><th class="text-right">Accumulated</th><th class="text-right">Carrying value</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($register as $r): $disposed = $r['status'] === 'disposed'; ?>
                    <tr class="<?= $disposed ? 'text-muted' : '' ?>">
                        <td><a href="./?page=assets/view_asset&id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['asset_number']) ?></a> <?= htmlspecialchars($r['asset_name']) ?></td>
                        <td><?= htmlspecialchars($r['dep_method'] ?: '—') ?></td>
                        <td><?= $r['dep_start_date'] ? date('j M Y', strtotime($r['dep_start_date'])) : '—' ?></td>
                        <td class="text-right"><?= $fmt($r['purchase_price']) ?></td>
                        <td class="text-right"><?= $fmt($r['accumulated']) ?></td>
                        <td class="text-right font-weight-bold"><?= $disposed ? '—' : $fmt($r['carrying']) ?></td>
                        <td><?= $disposed ? 'Disposed ' . date('j M Y', strtotime($r['disposal_date'])) : 'Registered' ?></td>
                        <td class="text-right"><?php if (!$disposed && $can_edit): ?><button class="btn btn-xs btn-outline-danger dispose-btn" data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['asset_number'] . ' ' . $r['asset_name'], ENT_QUOTES) ?>">Dispose</button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$register): ?><tr><td colspan="8" class="text-center text-muted py-4">No registered assets yet. Register an asset to depreciate it.</td></tr><?php endif; ?>
                </tbody>
                <?php if ($register): ?>
                <tfoot><tr class="font-weight-bold"><td colspan="3">Registered assets</td><td class="text-right"><?= $fmt($tot['cost']) ?></td><td class="text-right"><?= $fmt($tot['acc']) ?></td><td class="text-right"><?= $fmt($tot['nbv']) ?></td><td colspan="2"></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal_dispose" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title font-weight-bold">Dispose of asset</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <form id="form_dispose">
            <input type="hidden" name="id" id="d_id">
            <div class="modal-body">
                <p class="font-weight-bold" id="d_name"></p>
                <div class="form-row">
                    <div class="form-group col-6"><label class="font-weight-bold">Disposal date</label><input type="date" name="disposal_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group col-6"><label class="font-weight-bold">Proceeds</label><input type="number" name="proceeds" class="form-control text-right" step="0.01" min="0" value="0.00"></div>
                </div>
                <div class="form-group"><label class="font-weight-bold">Account the asset’s cost is held in</label>
                    <select name="cost_account_id" class="form-control" required><option value="">Choose…</option>
                    <?php foreach ($accounts as $a) { if ($a['class'] === 'asset') echo '<option value="' . $a['id'] . '">' . htmlspecialchars($a['name']) . '</option>'; } ?>
                    </select></div>
                <div class="form-group"><label class="font-weight-bold">Proceeds paid into</label>
                    <select name="proceeds_account_id" class="form-control"><option value="">Not applicable (scrapped)</option>
                    <?php foreach ($accounts as $a) { if ($a['class'] === 'asset') echo '<option value="' . $a['id'] . '">' . htmlspecialchars($a['name']) . '</option>'; } ?>
                    </select></div>
                <p class="text-xs text-muted mb-0">Depreciation must be posted to the end of the previous month first. No depreciation is charged for the month of disposal. The gain or loss goes to “Gain/Loss on Disposal of Assets”.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-danger font-weight-bold">Dispose</button></div>
        </form>
    </div></div>
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
    $('#btn_run').on('click', function () { post('run_depreciation', { through: $(this).data('through') }); });
    $('.dispose-btn').on('click', function () { $('#d_id').val($(this).data('id')); $('#d_name').text($(this).data('name')); $('#modal_dispose').modal('show'); });
    $('#form_dispose').on('submit', function (e) { e.preventDefault(); post('dispose_asset', $(this).serialize()); });
});
</script>
