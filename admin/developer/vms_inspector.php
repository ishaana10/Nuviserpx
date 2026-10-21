<?php
if($_settings->userdata('type') != 1){
    echo "<div class='alert alert-danger'>Access Denied: Super Administrator permission required.</div>";
    exit;
}

$tenant_id = $_settings->active_tenant_id();

$logs_qry = $conn->query("
    SELECT vl.*, t.name as tenant_name
    FROM `vms_logs` vl
    LEFT JOIN `tenants` t ON vl.tenant_id = t.id
    ORDER BY vl.id DESC
    LIMIT 100
");
?>

<div class="card card-outline card-warning shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-microchip text-warning mr-2"></i> FRCS VMS v3.0 SDC Payload Inspector & Simulator</h3>
        <div class="card-tools d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-warning btn-flat font-weight-bold" id="btn_ping_sdc"><i class="fas fa-plug mr-1"></i> SDC Ping Benchmark</button>
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-4" id="vmsDevTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link active font-weight-bold" id="sim-tab" data-toggle="tab" href="#vms-sim"><i class="fas fa-code text-primary mr-1"></i> VSDC Payload Simulator</a>
            </li>
            <li class="nav-item">
                <a class="nav-link font-weight-bold" id="log-tab" data-toggle="tab" href="#vms-logs"><i class="fas fa-list text-info mr-1"></i> Live Fiscal Logs Audit</a>
            </li>
        </ul>

        <div class="tab-content" id="vmsDevTabContent">
            <!-- TAB 1: VSDC SIMULATOR -->
            <div class="tab-pane fade show active" id="vms-sim" role="tabpanel">
                <div class="row">
                    <div class="col-md-6">
                        <form id="vsdc-sim-form">
                            <div class="form-group mb-3">
                                <label for="sim_sdc_url" class="font-weight-bold text-dark text-sm">Target SDC Endpoint URL</label>
                                <input type="text" name="sdc_url" id="sim_sdc_url" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($_settings->info('vms_url') ?: 'http://localhost:8088/api/v1/sdc') ?>">
                            </div>

                            <div class="form-group mb-3">
                                <label for="sim_payload" class="font-weight-bold text-dark text-sm">Raw VSDC v3.0 Request JSON</label>
                                <textarea name="payload_json" id="sim_payload" rows="12" class="form-control form-control-sm rounded-0 font-monospace text-xs">{
  "InvoiceType": 1,
  "TransactionType": 1,
  "TIN": "50-00000-0-0",
  "OperatorID": "OPERATOR_01",
  "InvoiceNumber": "INV-SIM-999",
  "InvoiceDate": "<?= date('Y-m-d\TH:i:s\Z') ?>",
  "Items": [
    {
      "GTIN": "N/A",
      "Name": "Sample Commercial Product",
      "Quantity": 1.0,
      "UnitPrice": 100.0,
      "TotalAmount": 115.0,
      "Labels": ["A"]
    }
  ],
  "Payments": [
    {
      "PaymentType": 0,
      "Amount": 115.0
    }
  ]
}</textarea>
                            </div>

                            <button type="submit" class="btn btn-sm btn-primary btn-flat font-weight-bold w-100"><i class="fas fa-paper-plane mr-1"></i> Transmit Payload to VSDC</button>
                        </form>
                    </div>

                    <div class="col-md-6">
                        <label class="font-weight-bold text-dark text-sm">VSDC Response Output</label>
                        <pre id="vsdc_output" class="bg-dark text-success p-3 rounded font-monospace text-xs" style="height: 380px; overflow-y: auto;">// Transmit VSDC payload to view response...</pre>
                    </div>
                </div>
            </div>

            <!-- TAB 2: LIVE FISCAL LOGS -->
            <div class="tab-pane fade" id="vms-logs" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered" id="vms-logs-table">
                        <thead>
                            <tr class="bg-gradient-navy text-white">
                                <th class="text-center" style="width: 5%;">#</th>
                                <th>Tenant</th>
                                <th>Invoice No</th>
                                <th>SDC Invoice No</th>
                                <th>MRC Serial</th>
                                <th class="text-center">Status</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            if($logs_qry && $logs_qry->num_rows > 0):
                                while($row = $logs_qry->fetch_assoc()):
                            ?>
                            <tr>
                                <td class="text-center align-middle"><?= $i++; ?></td>
                                <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['tenant_name'] ?: 'Default Tenant') ?></td>
                                <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['invoice_no']) ?></td>
                                <td class="align-middle font-monospace"><?= htmlspecialchars($row['sdc_invoice_no'] ?: 'N/A') ?></td>
                                <td class="align-middle font-monospace text-xs"><?= htmlspecialchars($row['mrc'] ?: 'N/A') ?></td>
                                <td class="text-center align-middle">
                                    <span class="badge badge-<?= $row['status'] == 'success' ? 'success' : 'danger' ?> px-2 py-1"><?= strtoupper($row['status']) ?></span>
                                </td>
                                <td class="align-middle text-xs"><?= date("d M Y H:i:s", strtotime($row['date_created'])) ?></td>
                            </tr>
                            <?php
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No VMS transaction logs recorded.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#vms-logs-table').dataTable();

    $('#btn_ping_sdc').click(function(){
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=test_sdc_ping",
            method: "POST",
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    alert_toast(resp.msg, "success");
                } else {
                    alert_toast(resp.msg || "SDC Ping Failed.", "error");
                }
            }
        });
    });

    $('#vsdc-sim-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=simulate_vms_fiscalization",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                $('#vsdc_output').text(JSON.stringify(err, null, 2));
                alert_toast("Simulation error.", "error");
                end_loader();
            },
            success: function(resp){
                end_loader();
                $('#vsdc_output').text(JSON.stringify(resp, null, 2));
                if(resp.status == 'success'){
                    alert_toast("VSDC Payload transmitted successfully!", "success");
                } else {
                    alert_toast("Transmission failed.", "error");
                }
            }
        });
    });
});
</script>
