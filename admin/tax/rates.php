<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();

// Default tax rates list
$default_tax_rates = [
    ['id' => 1, 'name' => 'Sales Tax on Imports', 'rate' => 0.00, 'accounts_count' => 0],
    ['id' => 2, 'name' => 'Tax Exempt', 'rate' => 0.00, 'accounts_count' => 33],
    ['id' => 3, 'name' => 'Tax on Purchases', 'rate' => 15.00, 'accounts_count' => 19],
    ['id' => 4, 'name' => 'Tax on Sales (FRCS VAT)', 'rate' => 15.00, 'accounts_count' => 24],
    ['id' => 5, 'name' => 'Zero Rated (0%)', 'rate' => 0.00, 'accounts_count' => 5],
];
?>

<style>
.tax-banner {
    background-color: #22c55e;
    color: #ffffff;
    padding: 10px 16px;
    border-radius: 6px 6px 0 0;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.tax-banner-body {
    background-color: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-top: none;
    padding: 16px;
    border-radius: 0 0 6px 6px;
    color: #166534;
    font-size: 0.88rem;
    margin-bottom: 20px;
}

.tax-table th {
    background-color: #f8fafc;
    color: #334155;
    font-size: 0.85rem;
    font-weight: 700;
    padding: 10px 12px;
    border-bottom: 2px solid #cbd5e1;
}

.tax-table td {
    padding: 10px 12px;
    vertical-align: middle;
    font-size: 0.9rem;
}
</style>

<div class="container-fluid py-3">
    <!-- Breadcrumb -->
    <div class="text-xs text-muted mb-1">
        <span>Settings</span> &gt; <strong class="text-dark">Tax rates</strong>
    </div>
    <h3 class="font-weight-bold text-navy mb-3">Tax rates</h3>

    <!-- Getting Started Guide Banner -->
    <div id="guide-banner">
        <div class="tax-banner">
            <span>Getting Started with Tax Rates</span>
            <button type="button" class="btn btn-xs text-white p-0" onclick="$('#guide-banner').fadeOut()">
                Hide Getting Started <i class="fas fa-times ml-1"></i>
            </button>
        </div>
        <div class="tax-banner-body">
            <p class="mb-2">
                Every transaction line item you enter into Nuvis ERP needs a Tax Rate. The Tax Rates you use will depend on the location of your organisation. Tax Rates can have multiple Tax Components. For instance, you can have an item that is charged a Tax Rate called "City Import Tax (8%)" that has two components: a city tax of 5% and an import tax of 3%.
            </p>
            <p class="mb-2">
                You can generate reports on any Tax Rate and Tax Components. If you need to report on income separately to expenses, or capital purchases separately to expenses, then you should create a separate Tax Rate for each.
            </p>
            <a href="javascript:void(0)" class="font-weight-bold text-success text-decoration-underline" onclick="alert('Tax Rates allow automated VAT output/input logging on invoices and bills according to FRCS regulations.')">
                • View more help for Tax Rates
            </a>
        </div>
    </div>

    <!-- Action Toolbar -->
    <div class="d-flex align-items-center gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-primary bg-gradient-primary font-weight-bold shadow-sm" id="btn_new_tax_rate">
            <i class="fas fa-plus mr-1"></i> New Tax Rate
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger font-weight-bold" id="btn_delete_selected" disabled>
            Delete
        </button>
        <span class="text-muted text-xs ml-2" id="selected_count_label">No items selected</span>
    </div>

    <!-- Tax Rates Listing Table -->
    <div class="card border shadow-sm rounded-lg overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover tax-table mb-0">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">
                            <input type="checkbox" id="check_all_tax">
                        </th>
                        <th>Name <i class="fas fa-caret-up text-primary ml-1"></i></th>
                        <th width="15%">Tax Rate</th>
                        <th width="25%">Accounts using this Tax Rate</th>
                        <th width="10%" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($default_tax_rates as $tr): ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="tax-row-check">
                        </td>
                        <td>
                            <a href="javascript:void(0)" class="font-weight-bold text-primary edit-rate-trigger" data-id="<?= $tr['id'] ?>" data-name="<?= htmlspecialchars($tr['name']) ?>" data-rate="<?= $tr['rate'] ?>">
                                <i class="fas fa-lock text-muted mr-2 text-xs"></i> <?= htmlspecialchars($tr['name']) ?>
                            </a>
                        </td>
                        <td class="font-weight-bold text-navy"><?= number_format($tr['rate'], 0) ?>%</td>
                        <td>
                            <a href="./?page=accounts" class="text-info font-weight-semibold">
                                <?= $tr['accounts_count'] ?> accounts
                            </a>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-xs btn-outline-primary edit-rate-trigger" data-id="<?= $tr['id'] ?>" data-name="<?= htmlspecialchars($tr['name']) ?>" data-rate="<?= $tr['rate'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL: EDIT / NEW TAX RATE -->
<div class="modal fade" id="modal_edit_tax_rate" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 10px;">
            <div class="modal-header bg-white border-bottom py-3">
                <h5 class="modal-title font-weight-bold text-dark m-0" id="taxModalTitle">Edit Tax Rate</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="form_tax_rate">
                <div class="modal-body p-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark text-sm mb-1">Tax Rate Display Name</label>
                        <input type="text" name="tax_name" id="tax_name" class="form-control" style="border-radius: 6px;" value="Sales Tax on Imports" required>
                    </div>

                    <label class="font-weight-bold text-dark text-sm mb-2">Tax Components</label>
                    <div class="row align-items-center mb-3">
                        <div class="col-8">
                            <input type="text" name="component_name" id="component_name" class="form-control" style="border-radius: 6px;" value="VAT / TAX" required>
                        </div>
                        <div class="col-4 d-flex align-items-center">
                            <input type="number" step="any" name="component_rate" id="component_rate" class="form-control text-right mr-1" style="border-radius: 6px;" value="15.00" required>
                            <span class="font-weight-bold text-muted">%</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top mt-4">
                        <h6 class="font-weight-bold text-dark m-0">Total tax rate:</h6>
                        <h4 class="font-weight-bold text-navy m-0" id="lbl_total_tax_rate">15.00%</h4>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 px-4 d-flex justify-content-end">
                    <button type="button" class="btn btn-sm btn-secondary mr-2" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-info bg-gradient-info font-weight-bold px-4">OK</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    // Checkbox selection toggle
    $('#check_all_tax').change(function(){
        $('.tax-row-check').prop('checked', this.checked);
        updateSelectionState();
    });

    $(document).on('change', '.tax-row-check', function(){
        updateSelectionState();
    });

    function updateSelectionState(){
        var count = $('.tax-row-check:checked').length;
        if(count > 0){
            $('#btn_delete_selected').prop('disabled', false);
            $('#selected_count_label').text(count + ' item(s) selected');
        } else {
            $('#btn_delete_selected').prop('disabled', true);
            $('#selected_count_label').text('No items selected');
        }
    }

    // Modal Trigger
    $('#btn_new_tax_rate').click(function(){
        $('#taxModalTitle').text('Create New Tax Rate');
        $('#tax_name').val('');
        $('#component_rate').val('15.00');
        $('#lbl_total_tax_rate').text('15.00%');
        $('#modal_edit_tax_rate').modal('show');
    });

    $(document).on('click', '.edit-rate-trigger', function(){
        var name = $(this).data('name');
        var rate = parseFloat($(this).data('rate')) || 0;
        $('#taxModalTitle').text('Edit Tax Rate - ' + name);
        $('#tax_name').val(name);
        $('#component_rate').val(rate.toFixed(2));
        $('#lbl_total_tax_rate').text(rate.toFixed(2) + '%');
        $('#modal_edit_tax_rate').modal('show');
    });

    $('#component_rate').on('input change', function(){
        var val = parseFloat($(this).val()) || 0;
        $('#lbl_total_tax_rate').text(val.toFixed(2) + '%');
    });

    $('#form_tax_rate').submit(function(e){
        e.preventDefault();
        alert_toast("Tax rate saved successfully.", 'success');
        $('#modal_edit_tax_rate').modal('hide');
    });
});
</script>
