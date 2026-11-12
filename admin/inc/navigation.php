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
                    <!-- Find a menu item fast; pinned items sit above the full menu -->
                    <div class="nv-side-search" id="nvSideSearch" role="search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" id="nvSideSearchInput" placeholder="Search menu&hellip; ( / )" autocomplete="off" aria-label="Search menu">
                        <button type="button" class="nv-side-clear" id="nvSideClear" aria-label="Clear search"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="nv-pinned-block is-empty" id="nvPinned">
                        <div class="nv-side-label">Pinned</div>
                        <ul class="nav nav-pills nav-sidebar flex-column text-sm nav-flat" id="nvPinnedList"></ul>
                    </div>
                    <div class="nv-side-empty" id="nvSideEmpty" role="status">No menu items match.</div>
                    <nav class="mt-2">
<?php ob_start(); ?>
                        <ul class="nav nav-pills nav-sidebar flex-column text-sm <?= $sidenav_compact ?> nav-flat nav-child-indent" data-widget="treeview" role="menu" data-accordion="<?= $sidenav_mode == 'accordion' ? 'true' : 'false' ?>">

                            <?php
                            // The menu, one file per section, in order.
                            foreach ([
                                '01_home', '02_sales', '03_purchases', '04_reporting',
                                '05_accounting', '06_tax', '07_contacts', '08_payroll_hr',
                                '09_access_users', '10_settings', '11_developer',
                            ] as $nv_menu_section) {
                                include __DIR__ . '/menus/' . $nv_menu_section . '.php';
                            }
                            ?>

                        </ul>
                    <?php echo \App\Support\MenuFilter::filter((string)ob_get_clean(), $_settings); ?>
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
        // Long menus scroll: bring the current page's link into view.
        setTimeout(function(){ try { $activeLink[0].scrollIntoView({block: 'nearest'}); } catch (e) {} }, 350);
    }
});
</script>
