<?php
// Read-only module overview for the active company. Modules are assigned per
// tenant by the developer (Tenants page); everyone else can only see what is
// on, what each module covers and what it depends on - nothing here writes.
use App\Support\Permissions;

$nv_perm_labels = Permissions::modules();
$nv_is_dev = $_settings->is_super_admin();
?>
<div class="card card-outline rounded-0 shadow-sm">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-puzzle-piece mr-2"></i>Modules &amp; Features</h3>
        <?php if($nv_is_dev): ?>
        <div class="card-tools">
            <a href="<?php echo base_url ?>admin/?page=tenants" class="btn btn-sm btn-primary rounded-0">
                <i class="fas fa-cog mr-1"></i> Assign modules (Tenants)
            </a>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="alert alert-info rounded-0">
            <i class="fas fa-info-circle mr-1"></i>
            Modules are set up per company by the system developer.
            <?php if(!$nv_is_dev): ?>
                This page is view-only &mdash; to activate or deactivate a module, contact your provider.
            <?php else: ?>
                Changes are made on the <a href="<?php echo base_url ?>admin/?page=tenants">Tenants</a> page.
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Status</th>
                        <th>Covers</th>
                        <th>Depends on</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach(Permissions::TENANT_MODULES as $nv_mk => $nv_md):
                    $nv_on = $_settings->module_enabled($nv_mk); ?>
                    <tr class="<?php echo $nv_on ? '' : 'text-muted' ?>">
                        <td class="font-weight-bold"><?php echo htmlspecialchars($nv_md['label']) ?></td>
                        <td>
                            <?php if($nv_on): ?>
                                <span class="badge badge-success px-2">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary px-2">Off</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo htmlspecialchars(implode(', ', array_map(fn($p) => $nv_perm_labels[$p] ?? $p, $nv_md['perms']))) ?></small></td>
                        <td><small><?php echo $nv_md['requires'] ? htmlspecialchars(implode(', ', array_map(fn($r) => Permissions::TENANT_MODULES[$r]['label'], $nv_md['requires']))) : '&mdash;' ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
