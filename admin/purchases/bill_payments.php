<?php
$svc = new \App\Services\BillPaymentService($conn, $_settings);
$tenant_id = $_settings->active_tenant_id();
$payments = $svc->payments();
$can_pay = $_settings->can_access('purchases_edit');
$can_void = $_settings->can_access('purchases_delete');
$vendors = $conn->query("SELECT id, party_code, name FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
$fx_codes = array_map(fn($c) => strtoupper($c['code']), (new \App\Services\FxService($conn, $_settings))->foreignCurrencies());
$accounts = $conn->query("SELECT id, name, currency FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
$preset_vendor = (int)($_GET['vendor_id'] ?? 0);
?>
<div class="container-fluid py-2">
    <h3 class="font-weight-bold text-navy mb-1">Supplier payments</h3>
    <p class="text-muted text-sm mb-3">Pay one vendor's open bills. The payment is posted to Accounts Payable and the bank, and the bills show as paid or part-paid. Void a payment to undo it.</p>

    <?php if ($can_pay): ?>
    <div class="card card-outline card-success mb-4">
        <div class="card-header font-weight-bold">New payment</div>
        <form id="bp-form" class="card-body">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label>Vendor</label>
                    <select name="vendor_id" id="bp_vendor" class="form-control form-control-sm" required>
                        <option value="">Choose a vendor…</option>
                        <?php while ($v = $vendors->fetch_assoc()): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= $preset_vendor === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['party_code'] . ' - ' . $v['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2 form-group"><label>Date</label><input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-3 form-group">
                    <label>Paid from</label>
                    <select name="bank_account_id" class="form-control form-control-sm">
                        <option value="">Default bank / cash account</option>
                        <?php while ($a = $accounts->fetch_assoc()): ?><option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?><?= in_array(strtoupper((string)$a['currency']), $fx_codes, true) ? ' (' . htmlspecialchars(strtoupper($a['currency'])) . ')' : '' ?></option><?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label>Method</label>
                    <select name="method" class="form-control form-control-sm">
                        <option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option><option value="cash">Cash</option><option value="card">Card</option>
                    </select>
                </div>
            </div>
            <div id="bp-bills" class="mb-2"><p class="text-muted small">Choose a vendor to see the bills waiting to be paid.</p></div>
            <input type="hidden" name="amount" id="bp_amount" value="0.00">
            <div class="row">
                <div class="col-md-4 form-group"><label>Reference / cheque no.</label><input type="text" name="reference" class="form-control form-control-sm" maxlength="100"></div>
                <div class="col-md-8 form-group"><label>Notes</label><input type="text" name="notes" class="form-control form-control-sm" maxlength="500"></div>
            </div>
            <button class="btn btn-success btn-sm font-weight-bold" type="submit"><i class="fas fa-check"></i> Record payment</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header font-weight-bold">Payments made</div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Date</th><th>Payment</th><th>Vendor</th><th>Method</th><th>Bills</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): $al = $svc->allocationsFor((int)$p['id']); ?>
                    <tr class="<?= $p['status'] === 'void' ? 'text-muted' : '' ?>">
                        <td style="white-space:nowrap"><?= date('j M Y', strtotime($p['payment_date'])) ?></td>
                        <td class="font-weight-bold"><?= htmlspecialchars($p['payment_no']) ?><?= $p['reference'] ? '<br><small class="text-muted">' . htmlspecialchars($p['reference']) . '</small>' : '' ?></td>
                        <td><?= htmlspecialchars((string)$p['vendor']) ?></td>
                        <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['method']))) ?></td>
                        <td><?= htmlspecialchars(implode(', ', array_map(fn($a) => $a['bill_no'] . ' (' . number_format((float)$a['amount'], 2) . ')', $al))) ?></td>
                        <td class="text-right"><?= number_format((float)$p['amount'], 2) ?></td>
                        <td><span class="badge badge-<?= $p['status'] === 'void' ? 'dark' : ($p['status'] === 'pending_approval' ? 'warning' : 'success') ?>"><?= strtoupper(str_replace('_', ' ', $p['status'])) ?></span></td>
                        <td><?php if (in_array($p['status'], ['posted', 'pending_approval'], true) && $can_void): ?><button class="btn btn-xs btn-outline-danger void-bp" data-id="<?= (int)$p['id'] ?>">Void</button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?><tr><td colspan="8" class="text-center text-muted py-4">No supplier payments yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function () {
    function load() {
        var v = $('#bp_vendor').val();
        if (!v) { return; }
        $('#bp-bills').html('<div class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Loading bills…</div>');
        $.post(_base_url_ + 'admin/purchases/get_open_bills.php', { vendor_id: v }, function (h) { $('#bp-bills').html(h); });
    }
    $('#bp_vendor').on('change', load);
    load();
    $('#bp-form').on('submit', function (e) {
        e.preventDefault();
        start_loader();
        $.ajax({ url: _base_url_ + 'classes/Master.php?f=pay_vendor_bills', method: 'POST', data: $(this).serialize(), dataType: 'json',
            error: function () { alert_toast('An error occurred.', 'error'); end_loader(); },
            success: function (r) { if (r && r.status === 'success') { location.reload(); } else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); } } });
    });
    $('.void-bp').on('click', function () {
        var id = $(this).data('id');
        if (!window.confirm('Void this payment? Its bills will owe the amount again.')) { return; }
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=void_bill_payment', { id: id }, null, 'json').done(function (r) {
            if (r && r.status === 'success') { location.reload(); } else { alert_toast((r && r.msg) || 'An error occurred.', 'error'); end_loader(); }
        }).fail(function () { alert_toast('An error occurred.', 'error'); end_loader(); });
    });
});
</script>
