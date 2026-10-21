<?php
$tenant_id = $_settings->active_tenant_id();

$qry = $conn->query("SELECT * FROM `currencies` WHERE tenant_id = '{$tenant_id}' ORDER BY is_default DESC, code ASC");
?>

<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-coins text-primary mr-2"></i> Multi-Currency & Exchange Rates</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary btn-flat" onclick="add_currency_modal()"><i class="fas fa-plus mr-1"></i> Add Currency</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm mb-4">
            <i class="fas fa-info-circle mr-2"></i> Configure multi-currency conversion exchange rates. The base operating currency exchange rate is fixed at 1.000000.
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered" id="currency-table">
                <thead>
                    <tr class="bg-gradient-navy text-white">
                        <th class="text-center" style="width: 5%;">#</th>
                        <th>Currency Code</th>
                        <th>Currency Name</th>
                        <th class="text-center">Symbol</th>
                        <th class="text-right">Exchange Rate</th>
                        <th class="text-center">Type / Base</th>
                        <th class="text-center" style="width: 12%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    if($qry && $qry->num_rows > 0):
                        while($row = $qry->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="text-center align-middle"><?= $i++; ?></td>
                        <td class="align-middle font-weight-bold text-primary"><?= htmlspecialchars($row['code']) ?></td>
                        <td class="align-middle font-weight-bold"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="text-center align-middle h5 m-0 font-weight-bold"><?= htmlspecialchars($row['symbol']) ?></td>
                        <td class="text-right align-middle font-weight-bold font-monospace"><?= number_format($row['exchange_rate'], 6) ?></td>
                        <td class="text-center align-middle">
                            <?php if($row['is_default'] == 1): ?>
                                <span class="badge badge-success px-2 py-1"><i class="fas fa-star mr-1"></i> Base Currency</span>
                            <?php else: ?>
                                <span class="badge badge-secondary px-2 py-1">Foreign</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-xs btn-outline-primary btn-flat mr-1" onclick="edit_currency_modal(<?= htmlspecialchars(json_encode($row)) ?>)"><i class="fas fa-edit mr-1"></i> Edit</button>
                            <?php if($row['is_default'] != 1): ?>
                                <button type="button" class="btn btn-xs btn-outline-danger btn-flat delete_currency" data-id="<?= $row['id'] ?>"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No currencies configured. Base currency is active.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Currency Modal -->
<div class="modal fade" id="currencyModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="currency-form">
                <input type="hidden" name="id" id="curr_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" id="currModalTitle">Add Currency</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="curr_code" class="font-weight-bold text-dark text-sm">Currency Code <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="curr_code" class="form-control form-control-sm text-uppercase" placeholder="e.g. AUD" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="curr_symbol" class="font-weight-bold text-dark text-sm">Symbol <span class="text-danger">*</span></label>
                            <input type="text" name="symbol" id="curr_symbol" class="form-control form-control-sm" placeholder="e.g. A$" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="curr_name" class="font-weight-bold text-dark text-sm">Currency Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="curr_name" class="form-control form-control-sm" placeholder="e.g. Australian Dollar" required>
                    </div>

                    <div class="form-group">
                        <label for="curr_rate" class="font-weight-bold text-dark text-sm">Exchange Rate (1 Base Currency = ? Foreign) <span class="text-danger">*</span></label>
                        <input type="number" step="0.000001" name="exchange_rate" id="curr_rate" class="form-control form-control-sm text-right" placeholder="1.000000" required>
                    </div>

                    <div class="form-group mb-0">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" name="is_default" id="curr_is_default" value="1">
                            <label class="custom-control-label font-weight-bold text-dark" for="curr_is_default">Set as Base Default Currency</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary btn-flat" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary btn-flat font-weight-bold">Save Currency</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function add_currency_modal(){
    $('#curr_id').val('');
    $('#curr_code').val('');
    $('#curr_name').val('');
    $('#curr_symbol').val('');
    $('#curr_rate').val('1.000000');
    $('#curr_is_default').prop('checked', false);
    $('#currModalTitle').text('Add New Currency');
    $('#currencyModal').modal('show');
}

function edit_currency_modal(curr){
    $('#curr_id').val(curr.id);
    $('#curr_code').val(curr.code);
    $('#curr_name').val(curr.name);
    $('#curr_symbol').val(curr.symbol);
    $('#curr_rate').val(curr.exchange_rate);
    $('#curr_is_default').prop('checked', curr.is_default == 1);
    $('#currModalTitle').text('Edit Currency Details');
    $('#currencyModal').modal('show');
}

$(document).ready(function(){
    $('#currency-table').dataTable();

    $('#currency-form').submit(function(e){
        e.preventDefault();
        start_loader();
        $.ajax({
            url: _base_url_ + "classes/Master.php?f=save_currency",
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
                    alert_toast(resp.msg || "Failed to save currency.", "error");
                    end_loader();
                }
            }
        });
    });

    $(document).on('click', '.delete_currency', function(){
        var id = $(this).data('id');
        if(confirm("Are you sure you want to delete this currency?")){
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=delete_currency",
                method: "POST",
                data: {id: id},
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
                        alert_toast(resp.msg || "Failed to delete currency.", "error");
                        end_loader();
                    }
                }
            });
        }
    });
});
</script>
