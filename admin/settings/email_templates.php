<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-envelope-open-text text-primary mr-2"></i> Outgoing Email & Notification Templates</h3>
        <div class="card-tools">
            <button type="submit" form="email-templates-form" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save Templates</button>
        </div>
    </div>
    <div class="card-body">
        <form action="" id="email-templates-form">
            <div class="alert alert-info border-info text-sm mb-4">
                <i class="fas fa-info-circle mr-2"></i> Available Placeholders:
                <code>{customer_name}</code>, <code>{invoice_number}</code>, <code>{payment_number}</code>, <code>{total_amount}</code>, <code>{due_date}</code>, <code>{portal_link}</code>, <code>{company_name}</code>
            </div>

            <!-- TABBED TEMPLATES -->
            <ul class="nav nav-tabs mb-4" id="tplTab" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active font-weight-bold" id="inv-tab" data-toggle="tab" href="#tpl-invoice"><i class="fas fa-file-invoice text-primary mr-1"></i> Sales Invoice</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link font-weight-bold" id="rcp-tab" data-toggle="tab" href="#tpl-receipt"><i class="fas fa-receipt text-success mr-1"></i> Payment Receipt</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link font-weight-bold" id="prt-tab" data-toggle="tab" href="#tpl-portal"><i class="fas fa-user-shield text-warning mr-1"></i> Portal Invitation</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link font-weight-bold" id="pay-tab" data-toggle="tab" href="#tpl-payslip"><i class="fas fa-calculator text-info mr-1"></i> Employee Payslip</a>
                </li>
            </ul>

            <div class="tab-content" id="tplTabContent">
                <!-- 1. INVOICE TPL -->
                <div class="tab-pane fade show active" id="tpl-invoice" role="tabpanel">
                    <div class="form-group mb-3">
                        <label for="email_tpl_invoice_subject" class="font-weight-bold text-dark text-sm">Invoice Subject Line</label>
                        <input type="text" name="email_tpl_invoice_subject" id="email_tpl_invoice_subject" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('email_tpl_invoice_subject') ?: 'Tax Invoice {invoice_number} from {company_name}') ?>">
                    </div>
                    <div class="form-group mb-0">
                        <label for="email_tpl_invoice_body" class="font-weight-bold text-dark text-sm">Invoice Body Message</label>
                        <textarea name="email_tpl_invoice_body" id="email_tpl_invoice_body" rows="6" class="form-control form-control-sm rounded-0"><?= htmlspecialchars($_settings->info('email_tpl_invoice_body') ?: "Dear {customer_name},\n\nPlease find attached Tax Invoice {invoice_number} for total amount {total_amount}, due on {due_date}.\n\nThank you for your business!\n\nBest regards,\n{company_name}") ?></textarea>
                    </div>
                </div>

                <!-- 2. RECEIPT TPL -->
                <div class="tab-pane fade" id="tpl-receipt" role="tabpanel">
                    <div class="form-group mb-3">
                        <label for="email_tpl_receipt_subject" class="font-weight-bold text-dark text-sm">Receipt Subject Line</label>
                        <input type="text" name="email_tpl_receipt_subject" id="email_tpl_receipt_subject" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('email_tpl_receipt_subject') ?: 'Payment Receipt {payment_number} from {company_name}') ?>">
                    </div>
                    <div class="form-group mb-0">
                        <label for="email_tpl_receipt_body" class="font-weight-bold text-dark text-sm">Receipt Body Message</label>
                        <textarea name="email_tpl_receipt_body" id="email_tpl_receipt_body" rows="6" class="form-control form-control-sm rounded-0"><?= htmlspecialchars($_settings->info('email_tpl_receipt_body') ?: "Dear {customer_name},\n\nWe have successfully received your payment of {total_amount} (Receipt #{payment_number}).\n\nThank you for prompt payment!\n\nBest regards,\n{company_name}") ?></textarea>
                    </div>
                </div>

                <!-- 3. PORTAL TPL -->
                <div class="tab-pane fade" id="tpl-portal" role="tabpanel">
                    <div class="form-group mb-3">
                        <label for="email_tpl_portal_subject" class="font-weight-bold text-dark text-sm">Invitation Subject Line</label>
                        <input type="text" name="email_tpl_portal_subject" id="email_tpl_portal_subject" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('email_tpl_portal_subject') ?: 'Customer Portal Invitation - {company_name}') ?>">
                    </div>
                    <div class="form-group mb-0">
                        <label for="email_tpl_portal_body" class="font-weight-bold text-dark text-sm">Invitation Body Message</label>
                        <textarea name="email_tpl_portal_body" id="email_tpl_portal_body" rows="6" class="form-control form-control-sm rounded-0"><?= htmlspecialchars($_settings->info('email_tpl_portal_body') ?: "Dear {customer_name},\n\nYou are invited to access your account statement and invoices online via our Customer Portal.\n\nPlease click the link below to set your password:\n{portal_link}\n\nBest regards,\n{company_name}") ?></textarea>
                    </div>
                </div>

                <!-- 4. PAYSLIP TPL -->
                <div class="tab-pane fade" id="tpl-payslip" role="tabpanel">
                    <div class="form-group mb-3">
                        <label for="email_tpl_payslip_subject" class="font-weight-bold text-dark text-sm">Payslip Subject Line</label>
                        <input type="text" name="email_tpl_payslip_subject" id="email_tpl_payslip_subject" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('email_tpl_payslip_subject') ?: 'Employee Payslip Notice - {company_name}') ?>">
                    </div>
                    <div class="form-group mb-0">
                        <label for="email_tpl_payslip_body" class="font-weight-bold text-dark text-sm">Payslip Body Message</label>
                        <textarea name="email_tpl_payslip_body" id="email_tpl_payslip_body" rows="6" class="form-control form-control-sm rounded-0"><?= htmlspecialchars($_settings->info('email_tpl_payslip_body') ?: "Dear {customer_name},\n\nPlease find attached your employee payslip detailing basic salary, FNPF, PAYE tax, and net pay for the current pay cycle.\n\nBest regards,\nPayroll Department - {company_name}") ?></textarea>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#email-templates-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/SystemSettings.php?f=update_settings",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred saving email templates.", "error");
                end_loader();
            },
            success: function(resp){
                alert_toast("Email notification templates successfully saved.", "success");
                end_loader();
            }
        });
    });
});
</script>
