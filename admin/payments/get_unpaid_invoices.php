<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();
$party_id = isset($_POST['party_id']) ? (int)$_POST['party_id'] : 0;
$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$preset_invoice_id = isset($_POST['preset_invoice_id']) ? (int)$_POST['preset_invoice_id'] : 0;

if(!$party_id){
    echo "<div class='alert alert-warning'>Please select a customer.</div>";
    exit;
}

// Get existing allocations if editing payment
$existing_alloc = [];
if($payment_id > 0){
    $alloc_qry = $conn->query("SELECT invoice_id, amount FROM `payment_allocations` WHERE payment_id = '{$payment_id}'");
    while($row = $alloc_qry->fetch_assoc()){
        $existing_alloc[$row['invoice_id']] = (float)$row['amount'];
    }
}

// Get unpaid or partially paid invoices for this customer, plus any invoice already allocated in this payment
$invoices = $conn->query("
    SELECT * FROM `invoice_list`
    WHERE tenant_id = '{$tenant_id}' AND party_id = '{$party_id}' AND status != 'cancelled'
    AND (status IN ('unpaid', 'partially_paid') OR id IN (SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$payment_id}'))
    ORDER BY invoice_date ASC
");

if($invoices->num_rows == 0){
    echo "<div class='alert alert-info'><i class='fas fa-info-circle'></i> No open unpaid invoices found for this customer.</div>";
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-bordered table-sm table-striped" id="allocation-table">
        <thead class="bg-light">
            <tr>
                <th>Invoice No</th>
                <th>Invoice Date</th>
                <th>Due Date</th>
                <th class="text-right">Total Amount</th>
                <th class="text-right">Current Balance Due</th>
                <th class="text-right" width="180">Amount to Allocate</th>
                <th class="text-center" width="80">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            while($inv = $invoices->fetch_assoc()):
                $inv_id = $inv['id'];
                $curr_alloc = isset($existing_alloc[$inv_id]) ? $existing_alloc[$inv_id] : 0;
                $balance_due = (float)$inv['balance'] + $curr_alloc;
                $default_amt = $curr_alloc > 0 ? $curr_alloc : (($preset_invoice_id == $inv_id) ? $balance_due : 0);
            ?>
            <tr>
                <td>
                    <strong>#<?= htmlspecialchars($inv['invoice_no']) ?></strong>
                </td>
                <td><?= date('d M Y', strtotime($inv['invoice_date'])) ?></td>
                <td><?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></td>
                <td class="text-right">$<?= number_format($inv['total'], 2) ?></td>
                <td class="text-right font-weight-bold text-danger">$<?= number_format($balance_due, 2) ?></td>
                <td>
                    <input type="number" step="any" name="invoice_alloc[<?= $inv_id ?>]" class="form-control form-control-sm text-right font-weight-bold alloc-amt" data-balance="<?= $balance_due ?>" value="<?= $default_amt > 0 ? number_format($default_amt, 2, '.', '') : '0.00' ?>">
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-success btn-pay-full" title="Pay Full Balance">Full</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
    $(document).ready(function(){
        $('.btn-pay-full').click(function(){
            var row = $(this).closest('tr');
            var bal = row.find('.alloc-amt').attr('data-balance');
            row.find('.alloc-amt').val(parseFloat(bal).toFixed(2));
            update_payment_amount_total();
        });

        $('.alloc-amt').on('input change', function(){
            update_payment_amount_total();
        });

        function update_payment_amount_total(){
            var total_alloc = 0;
            $('.alloc-amt').each(function(){
                total_alloc += parseFloat($(this).val()) || 0;
            });
            if(total_alloc > 0 && ($('#amount').val() == 0 || $('#amount').val() == '0.00')){
                $('#amount').val(total_alloc.toFixed(2));
            }
        }
    });
</script>