<?php
$sidenav_theme = $_settings->info('sidenav_theme') ? $_settings->info('sidenav_theme') : 'sidebar-dark-navy';
$sidenav_mode = $_settings->info('sidenav_mode') ? $_settings->info('sidenav_mode') : 'accordion';
$sidenav_compact = $_settings->info('sidenav_compact') !== '0' ? 'nav-compact' : '';

$show_commercial = $_settings->info('sidenav_show_commercial') !== '0';
$show_payroll = $_settings->info('sidenav_show_payroll') !== '0';
$show_financial = $_settings->info('sidenav_show_financial') !== '0';
$show_maintenance = $_settings->info('sidenav_show_maintenance') !== '0';
?>

<style>
.main-sidebar .nav-sidebar .nav-item > .nav-link {
    border-radius: 6px;
    margin: 2px 8px;
    transition: all 0.2s ease-in-out;
}
.main-sidebar .nav-sidebar .nav-item > .nav-link:hover {
    background-color: rgba(255, 255, 255, 0.08);
}
.main-sidebar .nav-sidebar .nav-item.menu-open > .nav-link {
    background-color: rgba(255, 255, 255, 0.12);
}
.main-sidebar .nav-treeview .nav-link {
    padding-left: 2rem !important;
}
</style>

<!-- Main Sidebar Container -->
<aside class="main-sidebar <?= $sidenav_theme ?> elevation-4 sidebar-no-expand">
    <!-- Brand Logo -->
    <a href="<?php echo base_url ?>admin" class="brand-link bg-transparent text-sm shadow-sm py-2">
        <img src="<?php echo validate_image($_settings->info('logo'))?>" alt="Logo" class="brand-image img-circle elevation-3 bg-black" style="width: 1.8rem;height: 1.8rem;max-height: unset;object-fit:scale-down;object-position:center center">
        <span class="brand-text font-weight-bold text-wrap"><?php echo $_settings->info('short_name') ?></span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar os-host os-theme-light os-host-overflow os-host-overflow-y">
        <div class="os-padding">
            <div class="os-viewport os-viewport-native-scrollbars-invisible">
                <div class="os-content" style="padding: 0px 4px; height: 100%; width: 100%;">

                    <!-- Sidebar Menu -->
                    <nav class="mt-2">
                        <ul class="nav nav-pills nav-sidebar flex-column text-sm <?= $sidenav_compact ?> nav-flat nav-child-indent" data-widget="treeview" role="menu" data-accordion="<?= $sidenav_mode == 'accordion' ? 'true' : 'false' ?>">

                            <!-- Dashboard -->
                            <li class="nav-item">
                                <a href="./" class="nav-link text-light nav-home">
                                    <i class="nav-icon fas fa-tachometer-alt text-info"></i>
                                    <p>Dashboard</p>
                                </a>
                            </li>

                            <!-- Commercial Stock & Sales Group Dropdown -->
                            <?php if($show_commercial && ($_settings->can_access('products_view') || $_settings->can_access('sales_orders_view') || $_settings->can_access('invoices_view') || $_settings->can_access('payments_view'))): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link text-light nav-is-tree">
                                    <i class="nav-icon fas fa-shopping-cart text-warning"></i>
                                    <p>
                                        Stock & Sales
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <?php if($_settings->can_access('products_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=products" class="nav-link text-light nav-products tree-item">
                                            <i class="nav-icon fas fa-boxes"></i>
                                            <p>Products & Stock</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('sales_orders_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=sales_orders" class="nav-link text-light nav-sales_orders tree-item">
                                            <i class="nav-icon fas fa-mobile-alt"></i>
                                            <p>Sales Orders & Quotes</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('products_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=stock_adjustments" class="nav-link text-light nav-stock_adjustments tree-item">
                                            <i class="nav-icon fas fa-cubes"></i>
                                            <p>Stock Movements</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('invoices_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=parties" class="nav-link text-light nav-parties tree-item">
                                            <i class="nav-icon fas fa-address-book"></i>
                                            <p>Customers & Vendors</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=invoices" class="nav-link text-light nav-invoices tree-item">
                                            <i class="nav-icon fas fa-file-invoice"></i>
                                            <p>Sales Invoices</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('payments_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payments" class="nav-link text-light nav-payments tree-item">
                                            <i class="nav-icon fas fa-receipt"></i>
                                            <p>Payment Receipts</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <?php endif; ?>

                            <!-- Payroll & HR Group Dropdown -->
                            <?php if($show_payroll && ($_settings->can_access('employees_view') || $_settings->can_access('payroll_view'))): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link text-light nav-is-tree">
                                    <i class="nav-icon fas fa-users text-success"></i>
                                    <p>
                                        Payroll & HR
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <?php if($_settings->can_access('employees_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=employees" class="nav-link text-light nav-employees tree-item">
                                            <i class="nav-icon fas fa-user-tie"></i>
                                            <p>Employees</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=attendance" class="nav-link text-light nav-attendance tree-item">
                                            <i class="nav-icon fas fa-user-clock"></i>
                                            <p>Attendance</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=leaves" class="nav-link text-light nav-leaves tree-item">
                                            <i class="nav-icon fas fa-business-time"></i>
                                            <p>Leave Applications</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('payroll_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=payroll" class="nav-link text-light nav-payroll tree-item">
                                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                            <p>Payroll & Payslips</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <?php endif; ?>

                            <!-- Financial Statements & Reports Dropdown -->
                            <?php if($show_financial && ($_settings->can_access('journals_view') || $_settings->can_access('reports_view'))): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link text-light nav-is-tree">
                                    <i class="nav-icon fas fa-chart-line text-primary"></i>
                                    <p>
                                        Financial Reports
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <?php if($_settings->can_access('reports_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports" class="nav-link text-light nav-reports tree-item">
                                            <i class="nav-icon fas fa-chart-pie"></i>
                                            <p>Reports Center</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('journals_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=journals" class="nav-link text-light nav-journals tree-item">
                                            <i class="nav-icon fas fa-book"></i>
                                            <p>Journal Entries</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if($_settings->can_access('reports_view')): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/general_ledger" class="nav-link text-light nav-reports_general_ledger tree-item">
                                            <i class="nav-icon fas fa-book-open"></i>
                                            <p>General Ledger</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/balance_sheet" class="nav-link text-light nav-reports_balance_sheet tree-item">
                                            <i class="nav-icon fas fa-balance-scale"></i>
                                            <p>Balance Sheet</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/income_statement" class="nav-link text-light nav-reports_income_statement tree-item">
                                            <i class="nav-icon fas fa-chart-line"></i>
                                            <p>Income Statement</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/ar_aging" class="nav-link text-light nav-reports_ar_aging tree-item">
                                            <i class="nav-icon fas fa-history"></i>
                                            <p>AR Aging</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/payroll_accounting" class="nav-link text-light nav-reports_payroll_accounting tree-item">
                                            <i class="nav-icon fas fa-calculator"></i>
                                            <p>Payroll Accounting</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/working_trial_balance" class="nav-link text-light nav-reports_working_trial_balance tree-item">
                                            <i class="nav-icon fas fa-file"></i>
                                            <p>Working Trial Balance</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=reports/trial_balance" class="nav-link text-light nav-reports_trial_balance tree-item">
                                            <i class="nav-icon fas fa-file"></i>
                                            <p>Trial Balance</p>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <?php endif; ?>

                            <!-- Maintenance & Settings Dropdown (Admin only) -->
                            <?php if($show_maintenance && $_settings->userdata('type') == 1): ?>
                            <li class="nav-item has-treeview">
                                <a href="#" class="nav-link text-light nav-is-tree">
                                    <i class="nav-icon fas fa-cogs text-secondary"></i>
                                    <p>
                                        Maintenance & Settings
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=groups" class="nav-link text-light nav-groups tree-item">
                                            <i class="nav-icon fas fa-th-list"></i>
                                            <p>Group List</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=accounts" class="nav-link text-light nav-accounts tree-item">
                                            <i class="nav-icon fas fa-table"></i>
                                            <p>Accounts List</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=user/list" class="nav-link text-light nav-user_list tree-item">
                                            <i class="nav-icon fas fa-users-cog"></i>
                                            <p>User List</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=tenants" class="nav-link text-light nav-tenants tree-item">
                                            <i class="nav-icon fas fa-building"></i>
                                            <p>Tenants / Companies</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=api_keys" class="nav-link text-light nav-api_keys tree-item">
                                            <i class="nav-icon fas fa-plug"></i>
                                            <p>API Keys & Webhooks</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=roles" class="nav-link text-light nav-roles tree-item">
                                            <i class="nav-icon fas fa-user-shield"></i>
                                            <p>User Roles & Access</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="<?php echo base_url ?>admin/?page=system_info" class="nav-link text-light nav-system_info tree-item">
                                            <i class="nav-icon fas fa-sliders-h"></i>
                                            <p>System Settings</p>
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
        $activeLink.addClass('active').removeClass('text-light text-dark');

        if($activeLink.hasClass('tree-item')){
            var $treeview = $activeLink.closest('.has-treeview');
            $treeview.addClass('menu-open');
            $treeview.children('a.nav-is-tree').addClass('active').removeClass('text-light text-dark');
        }
    }
});
</script>
