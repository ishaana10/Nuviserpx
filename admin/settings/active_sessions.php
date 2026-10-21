<?php
$tenant_id = $_settings->active_tenant_id();

$users_qry = $conn->query("
    SELECT u.*, r.name as role_name
    FROM `users` u
    LEFT JOIN `roles` r ON u.role_id = r.id
    WHERE u.tenant_id = '{$tenant_id}'
    ORDER BY u.last_login DESC
");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-users-cog text-primary mr-2"></i> Active User Sessions & Access Control</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="window.location.reload()"><i class="fas fa-sync-alt mr-1"></i> Refresh Sessions</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm mb-4">
            <i class="fas fa-info-circle mr-2"></i> Inspect user login activity, role assignments, and session status across your organization.
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="sessions-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>User / Staff Name</th>
                        <th>Username / Email</th>
                        <th>Assigned Role</th>
                        <th>Last Login Timestamp</th>
                        <th class="text-center">Account Status</th>
                        <th class="text-center" style="width: 12%;">Security Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($users_qry && $users_qry->num_rows > 0):
                        while($row = $users_qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary">
                            <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                            <?php if($row['id'] == $_settings->userdata('id')): ?>
                                <span class="badge badge-success ml-1">You</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle font-monospace"><?= htmlspecialchars($row['username']) ?></td>
                        <td class="align-middle">
                            <?php if($row['type'] == 1): ?>
                                <span class="badge badge-danger px-2 py-1"><i class="fas fa-user-shield mr-1"></i> Super Admin</span>
                            <?php else: ?>
                                <span class="badge badge-info px-2 py-1"><?= htmlspecialchars($row['role_name'] ?: 'Standard User') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle text-xs">
                            <?= $row['last_login'] ? date("d M Y H:i:s", strtotime($row['last_login'])) : 'Never logged in' ?>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge badge-success px-2 py-1">Active</span>
                        </td>
                        <td class="text-center align-middle">
                            <?php if($row['id'] != $_settings->userdata('id')): ?>
                                <button type="button" class="btn btn-xs btn-outline-danger btn-flat force_logout" data-id="<?= $row['id'] ?>"><i class="fas fa-sign-out-alt mr-1"></i> Force Logout</button>
                            <?php else: ?>
                                <span class="text-muted text-xs">Current Session</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No users found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#sessions-table').dataTable();

    $('.force_logout').click(function(){
        var uid = $(this).data('id');
        if(confirm("Force logout and invalidate session for this user?")){
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=terminate_session",
                method: "POST",
                data: {user_id: uid},
                dataType: "json",
                error: err => {
                    console.log(err);
                    alert_toast("An error occurred.", "error");
                    end_loader();
                },
                success: function(resp){
                    if(resp.status == 'success'){
                        alert_toast(resp.msg, "success");
                        setTimeout(function(){ location.reload(); }, 1200);
                    } else {
                        alert_toast(resp.msg || "Failed to terminate session.", "error");
                        end_loader();
                    }
                }
            });
        }
    });
});
</script>
