<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-calculator text-primary mr-2"></i> Financial & Accounting Settings</h3>
        <div class="card-tools">
            <button type="submit" form="financial-settings-form" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save Settings</button>
        </div>
    </div>
    <div class="card-body">
        <form action="" id="financial-settings-form">
            <div id="msg"></div>

            <!-- 1. Fiscal Year & Base Currency -->
            <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-calendar-alt text-secondary mr-2"></i> Financial Year & Base Currency</h5>
            <div class="row mb-4">
                <div class="col-md-4 form-group">
                    <label for="fiscal_year_end_month" class="font-weight-bold text-dark text-sm">Financial Year End Month</label>
                    <select name="fiscal_year_end_month" id="fiscal_year_end_month" class="form-control form-control-sm rounded-0">
                        <?php
                        $months = [
                            '12' => 'December (Standard Calendar Year)',
                            '06' => 'June (Australian Fiscal Year)',
                            '03' => 'March (UK / NZ Fiscal Year)',
                            '09' => 'September'
                        ];
                        $current_fy = $_settings->info('fiscal_year_end_month') ?: '12';
                        foreach($months as $code => $label):
                        ?>
                            <option value="<?= $code ?>" <?= $current_fy == $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label for="currency_code" class="font-weight-bold text-dark text-sm">Base Operating Currency</label>
                    <input type="text" name="currency_code" id="currency_code" class="form-control form-control-sm rounded-0 text-uppercase" value="<?= htmlspecialchars($_settings->info('currency_code') ?: '') ?>" placeholder="e.g. USD, AUD, EUR" required>
                </div>
                <div class="col-md-4 form-group">
                    <label for="currency_symbol" class="font-weight-bold text-dark text-sm">Currency Symbol</label>
                    <input type="text" name="currency_symbol" id="currency_symbol" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('currency_symbol') ?: '$') ?>" placeholder="e.g. $ or €" required>
                </div>
            </div>

            <!-- 2. Auto Sequence Prefix Configuration -->
            <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-list-ol text-secondary mr-2"></i> Document Auto-Numbering Prefixes</h5>
            <div class="row mb-4">
                <div class="col-md-3 form-group">
                    <label for="prefix_invoice" class="font-weight-bold text-dark text-sm">Sales Invoices Prefix</label>
                    <input type="text" name="prefix_invoice" id="prefix_invoice" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('prefix_invoice') ?: 'INV-') ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="prefix_bill" class="font-weight-bold text-dark text-sm">Bills & Expenses Prefix</label>
                    <input type="text" name="prefix_bill" id="prefix_bill" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('prefix_bill') ?: 'BILL-') ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="prefix_po" class="font-weight-bold text-dark text-sm">Purchase Orders Prefix</label>
                    <input type="text" name="prefix_po" id="prefix_po" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('prefix_po') ?: 'PO-') ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="prefix_quote" class="font-weight-bold text-dark text-sm">Quotes & Sales Orders Prefix</label>
                    <input type="text" name="prefix_quote" id="prefix_quote" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('prefix_quote') ?: 'QT-') ?>">
                </div>
            </div>

            <!-- 3. Default Payment Terms & Tax Registration -->
            <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-file-invoice-dollar text-secondary mr-2"></i> Payment Terms & Tax Registration</h5>
            <div class="row mb-3">
                <div class="col-md-4 form-group">
                    <label for="default_payment_terms" class="font-weight-bold text-dark text-sm">Default Invoice Payment Terms</label>
                    <select name="default_payment_terms" id="default_payment_terms" class="form-control form-control-sm rounded-0">
                        <?php
                        $terms = [
                            'due_on_receipt' => 'Due on Receipt',
                            'net_7' => 'Net 7 Days',
                            'net_14' => 'Net 14 Days',
                            'net_30' => 'Net 30 Days (Standard)',
                            'net_60' => 'Net 60 Days'
                        ];
                        $cur_term = $_settings->info('default_payment_terms') ?: 'net_30';
                        foreach($terms as $val => $lbl):
                        ?>
                            <option value="<?= $val ?>" <?= $cur_term == $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label for="company_tin" class="font-weight-bold text-dark text-sm">Tax Registration / TIN Number</label>
                    <input type="text" name="company_tin" id="company_tin" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('company_tin') ?: $_settings->info('vms_tin')) ?>" placeholder="e.g. 50-12345-0-0">
                </div>
                <div class="col-md-4 form-group">
                    <label for="default_vat_rate" class="font-weight-bold text-dark text-sm">Default VAT / Sales Tax Rate (%)</label>
                    <input type="number" step="0.01" name="default_vat_rate" id="default_vat_rate" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('default_vat_rate') ?: '15.00') ?>" placeholder="15.00">
                </div>
            </div>
        </form>
    </div>
</div>

<?php
$pl_tenant = (int)$_settings->active_tenant_id();
$pl_current = \App\Services\PeriodLockService::lockDate($conn, $pl_tenant);
$pl_history = [];
$pl_q = @$conn->prepare("SELECT p.lock_date, p.note, p.date_created, CONCAT(u.firstname, ' ', u.lastname) who
    FROM period_locks p LEFT JOIN users u ON u.id = p.locked_by WHERE p.tenant_id = ? ORDER BY p.id DESC LIMIT 5");
if ($pl_q) {
    $pl_q->bind_param('i', $pl_tenant);
    $pl_q->execute();
    $pl_history = $pl_q->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<div class="card card-outline card-warning shadow-sm rounded-0 mt-3">
    <div class="card-header">
        <h3 class="card-title font-weight-bold"><i class="fas fa-lock text-warning mr-2"></i> Close the books</h3>
    </div>
    <div class="card-body">
        <p class="text-muted text-sm mb-3">
            Nothing dated on or before the lock date can be created, changed or removed — in journals, invoices, payments, payroll, bills or through the API.
            Currently: <b><?= $pl_current ? 'closed up to ' . date('j M Y', strtotime($pl_current)) : 'open (no lock)' ?></b>.
        </p>
        <form id="period-lock-form" class="form-inline" style="gap:8px">
            <label class="font-weight-bold text-sm mr-2" for="lock_date">Close up to</label>
            <input type="date" name="lock_date" id="lock_date" class="form-control form-control-sm rounded-0" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string)$pl_current) ?>">
            <input type="text" name="note" class="form-control form-control-sm rounded-0" maxlength="255" placeholder="Note (e.g. FY2026 audited)" style="min-width:260px">
            <button type="submit" class="btn btn-sm btn-warning btn-flat font-weight-bold"><i class="fas fa-lock mr-1"></i> Apply</button>
        </form>
        <small class="text-muted d-block mt-2">Leave the date empty to reopen every period. Every change is recorded below and in the audit log.</small>
        <?php if ($pl_history): ?>
        <table class="table table-sm table-bordered mt-3 mb-0">
            <thead><tr><th>Closed up to</th><th>Note</th><th>By</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($pl_history as $h): ?>
                <tr>
                    <td><?= $h['lock_date'] ? htmlspecialchars(date('j M Y', strtotime($h['lock_date']))) : '<i class="text-muted">reopened</i>' ?></td>
                    <td><?= htmlspecialchars((string)$h['note']) ?></td>
                    <td><?= htmlspecialchars(trim((string)$h['who']) ?: '—') ?></td>
                    <td><?= htmlspecialchars(date('j M Y H:i', strtotime($h['date_created']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#period-lock-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.post(_base_url_ + "classes/Master.php?f=set_period_lock", $(this).serialize(), null, "json").done(function(resp){
            if (resp && resp.status === 'success') { location.reload(); return; }
            alert_toast((resp && resp.msg) || "Could not change the lock.", "error");
            end_loader();
        }).fail(function(){ alert_toast("Could not change the lock.", "error"); end_loader(); });
    });
});
</script>

<script>
$(document).ready(function(){
    $('#financial-settings-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/SystemSettings.php?f=update_settings",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred saving financial settings.", "error");
                end_loader();
            },
            success: function(resp){
                alert_toast("Financial settings successfully saved.", "success");
                end_loader();
            }
        });
    });
});
</script>
