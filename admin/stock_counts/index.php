<?php
require_once(__DIR__ . '/../../config.php');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tenant_id = (int)$_settings->active_tenant_id();
$whs = [];
$wq = $conn->query("SELECT id, name FROM `warehouses` WHERE tenant_id = '{$tenant_id}' AND status = 1 ORDER BY name ASC");
while ($wq && ($w = $wq->fetch_assoc())) { $whs[(int)$w['id']] = $w['name']; }
$counts = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = c.id) n,
    (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = c.id AND l.counted_qty IS NOT NULL) done
    FROM `stock_counts` c WHERE c.tenant_id = '{$tenant_id}' ORDER BY c.id DESC LIMIT 200");
$badge = ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'];
?>
<div class="card card-outline card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-clipboard-check text-info"></i> Stock Take</h3>
    </div>
    <div class="card-body">
        <form id="new-count" class="callout border-info rounded-0 mb-3">
            <div class="row align-items-end">
                <div class="col-md-3 form-group"><label class="text-sm">Warehouse</label>
                    <select name="warehouse_id" class="form-control form-control-sm">
                        <option value="0">Main / unassigned stock</option>
                        <?php foreach ($whs as $id => $n): ?><option value="<?= $id ?>"><?= $h($n) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2 form-group"><label class="text-sm">Count date</label><input type="date" name="count_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                <div class="col-md-3 form-group"><label class="text-sm">Products</label>
                    <select name="scope" class="form-control form-control-sm"><option value="stocked">Only items with stock recorded there</option><option value="all">All active products</option></select></div>
                <div class="col-md-2 form-group"><label class="text-sm">Notes</label><input type="text" name="notes" class="form-control form-control-sm" maxlength="500"></div>
                <div class="col-md-2 form-group"><button class="btn btn-info btn-sm btn-flat btn-block"><i class="fas fa-plus"></i> Start count</button></div>
            </div>
            <small class="text-muted">Starting a count records what the system says is there. Enter what was actually counted, then post to correct the stock.</small>
        </form>
        <table class="table table-sm table-striped">
            <thead><tr><th>Count</th><th>Date</th><th>Warehouse</th><th>Progress</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if ($counts): while ($c = $counts->fetch_assoc()): ?>
                <tr>
                    <td><?= $h($c['count_no']) ?></td><td><?= $h($c['count_date']) ?></td>
                    <td><?= $h((int)$c['warehouse_id'] > 0 ? ($whs[(int)$c['warehouse_id']] ?? ('#' . $c['warehouse_id'])) : 'Main / unassigned') ?></td>
                    <td><?= (int)$c['done'] ?> of <?= (int)$c['n'] ?> counted</td>
                    <td><span class="badge badge-<?= $badge[$c['status']] ?? 'secondary' ?>"><?= $h($c['status']) ?></span></td>
                    <td><a class="btn btn-xs btn-flat btn-primary" href="<?= base_url ?>admin/?page=stock_counts/count&id=<?= (int)$c['id'] ?>"><?= $c['status'] === 'draft' ? 'Open sheet' : 'View' ?></a></td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$('#new-count').submit(function(e){
    e.preventDefault(); start_loader();
    $.ajax({url:_base_url_+'classes/Master.php?f=create_stock_count', method:'POST', data:$(this).serialize(), dataType:'json',
        error:function(){ alert_toast('An error occurred.','error'); end_loader(); },
        success:function(r){ if (r.status==='success') { location.href = _base_url_+'admin/?page=stock_counts/count&id='+r.id; } else { alert_toast(r.msg,'error'); end_loader(); } }});
});
</script>
