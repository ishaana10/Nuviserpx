<?php
require_once(__DIR__ . '/../../config.php');
?>

<div class="container-fluid">
    <form action="" id="warehouse-form">
        <div class="form-group mb-3">
            <label for="name" class="font-weight-bold text-dark text-sm">Warehouse Name <span class="text-danger">*</span></label>
            <input type="text" id="name" name="name" class="form-control form-control-sm rounded-0" placeholder="e.g. West Coast Distribution Depot" required>
        </div>

        <div class="form-group mb-3">
            <label for="location" class="font-weight-bold text-dark text-sm">Physical Address / Location</label>
            <input type="text" id="location" name="location" class="form-control form-control-sm rounded-0" placeholder="e.g. Building 4, Lautoka Industrial Zone">
        </div>
    </form>
</div>

<script>
$(document).ready(function(){
    $('#uni_modal #warehouse-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_warehouse",
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
                    alert_toast(resp.msg || "Failed to save warehouse.", "error");
                    end_loader();
                }
            }
        });
    });
});
</script>
