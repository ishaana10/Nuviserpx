<?php
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * FROM `account_list` where id = '{$id_clean}' and tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        $res = $qry->fetch_array();
        foreach($res as $k => $v){
            if(!is_numeric($k))
            $$k = $v;
        }
    }
}
?>
<div class="container-fluid">
    <form action="" id="account-form">
        <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

        <div class="row">
            <div class="col-md-4 form-group">
                <label for="code" class="control-label font-weight-bold">Code</label>
                <input type="text" name="code" id="code" class="form-control form-control-sm" placeholder="e.g. 200, 400" value="<?php echo isset($code) ? htmlspecialchars($code) : '' ?>" required>
            </div>
            <div class="col-md-8 form-group">
                <label for="name" class="control-label font-weight-bold">Account Name</label>
                <input type="text" name="name" id="name" class="form-control form-control-sm" placeholder="Enter account name" value="<?php echo isset($name) ? htmlspecialchars($name) : '' ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="type" class="control-label font-weight-bold">Account Type</label>
                <select name="type" id="type" class="form-control form-control-sm" required>
                    <?php
                    $cur_type = isset($type) && $type !== '' ? $type : 'Expense';
                    $known = array_merge(...array_values(\App\Services\AccountService::ACCOUNT_TYPES));
                    if (!in_array($cur_type, $known, true)): ?>
                        <option value="<?= htmlspecialchars($cur_type) ?>" selected><?= htmlspecialchars($cur_type) ?> (current)</option>
                    <?php endif;
                    foreach (\App\Services\AccountService::ACCOUNT_TYPES as $grp => $types): ?>
                        <optgroup label="<?= $grp ?>">
                            <?php foreach ($types as $t): ?>
                                <option value="<?= $t ?>" <?= $cur_type === $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Decides where this account appears on the balance sheet or profit and loss.</small>
            </div>
            <div class="col-md-6 form-group">
                <label for="tax_rate" class="control-label font-weight-bold">Default Tax Rate</label>
                <select name="tax_rate" id="tax_rate" class="form-control form-control-sm" required>
                    <option value="Tax on Sales (0%)" <?= isset($tax_rate) && $tax_rate == 'Tax on Sales (0%)' ? 'selected' : '' ?>>Tax on Sales (0%)</option>
                    <option value="Tax on Purchases (0%)" <?= isset($tax_rate) && $tax_rate == 'Tax on Purchases (0%)' ? 'selected' : '' ?>>Tax on Purchases (0%)</option>
                    <option value="Tax Exempt (0%)" <?= isset($tax_rate) && $tax_rate == 'Tax Exempt (0%)' ? 'selected' : '' ?>>Tax Exempt (0%)</option>
                    <option value="VAT on Sales (15%)" <?= isset($tax_rate) && $tax_rate == 'VAT on Sales (15%)' ? 'selected' : '' ?>>VAT on Sales (15%)</option>
                    <option value="VAT on Purchases (15%)" <?= isset($tax_rate) && $tax_rate == 'VAT on Purchases (15%)' ? 'selected' : '' ?>>VAT on Purchases (15%)</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="description" class="control-label font-weight-bold">Description / Notes</label>
            <textarea rows="3" name="description" id="description" class="form-control form-control-sm" placeholder="Enter account description..."><?php echo isset($description) ? html_entity_decode($description) : '' ?></textarea>
        </div>

        <div class="form-group mb-0">
            <label for="status" class="control-label font-weight-bold">Status</label>
            <select name="status" id="status" class="form-control form-control-sm" required>
                <option value="1" <?= isset($status) && $status == 1 ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= isset($status) && $status == 0 ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
    </form>
</div>
<script>
    $(function(){
        $('#uni_modal #account-form').submit(function(e){
            e.preventDefault();
            var _this = $(this)
            $('.pop-msg').remove()
            var el = $('<div>')
                el.addClass("pop-msg alert")
                el.hide()
            start_loader();
            $.ajax({
                url:_base_url_+"classes/Master.php?f=save_account",
				data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
				error:err=>{
					console.log(err)
					alert_toast("An error occurred.",'error');
					end_loader();
				},
                success:function(resp){
                    if(resp.status == 'success'){
                        location.reload();
                    }else if(!!resp.msg){
                        el.addClass("alert-danger")
                        el.text(resp.msg)
                        _this.prepend(el)
                    }else{
                        el.addClass("alert-danger")
                        el.text("An error occurred due to unknown reason.")
                        _this.prepend(el)
                    }
                    el.show('slow')
                    $('html,body,.modal').animate({scrollTop:0},'fast')
                    end_loader();
                }
            })
        })
    })
</script>
