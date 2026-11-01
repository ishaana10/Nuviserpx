<?php
require_once(__DIR__ . '/../../config.php');

if(!$_settings->is_super_admin()){
	echo '<div class="alert alert-danger m-3"><i class="fas fa-ban mr-2"></i>Only the developer (super administrator) can view the permissions matrix.</div>';
	return;
}

$tenant_id = $_settings->active_tenant_id();

$roles = $conn->query("SELECT * FROM `roles` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");

// Pre-load each role's granted keys (expanded through the registry so legacy
// keys and implied views are reflected).
$roles_arr = [];
while($r = $roles->fetch_assoc()){
    $r['perm_keys'] = \App\Support\Permissions::parse($r['permissions'] ?? '');
    $roles_arr[] = $r;
}
$col_count = count($roles_arr) + 2;

// Flatten the canonical registry into display rows: section header rows and
// one row per permission key. Identical source to the role editor and the
// server-side enforcement, so this inspector always reflects reality.
$rows = [];
foreach(\App\Support\Permissions::matrix() as $section => $modules){
    $rows[] = ['type' => 'section', 'label' => $section];
    foreach($modules as $mod){
        foreach($mod['actions'] as $act => $key){
            $rows[] = ['type' => 'perm', 'label' => $mod['label'].' — '.\App\Support\Permissions::ACTIONS[$act], 'key' => $key];
        }
    }
}
foreach(\App\Support\Permissions::adminGroups() as $section => $perms){
    $rows[] = ['type' => 'section', 'label' => $section];
    foreach($perms as $key => $label){
        $rows[] = ['type' => 'perm', 'label' => $label, 'key' => $key];
    }
}
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-user-shield text-primary mr-2"></i> User Access Permissions Matrix</h3>
        <div class="card-tools">
            <a href="./?page=roles" class="btn btn-sm btn-primary btn-flat"><i class="fas fa-edit mr-1"></i> Manage Custom Roles</a>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">
            A read-only overview of what each role can do, per module action (View / Create / Edit / Delete).
            The <b>Super Administrator</b> (developer) always has unrestricted access.
        </p>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th>Permission</th>
                        <th class="text-center" style="width: 14%;">Super Admin</th>
                        <?php foreach($roles_arr as $r): ?>
                            <th class="text-center" style="width: 14%;"><?= htmlspecialchars($r['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($rows as $row): ?>
                        <?php if($row['type'] === 'section'): ?>
                            <tr class="bg-light">
                                <td colspan="<?= $col_count ?>" class="font-weight-bold text-dark text-uppercase text-xs"><?= htmlspecialchars($row['label']) ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="align-middle pl-4">
                                    <div class="text-navy"><?= htmlspecialchars($row['label']) ?></div>
                                    <code class="text-muted text-xs"><?= htmlspecialchars($row['key']) ?></code>
                                </td>
                                <td class="text-center align-middle text-success font-weight-bold"><i class="fas fa-check-circle"></i></td>
                                <?php foreach($roles_arr as $r):
                                    $allowed = in_array($row['key'], $r['perm_keys'], true)
                                            || in_array(\App\Support\Permissions::WILDCARD, $r['perm_keys'], true);
                                ?>
                                    <td class="text-center align-middle">
                                        <?php if($allowed): ?>
                                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check"></i></span>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-minus"></i></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
