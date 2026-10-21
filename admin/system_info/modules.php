<?php
require_once(__DIR__ . '/../../config.php');

$modules = [
    ['key' => 'mod_accounting', 'name' => 'Accounting & Chart of Accounts', 'icon' => 'fas fa-university', 'desc' => 'General ledger, manual journals, double-entry vouchers, and trial balance.', 'default' => '1'],
    ['key' => 'mod_fixed_assets', 'name' => 'Fixed Assets Management', 'icon' => 'fas fa-boxes', 'desc' => 'Book depreciation, asset tracking, and depreciation schedules.', 'default' => '1'],
    ['key' => 'mod_sales_crm', 'name' => 'Sales & CRM Opportunities', 'icon' => 'fas fa-handshake', 'desc' => 'Deals pipeline, quotes, and customer proposal e-signatures.', 'default' => '1'],
    ['key' => 'mod_tickets', 'name' => 'Helpdesk & Support Tickets', 'icon' => 'fas fa-headset', 'desc' => 'Customer support ticket tracking and communication threads.', 'default' => '1'],
    ['key' => 'mod_contracts', 'name' => 'Contracts & Subscriptions', 'icon' => 'fas fa-file-contract', 'desc' => 'Service contracts, recurring billing, and MRR metrics.', 'default' => '1'],
    ['key' => 'mod_warehouses', 'name' => 'Multi-Warehouse Inventory', 'icon' => 'fas fa-warehouse', 'desc' => 'Multi-location inventory tracking and inter-warehouse transfers.', 'default' => '1'],
    ['key' => 'mod_expense_reports', 'name' => 'Employee Expense Claims', 'icon' => 'fas fa-receipt', 'desc' => 'Employee reimbursement claims with manager approval workflow.', 'default' => '1'],
    ['key' => 'mod_payroll', 'name' => 'Payroll & HR Suite', 'icon' => 'fas fa-users', 'desc' => 'Fiji tax compliance payroll, employee profiles, and attendance.', 'default' => '1']
];
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-th-large text-primary mr-2"></i> Module Activation & Feature Toggle Center</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">
            Dolibarr-inspired module control center. Enable or disable system modules based on your business operational needs.
        </p>

        <form id="modules-toggle-form">
            <div class="row">
                <?php foreach($modules as $m):
                    $is_active = $_settings->info($m['key']) !== '0';
                ?>
                <div class="col-md-6 mb-3">
                    <div class="card border shadow-none h-100">
                        <div class="card-body d-flex align-items-start justify-content-between">
                            <div>
                                <h5 class="font-weight-bold text-dark mb-1"><i class="<?= $m['icon'] ?> text-primary mr-2"></i> <?= $m['name'] ?></h5>
                                <p class="text-muted text-sm mb-0"><?= $m['desc'] ?></p>
                            </div>
                            <div class="custom-control custom-switch ml-3">
                                <input type="checkbox" class="custom-control-input" id="<?= $m['key'] ?>" name="<?= $m['key'] ?>" value="1" <?= $is_active ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="<?= $m['key'] ?>"></label>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="border-top pt-3 text-right">
                <button type="submit" class="btn btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save Module Configuration</button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#modules-toggle-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_module_settings",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    alert_toast("Module configuration saved.", "success");
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to save module settings.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
