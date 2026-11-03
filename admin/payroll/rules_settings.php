<?php
require_once(base_app . 'classes/PayrollEngine.php');
$setting = PayrollEngine::decode_setting($_settings->info('payroll_rules'));
$versions = $setting['versions'];
$custom = (bool)$versions;
// Edit the version named in ?from=, else the one in force today (or the preset for a first save).
$edit_from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
$rules = $edit_from ? PayrollEngine::rules_on($setting, $edit_from) : PayrollEngine::load_rules($_settings);
$today = date('Y-m-d');
$in_force = null;
foreach ($versions as $v) { if ($v['effective_from'] <= $today) $in_force = $v['effective_from']; }
?>
<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-sliders-h text-primary mr-2"></i> Payroll Rules Settings</h3>
        <div class="card-tools">
            <a href="./?page=payroll/tax_rules" class="btn btn-sm btn-outline-secondary btn-flat"><i class="fas fa-eye mr-1"></i> View rules</a>
            <?php if ($custom): ?><button type="button" id="reset-rules" class="btn btn-sm btn-outline-danger btn-flat"><i class="fas fa-undo mr-1"></i> Remove all and use the starting preset</button><?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info text-sm">
            <?= $custom ? 'This company uses its own payroll rules.' : 'No custom rules saved: payroll uses the starting preset.' ?>
            Rules are dated: each version applies to pay dates on or after its <b>effective from</b> date, so a rate that changes
            each year is added as a new version and earlier runs keep the rates they were paid under.
            Payslips already processed are not recalculated.
            Percentages are entered as percentages (8 means 8%). Bands are marginal and annual; the last band has no upper limit.
        </div>

        <?php if ($versions): ?>
        <table class="table table-sm table-bordered" style="max-width:640px">
            <thead><tr class="bg-light"><th>Effective from</th><th>Employee / employer pension</th><th style="width:170px"></th></tr></thead>
            <tbody>
            <?php foreach ($versions as $v): $vr = PayrollEngine::normalize_rules($v['rules']); ?>
                <tr<?= $v['effective_from'] === $in_force ? ' class="table-success"' : '' ?>>
                    <td><?= $v['effective_from'] === '0001-01-01' ? 'The beginning' : htmlspecialchars($v['effective_from']) ?><?= $v['effective_from'] === $in_force ? ' <span class="badge badge-success">in force</span>' : '' ?></td>
                    <td><?= rtrim(rtrim(number_format($vr['pension']['employee_rate'] * 100, 2), '0'), '.') ?>% / <?= rtrim(rtrim(number_format($vr['pension']['employer_rate'] * 100, 2), '0'), '.') ?>%</td>
                    <td><a class="btn btn-xs btn-outline-primary" href="./?page=payroll/rules_settings&from=<?= urlencode($v['effective_from']) ?>">Edit</a>
                        <button type="button" class="btn btn-xs btn-outline-danger del-version" data-from="<?= htmlspecialchars($v['effective_from']) ?>">Remove</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-3 form-group"><label class="font-weight-bold">Effective from</label>
                <input type="date" class="form-control" id="r-from" value="<?= htmlspecialchars($edit_from && $edit_from !== '0001-01-01' ? $edit_from : $today) ?>"></div>
        </div>

        <div class="row">
            <div class="col-md-3 form-group"><label class="font-weight-bold">Employee pension (%)</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="r-emp"></div>
            <div class="col-md-3 form-group"><label class="font-weight-bold">Employer pension (%)</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="r-empr"></div>
            <div class="col-md-3 form-group"><label class="font-weight-bold">Secondary income rate (%)</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="r-sec"></div>
            <div class="col-md-3 form-group"><label class="font-weight-bold">Redundancy: exempt up to / rate (%)</label>
                <div class="input-group"><input type="number" step="0.01" min="0" class="form-control" id="r-red-cap"><input type="number" step="0.01" min="0" max="100" class="form-control" id="r-red-rate"></div></div>
        </div>

        <?php foreach (['resident' => 'Resident income tax bands', 'non_resident' => 'Non-resident income tax bands'] as $k => $title): ?>
        <h5 class="font-weight-bold border-bottom pb-2 mt-3"><?= $title ?><?php echo $_settings->currency_tag() ?></h5>
        <table class="table table-sm table-bordered" style="max-width:560px" id="bands-<?= $k ?>">
            <thead><tr class="bg-light"><th>Up to (annual income)</th><th>Rate (%)</th><th style="width:50px"></th></tr></thead>
            <tbody></tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary btn-flat add-band" data-t="<?= $k ?>"><i class="fas fa-plus"></i> Add band</button>
        <?php endforeach; ?>

        <h5 class="font-weight-bold border-bottom pb-2 mt-4">Overtime</h5>
        <div class="row">
            <div class="col-md-3 form-group"><label class="font-weight-bold">Standard hours per week</label><input type="number" step="0.01" min="1" max="168" class="form-control" id="r-wh"></div>
            <div class="col-md-3 form-group"><label class="font-weight-bold">Weeks per year</label><input type="number" step="0.01" min="1" max="53" class="form-control" id="r-wk"></div>
        </div>
        <table class="table table-sm table-bordered" style="max-width:560px" id="ot-tiers">
            <thead><tr class="bg-light"><th>Tier name</th><th>Multiplier (x hourly rate)</th></tr></thead>
            <tbody></tbody>
        </table>
        <small class="text-muted d-block mb-2">Hourly rate = basic salary &times; pay periods per year &divide; (weeks per year &times; standard weekly hours).</small>

        <h5 class="font-weight-bold border-bottom pb-2 mt-4">Employer levies (on gross pay)</h5>
        <table class="table table-sm table-bordered" style="max-width:760px" id="levies">
            <thead><tr class="bg-light"><th>Name</th><th>Rate (%)</th><th>Payslip field</th><th style="width:50px"></th></tr></thead>
            <tbody></tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary btn-flat" id="add-levy"><i class="fas fa-plus"></i> Add levy</button>
    </div>
    <div class="card-footer text-right">
        <button type="button" id="save-rules" class="btn btn-primary btn-flat font-weight-bold"><i class="fas fa-save mr-1"></i> Save payroll rules</button>
    </div>
</div>

<script>
(function(){
    var rules = <?= json_encode($rules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var pct = function(v){ return Math.round(v * 10000) / 100; };
    var frac = function(v){ return Math.round(parseFloat(v) * 100) / 10000; };

    function bandRow(t, b, last){
        var $tr = $('<tr>');
        var $up = $('<input type="number" step="0.01" min="0" class="form-control form-control-sm up">');
        if (last) { $up.prop('disabled', true).attr('placeholder', 'No upper limit'); } else { $up.val(b.up_to); }
        $tr.append($('<td>').append($up));
        $tr.append($('<td>').append($('<input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm rate">').val(pct(b.rate))));
        $tr.append($('<td>').append(last ? '' : $('<button type="button" class="btn btn-xs btn-outline-danger rm"><i class="fas fa-times"></i></button>')));
        return $tr;
    }
    function renderBands(t, bands){
        var $b = $('#bands-' + t + ' tbody').empty();
        bands.forEach(function(b, i){ $b.append(bandRow(t, b, i === bands.length - 1)); });
    }
    function levyRow(l){
        var $tr = $('<tr>');
        $tr.append($('<td>').append($('<input type="text" maxlength="60" class="form-control form-control-sm lbl">').val(l.label)));
        $tr.append($('<td>').append($('<input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm rate">').val(pct(l.rate))));
        var $s = $('<select class="form-control form-control-sm key"><option value="">Not shown separately</option><option value="workcare_levy">Employer levy 1</option><option value="training_levy">Employer levy 2</option></select>').val(l.key || '');
        $tr.append($('<td>').append($s));
        $tr.append($('<td>').append('<button type="button" class="btn btn-xs btn-outline-danger rm"><i class="fas fa-times"></i></button>'));
        return $tr;
    }

    $('#r-emp').val(pct(rules.pension.employee_rate));
    $('#r-empr').val(pct(rules.pension.employer_rate));
    $('#r-sec').val(pct(rules.secondary_income_rate));
    $('#r-red-cap').val(rules.redundancy.exempt_up_to);
    $('#r-red-rate').val(pct(rules.redundancy.rate));
    renderBands('resident', rules.resident_bands);
    renderBands('non_resident', rules.non_resident_bands);
    $('#r-wh').val(rules.overtime.weekly_hours);
    $('#r-wk').val(rules.overtime.weeks_per_year);
    rules.overtime.tiers.forEach(function(t){
        $('#ot-tiers tbody').append($('<tr>')
            .append($('<td>').append($('<input type="text" maxlength="40" class="form-control form-control-sm tl">').val(t.label)))
            .append($('<td>').append($('<input type="number" step="0.01" min="1" max="10" class="form-control form-control-sm tm">').val(t.multiplier))));
    });
    rules.employer_levies.forEach(function(l){ $('#levies tbody').append(levyRow(l)); });

    $(document).on('click', '.rm', function(){ $(this).closest('tr').remove(); });
    $('.add-band').on('click', function(){
        var t = $(this).data('t'), $b = $('#bands-' + t + ' tbody');
        var $last = $b.find('tr:last');
        // the open-ended band stays last: insert a new bounded band before it
        $last.before(bandRow(t, {up_to: '', rate: 0}, false));
    });
    $('#add-levy').on('click', function(){ $('#levies tbody').append(levyRow({label: '', rate: 0, key: ''})); });

    function readBands(t){
        var out = [], $rows = $('#bands-' + t + ' tbody tr');
        $rows.each(function(i){
            var up = $(this).find('.up').val();
            out.push({up_to: i === $rows.length - 1 ? null : (up === '' ? NaN : parseFloat(up)), rate: frac($(this).find('.rate').val())});
        });
        return out;
    }
    function collect(){
        return {
            pension: {employee_rate: frac($('#r-emp').val()), employer_rate: frac($('#r-empr').val())},
            secondary_income_rate: frac($('#r-sec').val()),
            redundancy: {exempt_up_to: parseFloat($('#r-red-cap').val()), rate: frac($('#r-red-rate').val())},
            resident_bands: readBands('resident'),
            non_resident_bands: readBands('non_resident'),
            overtime: {
                weekly_hours: parseFloat($('#r-wh').val()), weeks_per_year: parseFloat($('#r-wk').val()),
                tiers: $('#ot-tiers tbody tr').map(function(){ return {label: $(this).find('.tl').val(), multiplier: parseFloat($(this).find('.tm').val())}; }).get()
            },
            employer_levies: $('#levies tbody tr').map(function(){
                return {label: $(this).find('.lbl').val(), rate: frac($(this).find('.rate').val()), key: $(this).find('.key').val()};
            }).get()
        };
    }
    function post(f, data){
        start_loader();
        $.ajax({url: _base_url_ + 'classes/Master.php?f=' + f, method: 'POST', data: data, dataType: 'json',
            error: function(){ end_loader(); alert_toast('An error occurred', 'error'); },
            success: function(r){
                end_loader();
                if (r && r.status === 'success') { alert_toast(r.msg, 'success'); setTimeout(function(){ location.reload(); }, 900); }
                else { alert_toast((r && r.msg) || 'Could not save', 'error'); }
            }});
    }
    $('#save-rules').on('click', function(){
        // NaN is not valid JSON: send it as null so the server reports the bad band
        post('save_payroll_rules', {effective_from: $('#r-from').val(), rules_json: JSON.stringify(collect(), function(k, v){ return (typeof v === 'number' && isNaN(v)) ? null : v; })});
    });
    $('.del-version').on('click', function(){
        var f = $(this).data('from');
        if (confirm('Remove the rules effective ' + f + '? Payroll after that date will use the previous version.')) post('delete_payroll_rules_version', {effective_from: f});
    });
    $('#reset-rules').on('click', function(){
        if (confirm('Reset payroll rules to the starting preset?')) post('reset_payroll_rules', {});
    });
})();
</script>
