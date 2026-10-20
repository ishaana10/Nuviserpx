<?php
require_once(__DIR__ . '/../../config.php');
?>
<style>
.bank-modal-header {
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 12px;
    margin-bottom: 20px;
}
.bank-modal-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
}
.form-label-bank {
    font-size: 0.88rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
</style>

<div class="container-fluid">
    <div class="bank-modal-header">
        <h4 class="bank-modal-title">Add account details</h4>
    </div>
    <form action="" id="bank-account-form">
        <div class="form-group mb-3">
            <label for="bank_name" class="form-label-bank">Bank name</label>
            <input type="text" id="bank_name" name="bank_name" class="form-control form-control-sm rounded-0" placeholder="e.g. ANZ, Westpac, BSP, Bank of Baroda" required>
        </div>

        <div class="form-group mb-1">
            <label for="account_name" class="form-label-bank">Account name</label>
            <input type="text" id="account_name" name="account_name" class="form-control form-control-sm rounded-0" placeholder="e.g. Business Account" required>
            <small class="text-muted d-block mt-1">A unique name for this account</small>
        </div>

        <div class="form-group mb-3 mt-3">
            <label for="account_type" class="form-label-bank">Account type</label>
            <select id="account_type" name="account_type" class="form-control form-control-sm rounded-0 select2" required>
                <option value="" disabled selected>Select account type</option>
                <option value="Checking">Everyday (Day-to-day transaction account)</option>
                <option value="Savings">Savings (High interest savings account)</option>
                <option value="Credit Card">Credit Card</option>
                <option value="Other">Other Bank Account</option>
            </select>
        </div>

        <div class="form-group mb-3">
            <label for="account_number" class="form-label-bank">Account number / BSB</label>
            <input type="text" id="account_number" name="account_number" class="form-control form-control-sm rounded-0" placeholder="e.g. 1234-56789-00">
        </div>

        <div class="form-group mb-4">
            <label for="currency" class="form-label-bank">Currency</label>
            <select id="currency" name="currency" class="form-control form-control-sm rounded-0 select2" required>
                <option value="FJD" selected>FJD Fijian Dollar</option>
                <option value="AUD">AUD Australian Dollar</option>
                <option value="NZD">NZD New Zealand Dollar</option>
                <option value="USD">USD US Dollar</option>
                <option value="EUR">EUR Euro</option>
                <option value="GBP">GBP Great British Pound</option>
            </select>
        </div>

        <div class="text-center my-3">
            <a href="https://nuvistechnologies.com.fj" target="_blank" class="text-primary font-weight-bold" style="font-size: 0.88rem;">
                Learn about adding accounts <i class="fas fa-external-link-alt text-xs ml-1"></i>
            </a>
        </div>
    </form>
</div>

<script>
$(document).ready(function(){
    $('#uni_modal').on('shown.bs.modal', function(){
        $('.select2').select2({
            dropdownParent: $('#uni_modal')
        });
    });

    $('#uni_modal #bank-account-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_bank_account",
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
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to add bank account.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
