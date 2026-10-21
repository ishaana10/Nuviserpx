<?php
$table_stats = [];
$total_rows = 0;
$total_size_mb = 0;

$res = $conn->query("SHOW TABLE STATUS");
if($res){
    while($row = $res->fetch_assoc()){
        $rows = intval($row['Rows']);
        $data_bytes = intval($row['Data_length']) + intval($row['Index_length']);
        $size_mb = round($data_bytes / (1024 * 1024), 2);

        $table_stats[] = [
            'name' => $row['Name'],
            'rows' => $rows,
            'size' => $size_mb,
            'engine' => $row['Engine']
        ];

        $total_rows += $rows;
        $total_size_mb += $size_mb;
    }
}
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-database text-primary mr-2"></i> Database Backup & System Maintenance</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-success btn-flat mr-2" id="btn_optimize_db"><i class="fas fa-magic mr-1"></i> Optimize Tables</button>
            <a href="<?= base_url ?>classes/Master.php?f=backup_db" class="btn btn-sm btn-primary btn-flat font-weight-bold"><i class="fas fa-download mr-1"></i> Download SQL Backup</a>
        </div>
    </div>
    <div class="card-body">
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-table"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Total Database Tables</span>
                        <span class="info-box-number text-dark h4 mb-0"><?= count($table_stats) ?> Tables</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-info elevation-1"><i class="fas fa-list-ol"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Total Database Records</span>
                        <span class="info-box-number text-navy h4 mb-0"><?= number_format($total_rows) ?> Rows</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-hdd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text text-muted font-weight-bold">Estimated Storage Size</span>
                        <span class="info-box-number text-success h4 mb-0"><?= number_format($total_size_mb, 2) ?> MB</span>
                    </div>
                </div>
            </div>
        </div>

        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-list text-secondary mr-2"></i> Database Table Structure & Storage Usage</h5>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="tables-list">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Table Name</th>
                        <th>Storage Engine</th>
                        <th class="text-right">Total Rows</th>
                        <th class="text-right">Size (MB)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    foreach($table_stats as $stat):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold font-monospace text-primary"><?= htmlspecialchars($stat['name']) ?></td>
                        <td class="align-middle"><span class="badge badge-light border"><?= htmlspecialchars($stat['engine']) ?></span></td>
                        <td class="align-middle text-right font-weight-bold"><?= number_format($stat['rows']) ?></td>
                        <td class="align-middle text-right font-weight-bold font-monospace"><?= number_format($stat['size'], 2) ?> MB</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#tables-list').dataTable();

    $('#btn_optimize_db').click(function(){
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=optimize_db",
            method: "POST",
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred during database optimization.", "error");
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    alert_toast(resp.msg, "success");
                } else {
                    alert_toast(resp.msg || "Failed to optimize database.", "error");
                }
            }
        });
    });
});
</script>
