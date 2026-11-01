<?php
require_once(__DIR__ . '/../../config.php');

if(!$_settings->can_access('roles_manage')){
	echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>You don\'t have permission to view the permissions matrix.</div>';
	return;
}

$tenant_id = $_settings->active_tenant_id();

$roles = $conn->query("SELECT * FROM `roles` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");

// Canonical permission registry — identical to the role editor and the
// server-side enforcement, so this inspector always reflects reality.
$permission_groups = \App\Support\Permissions::groups();

// Pre-load each role's granted permission keys (stored comma-separated).
$roles_arr = [];
while($r = $roles->fetch_assoc()){
    $r['perm_keys'] = \App\Support\Permissions::parse($r['permissions'] ?? '');
    $roles_arr[] = $r;
}
$col_count = count($roles_arr) + 2;
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-user-shield text-primary mr-2"></i> User Access Permissions Matrix Inspector</h3>
        <div class="card-tools">
            <a href="./?page=roles" class="btn btn-sm btn-primary btn-flat"><i class="fas fa-edit mr-1"></i> Manage Custom Roles</a>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">
            A read-only overview of what each custom role can do across system modules.
            The <b>Super Administrator</b> always has unrestricted access.
        </p>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th>System Module Permission</th>
                        <th class="text-center" style="width: 15%;">Super Administrator</th>
                        <?php foreach($roles_arr as $r): ?>
                            <th class="text-center" style="width: 15%;"><?= htmlspecialchars($r['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($permission_groups)): ?>
                        <tr><td colspan="<?= $col_count ?>" class="text-center text-muted">No permissions defined.</td></tr>
                    <?php endif; ?>
                    <?php foreach($permission_groups as $cat => $perms): ?>
                        <tr class="bg-light">
                            <td colspan="<?= $col_count ?>" class="font-weight-bold text-dark text-uppercase text-xs"><?= htmlspecialchars($cat) ?></td>
                        </tr>
                        <?php foreach($perms as $p_key => $p_label): ?>
                            <tr>
                                <td class="align-middle pl-4">
                                    <div class="font-weight-bold text-navy"><?= htmlspecialchars($p_label) ?></div>
                                    <code class="text-muted"><?= htmlspecialchars($p_key) ?></code>
                                </td>
                                <td class="text-center align-middle text-success font-weight-bold"><i class="fas fa-check-circle"></i> Full Access</td>
                                <?php foreach($roles_arr as $r):
                                    $has_perm = in_array($p_key, $r['perm_keys'], true)
                                             || in_array(\App\Support\Permissions::WILDCARD, $r['perm_keys'], true);
                                ?>
                                    <td class="text-center align-middle">
                                        <?php if($has_perm): ?>
                                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i> Allowed</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary px-2 py-1"><i class="fas fa-times mr-1"></i> Denied</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
