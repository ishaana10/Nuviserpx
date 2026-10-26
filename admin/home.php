<?php
/**
 * Customisable dashboards.
 *
 * The page is a shell: dashboards, widget layouts and widget data are loaded
 * from classes/Dashboard.php by dist/js/nuvis-dashboard.js.
 */
$tenant_id = method_exists($_settings, 'active_tenant_id') ? $_settings->active_tenant_id() : 1;
$tenant_name = 'Default Enterprise Tenant';
$t_stmt = $conn->prepare("SELECT name FROM tenants WHERE id = ?");
if ($t_stmt) {
    $t_stmt->bind_param('i', $tenant_id);
    $t_stmt->execute();
    $t_res = $t_stmt->get_result();
    if ($t_res && $t_res->num_rows > 0) {
        $tenant_name = $t_res->fetch_assoc()['name'];
    }
}
$first_name = trim((string)$_settings->userdata('firstname'));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$asset_v = class_exists('AppVersion') ? urlencode(AppVersion::get('commit')) : '1.0';
?>
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/nuvis-dashboard.css?v=<?php echo $asset_v ?>">

<div class="nv-dash" id="nvDash">
    <div class="nv-dash-head">
        <div>
            <h1 class="nv-dash-title"><?= htmlspecialchars($greeting . ($first_name !== '' ? ', ' . $first_name : '')) ?></h1>
            <div class="nv-dash-sub">
                <span><i class="fas fa-building text-primary mr-1"></i><?= htmlspecialchars($tenant_name) ?></span>
                <span class="sep">•</span>
                <span id="nvRangeText">Loading…</span>
            </div>
        </div>
        <div class="nv-dash-actions">
            <span class="nv-save-state" id="nvSaveState" aria-live="polite"></span>
            <div class="nv-range-wrap">
                <i class="far fa-calendar-alt"></i>
                <select class="custom-select" id="nvRange" aria-label="Date range"></select>
            </div>
            <button type="button" class="btn btn-default" id="nvRefresh" title="Refresh data"><i class="fas fa-sync-alt"></i></button>
            <button type="button" class="btn btn-default nv-view-btn" id="nvEditBtn"><i class="fas fa-th-large"></i> Customize</button>
            <?php if ($_settings->can_access('invoices_manage')): ?>
            <a href="./?page=invoices/manage_invoice" class="btn btn-primary nv-view-btn"><i class="fas fa-plus"></i> New invoice</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="nv-dash-tabs" id="nvTabs" role="tablist" aria-label="Dashboards"></div>

    <div class="nv-edit-banner">
        <span><i class="fas fa-arrows-alt mr-2"></i>Drag widgets by their handle to rearrange. Use each widget's menu to resize, rename or change the chart. Changes save automatically.</span>
        <span class="d-flex" style="gap:8px">
            <button type="button" class="btn btn-sm btn-default" id="nvResetBtn"><i class="fas fa-undo"></i> Reset to default</button>
            <button type="button" class="btn btn-sm btn-default" id="nvAddBtn"><i class="fas fa-plus"></i> Add widget</button>
            <button type="button" class="btn btn-sm btn-primary" id="nvDoneBtn"><i class="fas fa-check"></i> Done</button>
        </span>
    </div>

    <div class="row nv-dash-grid" id="nvGrid">
        <?php for ($i = 0; $i < 4; $i++): ?>
        <div class="col-12 col-sm-6 col-xl-3 nv-w-col"><div class="nv-w"><div class="nv-w-body"><div class="nv-skel" style="height:14px;width:55%"></div><div class="nv-skel mt-3" style="height:30px;width:70%"></div><div class="nv-skel mt-3" style="height:12px;width:40%"></div></div></div></div>
        <?php endfor; ?>
    </div>
</div>

<!-- Widget gallery -->
<div class="modal fade" id="nvWidgetGallery" tabindex="-1" role="dialog" aria-labelledby="nvWidgetGalleryTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="nvWidgetGalleryTitle"><i class="fas fa-puzzle-piece"></i> Add widgets</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="nv-gallery-tools">
                    <div class="nv-search">
                        <i class="fas fa-search"></i>
                        <input type="search" class="form-control" id="nvGallerySearch" placeholder="Search widgets…" autocomplete="off">
                    </div>
                    <div class="nv-cat-filter" id="nvGalleryCats"></div>
                </div>
                <div class="nv-gallery" id="nvGalleryList"></div>
            </div>
            <div class="modal-footer">
                <span class="mr-auto text-muted small">Widgets only show data you have permission to see.</span>
                <button type="button" class="btn btn-primary" data-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo base_url ?>dist/js/nuvis-dashboard.js?v=<?php echo $asset_v ?>"></script>
