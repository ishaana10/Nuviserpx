<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$warehouses = $conn->query("SELECT * FROM `warehouses` WHERE tenant_id = '{$tenant_id}' AND status = 1 ORDER BY name ASC");
$wh_list = [];
while($w = $warehouses->fetch_assoc()){
    $wh_list[] = $w;
}

$products = $conn->query("SELECT id, product_code, name, stock_quantity FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
?>

<div class="container-fluid">
    <form action="" id="transfer-form">
        <div class="row">
            <div class="col-md-6 form-group">
                <label for="from_warehouse_id" class="font-weight-bold text-dark text-sm">From Warehouse <span class="text-danger">*</span></label>
                <select name="from_warehouse_id" id="from_warehouse_id" class="form-control form-control-sm select2" style="width: 100%;" required>
                    <option value="0">Main Central Warehouse</option>
                    <?php foreach($wh_list as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label for="to_warehouse_id" class="font-weight-bold text-dark text-sm">To Warehouse <span class="text-danger">*</span></label>
                <select name="to_warehouse_id" id="to_warehouse_id" class="form-control form-control-sm select2" style="width: 100%;" required>
                    <option value="" disabled selected>Select Destination...</option>
                    <option value="0">Main Central Warehouse</option>
                    <?php foreach($wh_list as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8 form-group">
                <label for="product_id" class="font-weight-bold text-dark text-sm">Select Product <span class="text-danger">*</span></label>
                <select name="product_id" id="product_id" class="form-control form-control-sm select2" style="width: 100%;" required>
                    <option value="">Select Product...</option>
                    <?php while($p = $products->fetch_assoc()): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_code'] . ' - ' . $p['name'] . ' (Avail: ' . $p['stock_quantity'] . ')') ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-4 form-group">
                <label for="qty" class="font-weight-bold text-dark text-sm">Quantity <span class="text-danger">*</span></label>
                <input type="number" step="any" id="qty" name="qty" class="form-control form-control-sm text-right" placeholder="0.00" required>
            </div>
        </div>

        <div class="form-group mb-3">
            <label for="notes" class="font-weight-bold text-dark text-sm">Transfer Reference / Notes</label>
            <input type="text" id="notes" name="notes" class="form-control form-control-sm rounded-0" placeholder="e.g. Stock balancing transfer for Suva branch">
        </div>
    </form>
</div>

<script>
$(document).ready(function(){
    $('#uni_modal').on('shown.bs.modal', function(){
        $('.select2').select2({
            dropdownParent: $('#uni_modal')
        });
    });

    $('#uni_modal #transfer-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=transfer_stock",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", "error");
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    location.reload();
                } else {
                    alert_toast(resp.msg || "Failed to execute transfer.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
