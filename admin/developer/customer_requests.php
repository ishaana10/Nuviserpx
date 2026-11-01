<?php
require_once(dirname(__DIR__, 2) . '/config.php');

if ($_settings->userdata('type') != 1) {
    echo '<div class="alert alert-danger font-weight-bold my-4"><i class="fas fa-exclamation-triangle mr-2"></i> Access Denied: restricted to Super Administrators.</div>';
    return;
}

// Platform-wide ticket KPIs
function tkt_count($conn, $where){ $r=$conn->query("SELECT COUNT(*) c FROM `tickets` WHERE {$where}"); return $r?(int)$r->fetch_assoc()['c']:0; }
$k_open = tkt_count($conn, "status IN ('new','in_progress','waiting_client')");
$k_new  = tkt_count($conn, "status = 'new'");
$k_urgent = tkt_count($conn, "priority = 'urgent' AND status NOT IN ('resolved','closed')");
$k_resolved = tkt_count($conn, "status IN ('resolved','closed')");
?>
<h4 class="mb-3 font-weight-bold"><i class="fas fa-inbox text-warning mr-2"></i>Customer Requests</h4>

<div class="row mb-2">
    <div class="col-md-3 col-6 mb-2"><div class="dev-kpi" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px"><div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase">Open</div><div style="font-size:1.5rem;font-weight:800"><?php echo $k_open ?></div></div></div>
    <div class="col-md-3 col-6 mb-2"><div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px"><div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase">New</div><div style="font-size:1.5rem;font-weight:800;color:#2563eb"><?php echo $k_new ?></div></div></div>
    <div class="col-md-3 col-6 mb-2"><div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px"><div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase">Urgent</div><div style="font-size:1.5rem;font-weight:800;color:#dc2626"><?php echo $k_urgent ?></div></div></div>
    <div class="col-md-3 col-6 mb-2"><div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px"><div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase">Resolved</div><div style="font-size:1.5rem;font-weight:800;color:#16a34a"><?php echo $k_resolved ?></div></div></div>
</div>

<div class="card card-outline card-warning">
    <div class="card-header"><h3 class="card-title">All Support Requests (platform-wide)</h3></div>
    <div class="card-body">
        <table class="table table-hover table-bordered table-sm" id="reqTable">
            <thead class="thead-light">
                <tr>
                    <th>Ticket</th>
                    <th>Tenant</th>
                    <th>Subject</th>
                    <th>Customer</th>
                    <th class="text-center">Priority</th>
                    <th class="text-center">Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT tk.*, t.name AS tenant_name, p.name AS party_name
                        FROM `tickets` tk
                        LEFT JOIN `tenants` t ON tk.tenant_id = t.id
                        LEFT JOIN `party_list` p ON tk.party_id = p.id
                        ORDER BY FIELD(tk.priority,'urgent','high','medium','low'), tk.id DESC
                        LIMIT 500";
                $qry = $conn->query($sql);
                while($qry && $row = $qry->fetch_assoc()):
                    $pb = ['urgent'=>'danger','high'=>'warning','medium'=>'info','low'=>'secondary'][$row['priority']] ?? 'secondary';
                    $sb = ['new'=>'primary','in_progress'=>'info','waiting_client'=>'warning','resolved'=>'success','closed'=>'secondary'][$row['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><b><?php echo htmlspecialchars($row['ticket_code']) ?></b></td>
                    <td><small><?php echo htmlspecialchars($row['tenant_name'] ?: '—') ?></small></td>
                    <td><?php echo htmlspecialchars($row['subject']) ?></td>
                    <td><small><?php echo htmlspecialchars($row['party_name'] ?: '—') ?></small></td>
                    <td class="text-center"><span class="badge badge-<?php echo $pb ?>"><?php echo ucfirst($row['priority']) ?></span></td>
                    <td class="text-center"><span class="badge badge-<?php echo $sb ?>"><?php echo ucfirst(str_replace('_',' ',$row['status'])) ?></span></td>
                    <td><small><?php echo $row['date_created'] ?></small></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <p class="text-muted small mb-0 mt-2">Requests are the support tickets raised within each tenant. Open a tenant's own Tickets module to reply or change status.</p>
    </div>
</div>

<script>
$(function(){
    if ($.fn.DataTable.isDataTable('#reqTable')) { $('#reqTable').DataTable().destroy(); }
    $('#reqTable').dataTable({ order:[], pageLength:25 });
});
</script>
