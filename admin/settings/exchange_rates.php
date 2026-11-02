<?php
/**
 * Exchange rates and foreign-currency revaluation.
 * Rates are base-currency units per one unit of the foreign currency, kept by date.
 */
$fx = new \App\Services\FxService($conn, $_settings);
$base = $fx->baseCurrency();
$foreign = $fx->foreignCurrencies();
$history = $fx->history(60);
$asAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['as_at'] ?? '')) ? $_GET['as_at'] : date('Y-m-d');
$preview = $fx->preview($asAt);
$names = array_column($foreign, 'name', 'code');
$fmt = fn ($n) => number_format((float)$n, 2);
?>
<div class="container-fluid py-3">
    <div class="text-xs text-muted mb-1"><span>Settings</span> &gt; <strong class="text-dark">Exchange rates &amp; revaluation</strong></div>
    <h3 class="font-weight-bold text-navy mb-1">Exchange rates &amp; revaluation</h3>
    <p class="text-muted text-sm mb-3">
        Base currency is <b><?= htmlspecialchars($base) ?></b>. Enter each rate as how much <?= htmlspecialchars($base) ?> one unit of the foreign currency is worth on that day.
        Add currencies under <a href="./?page=settings/currencies">Currencies &amp; Rates</a>.
    </p>

    <div class="row">
        <div class="col-lg-5 mb-3">
            <div class="card shadow-sm"><div class="card-header font-weight-bold">Add a rate</div>
                <form id="form_rate" class="card-body">
                    <div class="form-group"><label class="font-weight-bold">Currency</label>
                        <select name="currency_code" class="form-control" required>
                            <?php foreach ($foreign as $c): ?><option value="<?= htmlspecialchars($c['code']) ?>"><?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-row">
                        <div class="form-group col-6"><label class="font-weight-bold">Date</label>
                            <input type="date" name="rate_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="form-group col-6"><label class="font-weight-bold">1 unit = <?= htmlspecialchars($base) ?></label>
                            <input type="number" name="rate" class="form-control text-right" step="any" min="0" placeholder="0.00000000" required></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm font-weight-bold" <?= $foreign ? '' : 'disabled' ?>>Save rate</button>
                    <?php if (!$foreign): ?><span class="text-muted text-sm ml-2">No foreign currencies yet.</span><?php endif; ?>
                </form>
            </div>
            <div class="card shadow-sm mt-3"><div class="card-header font-weight-bold">Recent rates</div>
                <div class="table-responsive" style="max-height:340px">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Currency</th><th class="text-right">Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr><td><?= date('j M Y', strtotime($h['rate_date'])) ?></td><td><?= htmlspecialchars($h['currency_code']) ?></td><td class="text-right"><?= rtrim(rtrim(number_format((float)$h['rate'], 8), '0'), '.') ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?><tr><td colspan="3" class="text-muted text-center py-3">No rates entered yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-7 mb-3">
            <div class="card shadow-sm"><div class="card-header font-weight-bold">Revalue foreign-currency balances</div>
                <div class="card-body">
                    <form method="get" class="form-inline mb-3">
                        <input type="hidden" name="page" value="settings/exchange_rates">
                        <label class="mr-2 font-weight-bold">As at</label>
                        <input type="date" name="as_at" class="form-control form-control-sm mr-2" value="<?= htmlspecialchars($asAt) ?>">
                        <button class="btn btn-sm btn-outline-secondary">Preview</button>
                    </form>
                    <?php if ($preview['missing']): ?>
                        <div class="alert alert-warning text-sm">No rate on or before <?= htmlspecialchars($asAt) ?> for <b><?= htmlspecialchars(implode(', ', $preview['missing'])) ?></b>. Add one to include those balances.</div>
                    <?php endif; ?>
                    <?php if ($preview['rows']): ?>
                        <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Account</th><th>Cur.</th><th class="text-right">Foreign balance</th><th class="text-right">Rate</th><th class="text-right">Carried (<?= htmlspecialchars($base) ?>)</th><th class="text-right">Revalued</th><th class="text-right">Gain / (loss)</th></tr></thead>
                            <tbody>
                            <?php $net = 0.0; foreach ($preview['rows'] as $r): $net += $r['adjustment']; ?>
                                <tr><td><?= htmlspecialchars($r['account']) ?></td><td><?= htmlspecialchars($r['currency']) ?></td>
                                    <td class="text-right"><?= $fmt($r['fc_net']) ?></td><td class="text-right"><?= rtrim(rtrim(number_format($r['rate'], 8), '0'), '.') ?></td>
                                    <td class="text-right"><?= $fmt($r['carrying']) ?></td><td class="text-right"><?= $fmt($r['revalued']) ?></td>
                                    <td class="text-right <?= $r['adjustment'] < 0 ? 'text-danger' : '' ?>"><?= $fmt($r['adjustment']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <p class="text-sm text-muted">Posts one journal dated <?= date('j M Y', strtotime($asAt)) ?>; the other side goes to <b><?= htmlspecialchars(\App\Services\FxService::FX_ACCOUNT) ?></b>.
                            Running it again for the same date posts nothing new.</p>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold" id="btn_reval" data-as-at="<?= htmlspecialchars($asAt) ?>">Post revaluation</button>
                    <?php elseif (!$preview['missing']): ?>
                        <div class="text-muted text-center py-4">Nothing to revalue at <?= date('j M Y', strtotime($asAt)) ?>. Foreign-currency balances are already at the latest rates, or there are none.</div>
                    <?php endif; ?>
                </div>
            </div>
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
    $('#form_rate').on('submit', function (e) { e.preventDefault(); post('save_exchange_rate', $(this).serialize()); });
    $('#btn_reval').on('click', function () { post('post_fx_revaluation', { as_at: $(this).data('as-at') }); });
});
</script>
