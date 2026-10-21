<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$roles = $conn->query("SELECT * FROM `roles` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");

$permission_categories = [
    'Sales & Invoicing' => ['invoices_read', 'invoices_create', 'invoices_delete', 'payments_manage'],
    'Purchases & Bills' => ['bills_read', 'bills_create', 'purchase_orders_manage'],
    'Accounting & Journals' => ['chart_of_accounts_manage', 'journals_create', 'fixed_assets_manage'],
    'Payroll & HR' => ['employees_read', 'payroll_run', 'expense_claims_approve'],
    'Settings & Dev Tools' => ['system_info_manage', 'user_roles_manage', 'api_keys_manage']
];
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
            Dolibarr-inspired Role Access Control Matrix Inspector. Gives a granular overview of user access capabilities across system modules.
        </p>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th>System Module Permission</th>
                        <th class="text-center" style="width: 15%;">Super Administrator</th>
                        <?php
                        $roles_arr = [];
                        while($r = $roles->fetch_assoc()):
                            $roles_arr[] = $r;
                        ?>
                            <th class="text-center" style="width: 15%;"><?= htmlspecialchars($r['name']) ?></th>
                        <?php endwhile; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($permission_categories as $cat => $perms): ?>
                        <tr class="bg-light">
                            <td colspan="<?= count($roles_arr) + 2 ?>" class="font-weight-bold text-dark text-uppercase text-xs"><?= $cat ?></td>
                        </tr>
                        <?php foreach($perms as $p): ?>
                            <tr>
                                <td class="align-middle pl-4"><code><?= $p ?></code></td>
                                <td class="text-center align-middle text-success font-weight-bold"><i class="fas fa-check-circle"></i> Full Access</td>
                                <?php foreach($roles_arr as $r):
                                    $p_list = !empty($r['permissions']) ? json_decode($r['permissions'], true) : [];
                                    $has_perm = is_array($p_list) && in_array($p, $p_list);
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
