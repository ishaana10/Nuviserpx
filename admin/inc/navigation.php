<?php
$sidenav_theme = $_settings->info('sidenav_theme') ? $_settings->info('sidenav_theme') : 'sidebar-light-primary';
$sidenav_mode = $_settings->info('sidenav_mode') ? $_settings->info('sidenav_mode') : 'accordion';
$sidenav_compact = $_settings->info('sidenav_compact') !== '0' ? 'nav-compact' : '';

$show_commercial = $_settings->info('sidenav_show_commercial') !== '0';
$show_payroll = $_settings->info('sidenav_show_payroll') !== '0';
$show_financial = $_settings->info('sidenav_show_financial') !== '0';
$show_maintenance = $_settings->info('sidenav_show_maintenance') !== '0';

$active_tenant = $_settings->active_tenant_id();
$tenant_name = 'Nuvis Enterprise';
$t_qry = $conn->query("SELECT name FROM tenants WHERE id = '{$active_tenant}'");
if($t_qry && $t_qry->num_rows > 0){
    $tenant_name = $t_qry->fetch_assoc()['name'];
}
?>

<style>
/* Xero-inspired Navigation Sidebar - High Contrast White Text */
.main-sidebar {
    background-color: #0f172a !important;
    border-right: 1px solid #1e293b;
}

.main-sidebar .brand-link {
    background-color: #0f172a !important;
    border-bottom: 1px solid #1e293b;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.tenant-avatar-badge {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background-color: #ef4444;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.tenant-title-text {
    font-size: 0.95rem;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.2;
}

.main-sidebar .nav-sidebar .nav-item > .nav-link {
    color: #ffffff !important;
    border-radius: 6px;
    margin: 3px 8px;
    font-weight: 500;
    font-size: 0.92rem;
    padding: 9px 12px;
    transition: all 0.15s ease;
}

.main-sidebar .nav-sidebar .nav-item > .nav-link:hover {
    background-color: rgba(255, 255, 255, 0.12) !important;
    color: #ffffff !important;
}

.main-sidebar .nav-sidebar .nav-item > .nav-link.active {
    background-color: #e2e8f0 !important;
    color: #0f172a !important;
    font-weight: 700;
}

.main-sidebar .nav-sidebar .nav-icon {
    color: rgba(255, 255, 255, 0.85) !important;
    width: 20px;
    margin-right: 10px;
    text-align: center;
}

.main-sidebar .nav-sidebar .nav-link.active .nav-icon {
    color: #2563eb !important;
}

.main-sidebar .nav-sidebar .right {
    color: rgba(255, 255, 255, 0.7) !important;
}

.main-sidebar .nav-sidebar .nav-link.active .right {
    color: #0f172a !important;
}

.main-sidebar .nav-treeview .nav-link {
    padding-left: 2.2rem !important;
    font-size: 0.88rem;
    color: rgba(255, 255, 255, 0.8) !important;
}

.main-sidebar .nav-treeview .nav-link:hover {
    color: #ffffff !important;
    background-color: rgba(255, 255, 255, 0.1) !important;
}

.main-sidebar .nav-treeview .nav-link.active {
    color: #2563eb !important;
    background-color: #ffffff !important;
    font-weight: 700;
}
</style>

<!-- Main Sidebar Container -->
<aside class="main-sidebar elevation-1 sidebar-no-expand">
    <!-- Brand / Tenant Active Badge Header -->
    <a href="<?php echo base_url ?>admin" class="brand-link">
        <div class="tenant-avatar-badge">
            <?php echo strtoupper(substr($tenant_name, 0, 1)) ?>
        </div>
        <div class="d-flex align-items-center justify-content-between w-100">
            <span class="tenant-title-text"><?php echo htmlspecialchars($tenant_name) ?></span>
            <i class="fas fa-chevron-right text-muted text-xs"></i>
        </div>
    </a>

    <!-- Sidebar Scroll Area -->
    <div class="sidebar os-host os-theme-light os-host-overflow os-host-overflow-y">
        <div class="os-padding">
            <div class="os-viewport os-viewport-native-scrollbars-invisible">
                <div class="os-content" style="padding: 0px; height: 100%; width: 100%;">

                    <!-- Xero-inspired Nav Menu Structure -->
                    <nav class="mt-2">
                        <ul class="nav nav-pills nav-sidebar flex-column text-sm <?= $sidenav_compact ?> nav-flat nav-child-indent" data-widget="treeview" role="menu" data-accordion="<?= $sidenav_mode == 'accordion' ? 'true' : 'false' ?>">

                            <!-- 1. Home -->
                            <li class="nav-item">
                                <a href="./" class="nav-link nav-home">
                                    <i class="nav-icon fas fa-home"></i>
                                    <p>Home</p>
                                </a>
                            </li>

                            <!-- 2. Sales -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-dollar-sign"></i>
                                    <p>
                                        Sales
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=invoices" class="nav-link nav-invoices tree-item">
                                            <i class="nav-icon fas fa-file-invoice"></i>
                                            <p>Sales Invoices</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=sales_orders" class="nav-link nav-sales_orders tree-item">
                                            <i class="nav-icon fas fa-file-signature"></i>
                                            <p>Quotes & Orders</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=crm" class="nav-link nav-crm tree-item">
                                            <i class="nav-icon fas fa-handshake"></i>
                                            <p>CRM Opportunities</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payments" class="nav-link nav-payments tree-item">
                                            <i class="nav-icon fas fa-receipt"></i>
                                            <p>Payment Receipts</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=products" class="nav-link nav-products tree-item">
                                            <i class="nav-icon fas fa-boxes"></i>
                                            <p>Products & Services</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <!-- 3. Purchases -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-shopping-bag"></i>
                                    <p>
                                        Purchases
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=invoices&type=bill" class="nav-link nav-bills tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Bills & Expenses</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=sales_orders&type=po" class="nav-link nav-purchase_orders tree-item">
                                            <i class="nav-icon fas fa-shopping-cart"></i>
                                            <p>Purchase Orders</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=stock_adjustments" class="nav-link nav-stock_adjustments tree-item">
                                            <i class="nav-icon fas fa-cubes"></i>
                                            <p>Stock Movements</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=warehouses" class="nav-link nav-warehouses tree-item">
                                            <i class="nav-icon fas fa-warehouse"></i>
                                            <p>Warehouses & Locations</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <!-- 4. Reporting -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-chart-line"></i>
                                    <p>
                                        Reporting
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports" class="nav-link nav-reports tree-item">
                                            <i class="nav-icon fas fa-th-large"></i>
                                            <p>All Reports Hub</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/general_ledger" class="nav-link nav-reports_general_ledger tree-item">
                                            <i class="nav-icon fas fa-book-open"></i>
                                            <p>General Ledger</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/balance_sheet" class="nav-link nav-reports_balance_sheet tree-item">
                                            <i class="nav-icon fas fa-balance-scale"></i>
                                            <p>Balance Sheet</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/income_statement" class="nav-link nav-reports_income_statement tree-item">
                                            <i class="nav-icon fas fa-chart-bar"></i>
                                            <p>Income Statement</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/ar_aging" class="nav-link nav-reports_ar_aging tree-item">
                                            <i class="nav-icon fas fa-history"></i>
                                            <p>AR Aging</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/payroll_accounting" class="nav-link nav-reports_payroll_accounting tree-item">
                                            <i class="nav-icon fas fa-calculator"></i>
                                            <p>Payroll Accounting</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <!-- 5. Accounting -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-university"></i>
                                    <p>
                                        Accounting
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=accounts" class="nav-link nav-accounts tree-item">
                                            <i class="nav-icon fas fa-table"></i>
                                            <p>Chart of Accounts</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=assets" class="nav-link nav-assets tree-item">
                                            <i class="nav-icon fas fa-boxes"></i>
                                            <p>Fixed Assets</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=groups" class="nav-link nav-groups tree-item">
                                            <i class="nav-icon fas fa-th-list"></i>
                                            <p>Account Groups</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=journals" class="nav-link nav-journals tree-item">
                                            <i class="nav-icon fas fa-book"></i>
                                            <p>Manual Journals</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <!-- 6. Tax -->
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-percentage"></i>
                                    <p>
                                        Tax
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tax/report" class="nav-link nav-tax_report tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Sales Tax Report</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tax/rates" class="nav-link nav-tax_rates tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>Tax Rates & Settings</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <!-- 7. Contacts -->
                            <li class="nav-item">
                                <a href="<?php echo base_url ?>admin/?page=parties" class="nav-link nav-parties">
                                    <i class="nav-icon fas fa-user-friends"></i>
                                    <p>Contacts</p>
                                </a>
                            </li>

                            <!-- 8. Payroll & HR -->
                            <?php if($show_payroll): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-users"></i>
                                    <p>
                                        Payroll & HR
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=employees" class="nav-link nav-employees tree-item">
                                            <i class="nav-icon fas fa-user-tie"></i>
                                            <p>Employees</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=attendance" class="nav-link nav-attendance tree-item">
                                            <i class="nav-icon fas fa-user-clock"></i>
                                            <p>Attendance Log</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=attendance/shift_roster" class="nav-link nav-attendance_shift_roster tree-item">
                                            <i class="nav-icon fas fa-clock"></i>
                                            <p>Shift Rosters</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=leaves" class="nav-link nav-leaves tree-item">
                                            <i class="nav-icon fas fa-business-time"></i>
                                            <p>Leave Requests</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll" class="nav-link nav-payroll tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Payroll Processing</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/payrun_engine" class="nav-link nav-payroll_payrun_engine tree-item">
                                            <i class="nav-icon fas fa-cogs"></i>
                                            <p>Payrun Engine & Retro</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/tax_rules" class="nav-link nav-payroll_tax_rules tree-item">
                                            <i class="nav-icon fas fa-balance-scale"></i>
                                            <p>FRCS Tax Regulations</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/salary_components" class="nav-link nav-payroll_salary_components tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>Payhead Components</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/fnpf_schedule" class="nav-link nav-payroll_fnpf_schedule tree-item">
                                            <i class="nav-icon fas fa-file-contract"></i>
                                            <p>FNPF CS3 Schedule</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/fiji_ems_export" class="nav-link nav-payroll_fiji_ems_export tree-item">
                                            <i class="nav-icon fas fa-file-csv"></i>
                                            <p>FRCS EMS Tax Export</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/tax_rebate_report" class="nav-link nav-payroll_tax_rebate_report tree-item">
                                            <i class="nav-icon fas fa-hand-holding-usd text-success"></i>
                                            <p>150% Tax Rebate Report</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll/sandbox" class="nav-link nav-payroll_sandbox tree-item">
                                            <i class="nav-icon fas fa-calculator text-primary"></i>
                                            <p>Payroll Test Sandbox</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=expense_reports" class="nav-link nav-expense_reports tree-item">
                                            <i class="nav-icon fas fa-receipt"></i>
                                            <p>Expense Claims</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=contracts" class="nav-link nav-contracts tree-item">
                                            <i class="nav-icon fas fa-file-contract"></i>
                                            <p>Contracts & Subscriptions</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tickets" class="nav-link nav-tickets tree-item">
                                            <i class="nav-icon fas fa-headset"></i>
                                            <p>Support Tickets</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <?php endif; ?>

                            <!-- 9. Settings (Admin only) -->
                            <?php if($_settings->userdata('type') == 1): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-cog"></i>
                                    <p>
                                        Settings
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=templates/manage_template" class="nav-link nav-templates_manage_template tree-item">
                                            <i class="fas fa-file-invoice nav-icon"></i>
                                            <p>Invoice Templates</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/financial_settings" class="nav-link nav-settings_financial_settings tree-item">
                                            <i class="nav-icon fas fa-calculator"></i>
                                            <p>Financial Settings</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/security" class="nav-link nav-settings_security tree-item">
                                            <i class="nav-icon fas fa-shield-alt"></i>
                                            <p>Security & 2FA Policy</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/currencies" class="nav-link nav-settings_currencies tree-item">
                                            <i class="nav-icon fas fa-coins"></i>
                                            <p>Currencies & Rates</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/vms_settings" class="nav-link nav-settings_vms_settings tree-item">
                                            <i class="nav-icon fas fa-microchip"></i>
                                            <p>FRCS VMS v3.0 Config</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=system_info" class="nav-link nav-system_info tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>System & Branding</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=roles" class="nav-link nav-roles tree-item">
                                            <i class="nav-icon fas fa-user-shield"></i>
                                            <p>User Roles & Access</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=user/permissions" class="nav-link nav-user_permissions tree-item">
                                            <i class="nav-icon fas fa-table"></i>
                                            <p>Permissions Matrix</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=system_info/modules" class="nav-link nav-system_info_modules tree-item">
                                            <i class="nav-icon fas fa-th-large"></i>
                                            <p>Modules Manager</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/extrafields" class="nav-link nav-settings_extrafields tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>Custom Extrafields</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/email_templates" class="nav-link nav-settings_email_templates tree-item">
                                            <i class="nav-icon fas fa-envelope-open-text"></i>
                                            <p>Email Templates</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/audit_logs" class="nav-link nav-settings_audit_logs tree-item">
                                            <i class="nav-icon fas fa-history"></i>
                                            <p>Audit Trail Logs</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/active_sessions" class="nav-link nav-settings_active_sessions tree-item">
                                            <i class="nav-icon fas fa-users-cog"></i>
                                            <p>Active User Sessions</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/login_activity" class="nav-link nav-settings_login_activity tree-item">
                                            <i class="nav-icon fas fa-exclamation-triangle"></i>
                                            <p>Login Security Log</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=settings/backup" class="nav-link nav-settings_backup tree-item">
                                            <i class="nav-icon fas fa-database"></i>
                                            <p>DB Backup & Storage</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tenants" class="nav-link nav-tenants tree-item">
                                            <i class="nav-icon fas fa-building"></i>
                                            <p>Tenants / Companies</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=user/list" class="nav-link nav-user_list tree-item">
                                            <i class="nav-icon fas fa-users-cog"></i>
                                            <p>Users & Staff</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=api_keys" class="nav-link nav-api_keys tree-item">
                                            <i class="nav-icon fas fa-plug"></i>
                                            <p>API Keys & Webhooks</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <?php endif; ?>

                            <!-- 10. Developer Tools (Admin / Developer only) -->
                            <?php if($_settings->userdata('type') == 1): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link nav-is-tree">
                                    <i class="nav-icon fas fa-code text-warning"></i>
                                    <p>
                                        Developer
                                        <i class="right fas fa-chevron-right text-xs"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/portal_preview" class="nav-link nav-developer_portal_preview tree-item">
                                            <i class="nav-icon fas fa-desktop text-success"></i>
                                            <p>Customer Portal Inspector</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/vms_inspector" class="nav-link nav-developer_vms_inspector tree-item">
                                            <i class="nav-icon fas fa-microchip text-warning"></i>
                                            <p>VMS v3.0 SDC Inspector</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/vsdc_accreditation" class="nav-link nav-developer_vsdc_accreditation tree-item">
                                            <i class="nav-icon fas fa-certificate text-danger"></i>
                                            <p>FRCS EFD Accreditation</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=developer/git_update" class="nav-link nav-developer_git_update tree-item">
                                            <i class="nav-icon fas fa-code-branch text-info"></i>
                                            <p>Git Update Module</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <?php endif; ?>

                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</aside>

<script>
$(document).ready(function(){
    var page = '<?php echo isset($_GET['page']) ? $_GET['page'] : 'home' ?>';
    page = page.replace(/\//gi,'_');

    if($('.nav-link.nav-'+page).length > 0){
        var $activeLink = $('.nav-link.nav-'+page);
        $activeLink.addClass('active');

        if($activeLink.hasClass('tree-item')){
            var $treeview = $activeLink.closest('.has-treeview');
            $treeview.addClass('menu-open');
            $treeview.children('a.nav-is-tree').addClass('active');
        }
    }
});
</script>
