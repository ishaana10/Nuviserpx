<?php
require_once(__DIR__ . '/../../config.php');

if ($_settings->userdata('type') != 1) {
    echo '<div class="alert alert-danger font-weight-bold my-4"><i class="fas fa-exclamation-triangle mr-2"></i> Access Denied: Developer module is restricted to Super Administrators.</div>';
    exit;
}

$tenant_id = $_settings->active_tenant_id();

// Fetch active customers
$customers_qry = $conn->query("SELECT id, name, email FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND `type` = 'customer' AND delete_flag = 0 ORDER BY name ASC");

$selected_customer_id = $_GET['customer_id'] ?? null;
if (!$selected_customer_id && $customers_qry && $customers_qry->num_rows > 0) {
    $first = $customers_qry->fetch_assoc();
    $selected_customer_id = $first['id'];
    $customers_qry->data_seek(0);
}

// Simulate session for preview if customer selected
if ($selected_customer_id) {
    $cust = $conn->query("SELECT * FROM `party_list` WHERE id = '{$selected_customer_id}'")->fetch_assoc();
    if ($cust) {
        $_SESSION['customer_client_id'] = $cust['id'];
        $_SESSION['customer_name'] = $cust['name'];
        $_SESSION['customer_email'] = $cust['email'];
        $_SESSION['customer_id'] = 99999; // Developer preview session flag
    }
}
?>

<div class="container-fluid py-3">
    <!-- Top Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="font-weight-bold text-navy m-0">
                <i class="fas fa-laptop-code text-warning mr-2"></i> Customer Portal Live Inspector & Developer Customizer
            </h3>
            <small class="text-muted">Select a customer to preview their portal experience and customize portal display controls in real-time.</small>
        </div>
        <div>
            <span class="badge badge-warning px-3 py-2 font-weight-bold shadow-sm">
                <i class="fas fa-user-shield mr-1"></i> Developer Mode
            </span>
        </div>
    </div>

    <div class="row">
        <!-- Left Sidebar: Customer Switcher & Quick Customizer -->
        <div class="col-lg-4 col-md-5 mb-3">
            <!-- Customer Switcher -->
            <div class="card card-outline card-primary shadow-sm mb-3">
                <div class="card-header bg-light py-2">
                    <h6 class="card-title font-weight-bold text-primary m-0"><i class="fas fa-users mr-1"></i> Select Customer Contact</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="customer-select-frm">
                        <input type="hidden" name="page" value="developer/portal_preview">
                        <div class="form-group mb-2">
                            <label class="text-xs text-muted text-uppercase font-weight-bold">Active Customer:</label>
                            <select name="customer_id" id="customer_id" class="form-control select2" onchange="this.form.submit()">
                                <?php if ($customers_qry && $customers_qry->num_rows > 0): ?>
                                    <?php while ($c = $customers_qry->fetch_assoc()): ?>
                                        <option value="<?php echo $c['id'] ?>" <?php echo $selected_customer_id == $c['id'] ? 'selected' : '' ?>>
                                            <?php echo htmlspecialchars($c['name']) ?> (<?php echo htmlspecialchars($c['email'] ?: 'No Email') ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <option value="">No Customers Found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Settings Form -->
            <div class="card card-outline card-info shadow-sm">
                <div class="card-header bg-light py-2">
                    <h6 class="card-title font-weight-bold text-info m-0"><i class="fas fa-sliders-h mr-1"></i> Quick Display Customizer</h6>
                </div>
                <div class="card-body">
                    <form id="quick-portal-frm">
                        <div class="form-group mb-3">
                            <label class="text-xs text-muted font-weight-bold mb-1">Welcome Message:</label>
                            <input type="text" class="form-control form-control-sm" name="portal_welcome_msg" value="<?php echo htmlspecialchars($_settings->info('portal_welcome_msg') ?: 'Welcome to your customer portal. View your account statement and invoices below.') ?>">
                        </div>

                        <div class="form-group mb-3">
                            <label class="text-xs text-muted font-weight-bold mb-1">Header Theme Color:</label>
                            <input type="color" class="form-control form-control-sm" style="height: 34px;" name="portal_primary_color" value="<?php echo htmlspecialchars($_settings->info('portal_primary_color') ?: '#0284c7') ?>">
                        </div>

                        <div class="form-group mb-3">
                            <label class="text-xs text-muted font-weight-bold mb-1">Announcement Notice Banner:</label>
                            <textarea name="portal_announcement" class="form-control form-control-sm" rows="2" placeholder="Optional notice banner"><?php echo htmlspecialchars($_settings->info('portal_announcement')) ?></textarea>
                        </div>

                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="quick_show_invoices" name="portal_show_invoices_check" value="1" <?php echo $_settings->info('portal_show_invoices') !== '0' ? 'checked' : '' ?> onchange="$('#quick_portal_show_invoices').val(this.checked ? '1' : '0')">
                            <input type="hidden" name="portal_show_invoices" id="quick_portal_show_invoices" value="<?php echo $_settings->info('portal_show_invoices') !== '0' ? '1' : '0' ?>">
                            <label class="custom-control-label text-sm" for="quick_show_invoices">Allow Invoices View</label>
                        </div>

                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="quick_show_payments" name="portal_show_payments_check" value="1" <?php echo $_settings->info('portal_show_payments') !== '0' ? 'checked' : '' ?> onchange="$('#quick_portal_show_payments').val(this.checked ? '1' : '0')">
                            <input type="hidden" name="portal_show_payments" id="quick_portal_show_payments" value="<?php echo $_settings->info('portal_show_payments') !== '0' ? '1' : '0' ?>">
                            <label class="custom-control-label text-sm" for="quick_show_payments">Allow Payments & Receipts View</label>
                        </div>

                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="quick_show_kpi" name="portal_show_kpi_cards_check" value="1" <?php echo $_settings->info('portal_show_kpi_cards') !== '0' ? 'checked' : '' ?> onchange="$('#quick_portal_show_kpi').val(this.checked ? '1' : '0')">
                            <input type="hidden" name="portal_show_kpi_cards" id="quick_portal_show_kpi" value="<?php echo $_settings->info('portal_show_kpi_cards') !== '0' ? '1' : '0' ?>">
                            <label class="custom-control-label text-sm" for="quick_show_kpi">Show KPI Summary Cards</label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold shadow-sm">
                            <i class="fas fa-save mr-1"></i> Save & Refresh Preview
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Side: Live Iframe Preview -->
        <div class="col-lg-8 col-md-7 mb-3">
            <div class="card card-outline card-dark shadow-sm h-100">
                <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="card-title font-weight-bold m-0"><i class="fas fa-desktop mr-1"></i> Live Customer Portal Outcome View</h6>
                    <div>
                        <button type="button" class="btn btn-xs btn-outline-light" onclick="document.getElementById('portal-preview-iframe').src += ''">
                            <i class="fas fa-sync-alt mr-1"></i> Reload Frame
                        </button>
                        <a href="<?php echo base_url ?>portal/index.php" target="_blank" class="btn btn-xs btn-outline-info ml-1">
                            <i class="fas fa-external-link-alt mr-1"></i> Open in New Tab
                        </a>
                    </div>
                </div>
                <div class="card-body p-0" style="min-height: 600px;">
                    <iframe id="portal-preview-iframe" src="<?php echo base_url ?>portal/index.php" style="width: 100%; height: 650px; border: none; border-radius: 0 0 8px 8px;"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#quick-portal-frm').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + 'classes/SystemSettings.php?f=update_settings',
            data: new FormData($(this)[0]),
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            type: 'POST',
            success: function(resp){
                end_loader();
                if(resp == 1){
                    alert_toast("Portal customization saved.", 'success');
                    document.getElementById('portal-preview-iframe').src = _base_url_ + 'portal/index.php?t=' + new Date().getTime();
                } else {
                    alert_toast("Error saving settings.", 'error');
                }
            }
        });
    });
});
</script>
