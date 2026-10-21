<?php 
require_once(__DIR__ . '/../../config.php');
$tenant_id = $_settings->active_tenant_id();

$account_arr = [];
$group_arr = [];

// Fetch active accounts for line selection
$accounts = $conn->query("SELECT * FROM `account_list` where tenant_id = '{$tenant_id}' and delete_flag = 0 and `status` = 1 order by `name` asc ");
while($row = $accounts->fetch_assoc()){
    unset($row['description']);
    $account_arr[$row['id']] = $row;
}

// Fetch account groups
$groups = $conn->query("SELECT * FROM `group_list` where delete_flag = 0 and `status` = 1 order by `name` asc ");
while($row = $groups->fetch_assoc()){
    unset($row['description']);
    $group_arr[$row['id']] = $row;
}

if(isset($_GET['id'])){
    $id = intval($_GET['id']);
    $qry = $conn->query("SELECT * FROM `journal_entries` where id = '{$id}' and tenant_id = '{$tenant_id}'");
    if($qry->num_rows > 0){
        $res = $qry->fetch_array();
        foreach($res as $k => $v){
            if(!is_numeric($k))
            $$k = $v;
        }
    }
}
?>
<?php
function format_num($number){
	$decimals = 0;
	$num_ex = explode('.',$number);
	$decimals = isset($num_ex[1]) ? strlen($num_ex[1]) : 0 ;
	return number_format($number,$decimals);
}
?>

<style>
.journal-container {
    max-width: 1100px;
    margin: 0 auto;
}
.journal-warning-banner {
    background-color: #fff7ed;
    border: 1px solid #ffedd5;
    border-radius: 6px;
    padding: 12px 16px;
    color: #c2410c;
    font-size: 0.88rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.journal-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.form-label-mj {
    font-size: 0.88rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
}
.table-mj th {
    background: #f8fafc;
    color: #334155;
    font-size: 0.85rem;
    font-weight: 700;
    border-bottom: 2px solid #cbd5e1;
}
.table-mj td {
    vertical-align: middle;
    padding: 6px 8px;
}
</style>

<div class="journal-container my-3">
    <!-- Breadcrumb Header -->
    <div class="mb-2">
        <small class="text-muted"><a href="./?page=journals" class="text-secondary">Manual Journals</a> &gt;</small>
        <h3 class="font-weight-bold text-dark m-0"><?= isset($id) ? 'Edit Manual Journal' : 'New Manual Journal' ?></h3>
    </div>

    <!-- Accountant Warning Banner -->
    <div class="journal-warning-banner" id="mj-warning">
        <div class="d-flex align-items-center">
            <i class="fas fa-info-circle fa-lg mr-3 text-warning"></i>
            <span>We recommend that only your accountant or bookkeeper create journals, unless you have experience managing your general ledger.</span>
        </div>
        <button type="button" class="close text-secondary" onclick="$('#mj-warning').fadeOut()">&times;</button>
    </div>

    <form action="" id="journal-form">
        <input type="hidden" name="id" value="<?= isset($id) ? $id :'' ?>">
        <input type="hidden" name="status" id="journal_status" value="<?= isset($status) ? $status : 'posted' ?>">

        <div class="journal-card mb-4">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                <span class="badge badge-light border text-uppercase font-weight-bold px-2 py-1"><?= isset($status) ? ucfirst($status) : 'Draft' ?></span>
            </div>

            <!-- Top Inputs Row -->
            <div class="row">
                <div class="col-md-5 form-group">
                    <label class="form-label-mj">Narration</label>
                    <input type="text" name="description" class="form-control form-control-sm rounded-0" value="<?= isset($description) ? htmlspecialchars($description) : '' ?>" required>
                </div>
                <div class="col-md-3 form-group">
                    <label class="form-label-mj">Date</label>
                    <input type="date" name="journal_date" class="form-control form-control-sm rounded-0" value="<?= isset($journal_date) ? $journal_date : date("Y-m-d") ?>" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label-mj">Auto Reversing Date <small class="text-muted font-weight-normal">(optional)</small></label>
                    <input type="date" name="auto_reversing_date" class="form-control form-control-sm rounded-0" value="<?= isset($auto_reversing_date) ? $auto_reversing_date : '' ?>">
                </div>
            </div>

            <!-- Checkbox Options Row -->
            <div class="row my-2">
                <div class="col-md-12">
                    <div class="custom-control custom-checkbox mb-1">
                        <input type="checkbox" class="custom-control-input" id="default_narration" name="default_narration" value="1" <?= (!isset($default_narration) || $default_narration == 1) ? 'checked' : '' ?>>
                        <label class="custom-control-label font-weight-normal text-secondary text-sm" for="default_narration">Default narration to journal line description</label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="show_cash_basis" name="show_cash_basis" value="1" <?= (!isset($show_cash_basis) || $show_cash_basis == 1) ? 'checked' : '' ?>>
                        <label class="custom-control-label font-weight-normal text-secondary text-sm" for="show_cash_basis">
                            Show journal on cash basis reports <i class="fas fa-question-circle text-muted text-xs" title="Determines whether this journal appears on cash-basis financial reports"></i>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Tax Mode Dropdown -->
            <div class="row justify-content-end my-3">
                <div class="col-md-4 d-flex align-items-center justify-content-end">
                    <label class="form-label-mj mr-2 mb-0">Amounts are</label>
                    <select name="tax_mode" class="form-control form-control-sm rounded-0 w-auto">
                        <option value="No Tax" <?= (isset($tax_mode) && $tax_mode == 'No Tax') ? 'selected' : '' ?>>No Tax</option>
                        <option value="Tax Inclusive" <?= (isset($tax_mode) && $tax_mode == 'Tax Inclusive') ? 'selected' : '' ?>>Tax Inclusive</option>
                        <option value="Tax Exclusive" <?= (isset($tax_mode) && $tax_mode == 'Tax Exclusive') ? 'selected' : '' ?>>Tax Exclusive</option>
                    </select>
                </div>
            </div>

            <!-- Grid Items Table -->
            <div class="table-responsive">
                <table id="account_list" class="table table-bordered table-mj">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Description</th>
                            <th style="width: 25%;">Account</th>
                            <th style="width: 20%;">Tax Rate</th>
                            <th style="width: 13%;" class="text-right">Debit FJD</th>
                            <th style="width: 13%;" class="text-right">Credit FJD</th>
                            <th style="width: 4%;" class="text-center"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if(isset($id)):
                        $jitems = $conn->query("SELECT j.*, a.name as account, g.type FROM `journal_items` j inner join account_list a on j.account_id = a.id inner join group_list g on j.group_id = g.id where journal_id = '{$id}' and a.tenant_id = '{$tenant_id}'");
                        while($row = $jitems->fetch_assoc()):
                        ?>
                        <tr>
                            <td>
                                <input type="text" name="line_description[]" class="form-control form-control-sm rounded-0" value="<?= htmlspecialchars($row['description'] ?? '') ?>">
                            </td>
                            <td>
                                <select name="account_id[]" class="form-control form-control-sm rounded-0 select2-line" required>
                                    <option value="" disabled></option>
                                    <?php foreach($account_arr as $ak => $av): ?>
                                        <option value="<?= $ak ?>" <?= $row['account_id'] == $ak ? 'selected' : '' ?>><?= htmlspecialchars($av['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="group_id[]" value="<?= $row['group_id'] ?>">
                            </td>
                            <td>
                                <select name="tax_rate[]" class="form-control form-control-sm rounded-0">
                                    <option value="No Tax (0%)" <?= ($row['tax_rate'] ?? '') == 'No Tax (0%)' ? 'selected' : '' ?>>No Tax (0%)</option>
                                    <option value="VAT on Income (15%)" <?= ($row['tax_rate'] ?? '') == 'VAT on Income (15%)' ? 'selected' : '' ?>>VAT on Income (15%)</option>
                                    <option value="VAT on Expenses (15%)" <?= ($row['tax_rate'] ?? '') == 'VAT on Expenses (15%)' ? 'selected' : '' ?>>VAT on Expenses (15%)</option>
                                    <option value="Tax Exempt (0%)" <?= ($row['tax_rate'] ?? '') == 'Tax Exempt (0%)' ? 'selected' : '' ?>>Tax Exempt (0%)</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="any" name="debit[]" class="form-control form-control-sm rounded-0 text-right debit-input" value="<?= $row['type'] == 1 ? $row['amount'] : '' ?>">
                            </td>
                            <td>
                                <input type="number" step="any" name="credit[]" class="form-control form-control-sm rounded-0 text-right credit-input" value="<?= $row['type'] == 2 ? $row['amount'] : '' ?>">
                            </td>
                            <td class="text-center align-middle">
                                <button class="btn btn-xs btn-outline-danger btn-flat delete-row" type="button"><i class="fa fa-times"></i></button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="p-2">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-flat" id="add_new_line"><i class="fas fa-plus mr-1"></i> Add a new line</button>
                            </td>
                        </tr>
                        <tr class="bg-light">
                            <td colspan="3" class="text-right font-weight-bold">Subtotal</td>
                            <td class="text-right font-weight-bold subtotal-debit">0.00</td>
                            <td class="text-right font-weight-bold subtotal-credit">0.00</td>
                            <td></td>
                        </tr>
                        <tr class="bg-light">
                            <td colspan="3" class="text-right font-weight-bold border-top-2">TOTAL</td>
                            <td class="text-right font-weight-bold border-top-2 total-debit">0.00</td>
                            <td class="text-right font-weight-bold border-top-2 total-credit">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Trial Balance Balance Indicator -->
            <div class="alert text-center py-2 my-2 trial-balance-alert" style="display: none;"></div>

            <!-- Save Action Buttons -->
            <div class="d-flex align-items-center justify-content-end gap-2 mt-4">
                <a href="./?page=journals" class="btn btn-outline-secondary btn-flat mr-2">Cancel</a>
                <button type="button" class="btn btn-outline-primary btn-flat mr-2" id="btn-save-draft"><i class="fas fa-save mr-1"></i> Save as draft</button>
                <button type="button" class="btn btn-success btn-flat" id="btn-post"><i class="fas fa-check mr-1"></i> Post</button>
            </div>
        </div>
    </form>

    <!-- History & Notes Section -->
    <div class="journal-card">
        <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3">
            History & Notes <i class="fas fa-question-circle text-muted text-xs" title="Displays record modifications and comments"></i>
        </h5>
        <div class="d-flex align-items-center gap-2 mb-3">
            <button type="button" class="btn btn-xs btn-outline-secondary btn-flat" onclick="$('#history-log-box').slideToggle();">
                Show History (<?= isset($id) ? '1 entry' : '0 entries' ?>)
            </button>
            <button type="button" class="btn btn-xs btn-outline-primary btn-flat" onclick="alert_toast('Note feature ready.', 'info');">Add Note</button>
        </div>
        <div id="history-log-box" style="display: none;" class="p-3 bg-light rounded border text-sm text-muted">
            <i class="fas fa-history mr-1"></i> <?= isset($id) ? 'Journal voucher recorded on ' . date("d M Y H:i", strtotime($date_created ?? 'now')) : 'No history available yet.' ?>
        </div>
    </div>
</div>

<template id="line-template">
<tr>
    <td>
        <input type="text" name="line_description[]" class="form-control form-control-sm rounded-0">
    </td>
    <td>
        <select name="account_id[]" class="form-control form-control-sm rounded-0 select2-line" required>
            <option value="" disabled selected>Select account</option>
            <?php foreach($account_arr as $ak => $av): ?>
                <option value="<?= $ak ?>"><?= htmlspecialchars($av['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="group_id[]" value="1">
    </td>
    <td>
        <select name="tax_rate[]" class="form-control form-control-sm rounded-0">
            <option value="No Tax (0%)" selected>No Tax (0%)</option>
            <option value="VAT on Income (15%)">VAT on Income (15%)</option>
            <option value="VAT on Expenses (15%)">VAT on Expenses (15%)</option>
            <option value="Tax Exempt (0%)">Tax Exempt (0%)</option>
        </select>
    </td>
    <td>
        <input type="number" step="any" name="debit[]" class="form-control form-control-sm rounded-0 text-right debit-input" placeholder="0.00">
    </td>
    <td>
        <input type="number" step="any" name="credit[]" class="form-control form-control-sm rounded-0 text-right credit-input" placeholder="0.00">
    </td>
    <td class="text-center align-middle">
        <button class="btn btn-xs btn-outline-danger btn-flat delete-row" type="button"><i class="fa fa-times"></i></button>
    </td>
</tr>
</template>

<script>
function initLineSelect2(elem){
    elem.find('.select2-line').select2({
        width: '100%',
        placeholder: 'Select account...'
    });
}

function calculateJournalTotals(){
    var totalDebit = 0;
    var totalCredit = 0;

    $('#account_list tbody tr').each(function(){
        var d = parseFloat($(this).find('.debit-input').val()) || 0;
        var c = parseFloat($(this).find('.credit-input').val()) || 0;
        totalDebit += d;
        totalCredit += c;
    });

    $('.subtotal-debit, .total-debit').text(totalDebit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    $('.subtotal-credit, .total-credit').text(totalCredit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));

    var diff = Math.abs(totalDebit - totalCredit);
    var alertBox = $('.trial-balance-alert');
    if($('#account_list tbody tr').length > 0){
        alertBox.show();
        if(diff < 0.001 && totalDebit > 0){
            alertBox.removeClass('alert-danger').addClass('alert-success').html('<i class="fas fa-check-circle mr-1"></i> Journal balance is equal (Debit = Credit: $' + totalDebit.toFixed(2) + ')');
        } else if(diff >= 0.001) {
            alertBox.removeClass('alert-success').addClass('alert-danger').html('<i class="fas fa-exclamation-triangle mr-1"></i> Trial balance is out of balance by $' + diff.toFixed(2));
        } else {
            alertBox.hide();
        }
    } else {
        alertBox.hide();
    }
}

$(document).ready(function(){
    // Add default lines if empty
    if($('#account_list tbody tr').length === 0){
        for(var i=0; i<4; i++){
            var tpl = $($('#line-template').html()).clone();
            $('#account_list tbody').append(tpl);
            initLineSelect2(tpl);
        }
    } else {
        $('#account_list tbody tr').each(function(){
            initLineSelect2($(this));
        });
    }

    calculateJournalTotals();

    $('#add_new_line').click(function(){
        var tpl = $($('#line-template').html()).clone();
        $('#account_list tbody').append(tpl);
        initLineSelect2(tpl);
    });

    $(document).on('click', '.delete-row', function(){
        $(this).closest('tr').remove();
        calculateJournalTotals();
    });

    $(document).on('input', '.debit-input', function(){
        if($(this).val() !== ''){
            $(this).closest('tr').find('.credit-input').val('');
        }
        calculateJournalTotals();
    });

    $(document).on('input', '.credit-input', function(){
        if($(this).val() !== ''){
            $(this).closest('tr').find('.debit-input').val('');
        }
        calculateJournalTotals();
    });

    $('input[name="description"]').on('input', function(){
        if($('#default_narration').is(':checked')){
            var val = $(this).val();
            $('#account_list tbody tr').each(function(){
                var descInput = $(this).find('input[name="line_description[]"]');
                if(descInput.val() === '' || descInput.data('auto') === true){
                    descInput.val(val);
                    descInput.data('auto', true);
                }
            });
        }
    });

    $('#btn-save-draft').click(function(){
        $('#journal_status').val('draft');
        $('#journal-form').submit();
    });

    $('#btn-post').click(function(){
        $('#journal_status').val('posted');
        $('#journal-form').submit();
    });

    $('#journal-form').submit(function(e){
        e.preventDefault();
        var totalDebit = 0;
        var totalCredit = 0;

        $('#account_list tbody tr').each(function(){
            var d = parseFloat($(this).find('.debit-input').val()) || 0;
            var c = parseFloat($(this).find('.credit-input').val()) || 0;
            totalDebit += d;
            totalCredit += c;
        });

        if(Math.abs(totalDebit - totalCredit) >= 0.001){
            alert_toast("Trial Balance is not equal. Total Debit must equal Total Credit.", 'error');
            return false;
        }

        start_loader();
        $.ajax({
            url: _base_url_+"classes/Master.php?f=save_journal",
            data: new FormData($(this)[0]),
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            type: 'POST',
            dataType: 'json',
            error: err => {
                console.log(err);
                alert_toast("An error occurred.", 'error');
                end_loader();
            },
            success: function(resp){
                if(resp.status == 'success'){
                    location.href = "./?page=journals";
                } else if(!!resp.msg){
                    alert_toast(resp.msg, 'error');
                } else {
                    alert_toast("An error occurred due to unknown reason.", 'error');
                }
                end_loader();
            }
        });
    });
});
</script>
