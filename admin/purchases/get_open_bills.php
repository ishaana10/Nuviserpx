<?php
require_once(__DIR__ . '/../../config.php');

if (!$_settings->can_access('purchases_view')) {
    echo "<div class='alert alert-danger'>You do not have access to purchases.</div>";
    exit;
}
$vendor_id = (int)($_POST['vendor_id'] ?? 0);
if (!$vendor_id) {
    echo "<div class='alert alert-warning'>Choose a vendor.</div>";
    exit;
}
$paySvc = new \App\Services\BillPaymentService($conn, $_settings);
$bills = $paySvc->openBills($vendor_id);
$whtRate = $paySvc->vendorRate($vendor_id);
if (!$bills) {
    echo "<div class='alert alert-info'><i class='fas fa-info-circle'></i> This vendor has no bills waiting to be paid.</div>";
    exit;
}
?>
<div class="table-responsive">
    <table class="table table-bordered table-sm table-striped">
        <thead class="bg-light"><tr><th>Bill</th><th>Bill date</th><th>Due</th><th class="text-right">Total</th><th class="text-right">Still owed</th><th class="text-right" width="170">Pay now</th><th width="70"></th></tr></thead>
        <tbody>
        <?php foreach ($bills as $b): $overdue = $b['due_date'] && strtotime($b['due_date']) < strtotime(date('Y-m-d')); ?>
            <tr>
                <td><b><?= htmlspecialchars($b['bill_no']) ?></b></td>
                <td><?= date('d M Y', strtotime($b['bill_date'])) ?></td>
                <td><?= $b['due_date'] ? date('d M Y', strtotime($b['due_date'])) : '—' ?> <?= $overdue ? '<span class="badge badge-danger">OVERDUE</span>' : '' ?></td>
                <td class="text-right"><?= number_format((float)$b['total'], 2) ?></td>
                <td class="text-right font-weight-bold text-danger"><?= number_format((float)$b['owed'], 2) ?></td>
                <td><input type="number" step="0.01" min="0" max="<?= number_format((float)$b['owed'], 2, '.', '') ?>" name="alloc[<?= (int)$b['id'] ?>]" class="form-control form-control-sm text-right alloc-amt" value="0.00"></td>
                <td class="text-center"><button type="button" class="btn btn-xs btn-outline-success pay-full" data-owed="<?= number_format((float)$b['owed'], 2, '.', '') ?>">Full</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="text-right">Total payment</th><th class="text-right" id="alloc-total">0.00</th><th></th></tr></tfoot>
    </table>
</div>
<div class="row justify-content-end mb-2">
    <div class="col-md-2 form-group mb-1"><label class="text-xs">Withholding tax %</label><input type="number" step="0.01" min="0" max="50" id="wht_rate_ui" class="form-control form-control-sm text-right" value="<?= number_format($whtRate, 2, '.', '') ?>"></div>
    <div class="col-md-2 form-group mb-1"><label class="text-xs">Tax withheld</label><input type="number" step="0.01" min="0" name="wht_amount" id="wht_amount" class="form-control form-control-sm text-right" value="0.00"></div>
    <div class="col-md-2 form-group mb-1"><label class="text-xs">Paid from bank</label><input type="text" id="net_pay" class="form-control form-control-sm text-right font-weight-bold" value="0.00" readonly></div>
</div>
<script>
$(function () {
    function net() { var g = parseFloat($('#bp_amount').val()) || 0, w = parseFloat($('#wht_amount').val()) || 0; $('#net_pay').val(Math.max(0, g - w).toFixed(2)); }
    function rateChanged() { var g = parseFloat($('#bp_amount').val()) || 0, r = parseFloat($('#wht_rate_ui').val()) || 0; $('#wht_amount').val((Math.round(g * r) / 100).toFixed(2)); net(); }
    $('#wht_rate_ui').on('input change', rateChanged);
    $('#wht_amount').on('input change', net);
    $(document).on('input change click', '.alloc-amt, .pay-full', function () { setTimeout(rateChanged, 0); });
});
</script>
<script>
$(function () {
    function total() {
        var t = 0;
        $('.alloc-amt').each(function () { t += parseFloat($(this).val()) || 0; });
        $('#alloc-total').text(t.toFixed(2));
        $('#bp_amount').val(t.toFixed(2));
    }
    $('.alloc-amt').on('input change', total);
    $('.pay-full').on('click', function () { $(this).closest('tr').find('.alloc-amt').val($(this).data('owed')); total(); });
});
</script>
