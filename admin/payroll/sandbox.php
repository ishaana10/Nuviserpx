<div class="card card-outline card-primary shadow-sm rounded-0">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold"><i class="fas fa-calculator text-primary mr-2"></i> Interactive Fiji PAYE & FNPF Verification Sandbox</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-flat" onclick="reset_sandbox()"><i class="fas fa-undo mr-1"></i> Reset Calculator</button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-info text-sm mb-4">
            <i class="fas fa-flask mr-2"></i> Verification Sandbox for testing Nuvis ERPX Fiji payroll calculations against Income Tax Act 2015 Regulation 6 Cumulative PAYE rules and FNPF 2026–2027 rates.
        </div>

        <div class="row">
            <!-- INPUT CONTROLS -->
            <div class="col-md-5 border-right pr-md-4">
                <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-sliders-h text-secondary mr-2"></i> Payroll Parameters Input</h5>
                <form id="sandbox-form">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="sb_frequency" class="font-weight-bold text-dark text-sm">Pay Frequency</label>
                            <select id="sb_frequency" class="form-control form-control-sm rounded-0 sb-calc">
                                <option value="Monthly">Monthly (12)</option>
                                <option value="Fortnightly" selected>Fortnightly (26)</option>
                                <option value="Weekly">Weekly (52)</option>
                                <option value="Bi-weekly">Bi-weekly / Semi-Monthly (24)</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="sb_period_n" class="font-weight-bold text-dark text-sm">Pay Period Number ($n$)</label>
                            <input type="number" min="1" max="52" id="sb_period_n" class="form-control form-control-sm rounded-0 sb-calc" value="1">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="sb_resident" class="font-weight-bold text-dark text-sm">Residency Status</label>
                            <select id="sb_resident" class="form-control form-control-sm rounded-0 sb-calc">
                                <option value="1" selected>Resident (Code P - $30k Free Threshold)</option>
                                <option value="0">Non-Resident (Code S - 20% Flat)</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="sb_basic" class="font-weight-bold text-dark text-sm">Period Base Salary ($)</label>
                            <input type="number" step="0.01" id="sb_basic" class="form-control form-control-sm text-right rounded-0 sb-calc" value="1500.00">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="sb_ot15" class="font-weight-bold text-dark text-sm">Overtime 1.5x Hours</label>
                            <input type="number" step="0.5" id="sb_ot15" class="form-control form-control-sm text-right rounded-0 sb-calc" value="4.0">
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="sb_ot20" class="font-weight-bold text-dark text-sm">Overtime 2.0x Hours</label>
                            <input type="number" step="0.5" id="sb_ot20" class="form-control form-control-sm text-right rounded-0 sb-calc" value="2.0">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="sb_allowances" class="font-weight-bold text-dark text-sm">Taxable Allowances ($)</label>
                            <input type="number" step="0.01" id="sb_allowances" class="form-control form-control-sm text-right rounded-0 sb-calc" value="150.00">
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="sb_lump_sum" class="font-weight-bold text-dark text-sm">Lump Sum / Bonus ($)</label>
                            <input type="number" step="0.01" id="sb_lump_sum" class="form-control form-control-sm text-right rounded-0 sb-calc" value="0.00">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="sb_g_ytd" class="font-weight-bold text-dark text-sm">YTD Prior Taxable Gross ($)</label>
                            <input type="number" step="0.01" id="sb_g_ytd" class="form-control form-control-sm text-right rounded-0 sb-calc" value="0.00">
                        </div>
                        <div class="col-md-6 form-group mb-0">
                            <label for="sb_t_ytd" class="font-weight-bold text-dark text-sm">YTD Prior PAYE Tax ($)</label>
                            <input type="number" step="0.01" id="sb_t_ytd" class="form-control form-control-sm text-right rounded-0 sb-calc" value="0.00">
                        </div>
                    </div>
                </form>
            </div>

            <!-- OUTPUT RESULTS -->
            <div class="col-md-7 pl-md-4 pt-3 pt-md-0">
                <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-poll text-secondary mr-2"></i> Calculated Statutory Breakdown</h5>

                <div class="row mb-3 text-center">
                    <div class="col-md-4 mb-2">
                        <div class="card bg-light border p-2">
                            <small class="text-muted d-block font-weight-bold">Gross Period Earnings</small>
                            <span class="h5 font-weight-bold text-dark m-0" id="sb_res_gross">$0.00</span>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="card bg-light border p-2">
                            <small class="text-muted d-block font-weight-bold">Projected Annual $Y_{\text{est}}$</small>
                            <span class="h5 font-weight-bold text-primary m-0" id="sb_res_yest">$0.00</span>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="card bg-light border p-2">
                            <small class="text-muted d-block font-weight-bold">Net Period Pay</small>
                            <span class="h5 font-weight-bold text-success m-0" id="sb_res_net">$0.00</span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr>
                                <th class="bg-light">Base Salary Earnings</th>
                                <td class="text-right font-weight-bold" id="sb_res_basic">$0.00</td>
                            </tr>
                            <tr>
                                <th class="bg-light">Overtime Earnings ($1.5\times$ & $2.0\times$)</th>
                                <td class="text-right font-weight-bold text-primary" id="sb_res_ot">$0.00</td>
                            </tr>
                            <tr>
                                <th class="bg-light">Employee Mandatory FNPF (8%)</th>
                                <td class="text-right font-weight-bold text-info" id="sb_res_emp_fnpf">$0.00</td>
                            </tr>
                            <tr>
                                <th class="bg-light">Employer Mandatory FNPF (8% Relief Rate)</th>
                                <td class="text-right font-weight-bold text-info" id="sb_res_empr_fnpf">$0.00</td>
                            </tr>
                            <tr>
                                <th class="bg-light">FRCS Regulation 6 PAYE Tax</th>
                                <td class="text-right font-weight-bold text-danger" id="sb_res_paye">$0.00</td>
                            </tr>
                            <tr>
                                <th class="bg-light">Employer FNU Levy (1%)</th>
                                <td class="text-right font-weight-bold text-secondary" id="sb_res_fnu">$0.00</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function run_sandbox_calc(){
    var freq = $('#sb_frequency').val();
    var period_n = parseInt($('#sb_period_n').val()) || 1;
    var resident = parseInt($('#sb_resident').val());
    var basic = parseFloat($('#sb_basic').val()) || 0;
    var ot15 = parseFloat($('#sb_ot15').val()) || 0;
    var ot20 = parseFloat($('#sb_ot20').val()) || 0;
    var allowances = parseFloat($('#sb_allowances').val()) || 0;
    var lump = parseFloat($('#sb_lump_sum').val()) || 0;
    var g_ytd = parseFloat($('#sb_g_ytd').val()) || 0;
    var t_ytd = parseFloat($('#sb_t_ytd').val()) || 0;

    $.ajax({
        url: _base_url_ + "classes/Master.php?f=calculate_payroll_ajax",
        method: "POST",
        data: {
            basic_salary: basic,
            overtime_1_5_hours: ot15,
            overtime_2_0_hours: ot20,
            allowances: allowances,
            lump_sum_payments: lump,
            g_ytd_prior: g_ytd,
            t_ytd_prior: t_ytd,
            pay_period_n: period_n,
            pay_frequency: freq,
            is_resident: resident
        },
        dataType: "json",
        success: function(resp){
            if(resp.status == 'success'){
                var d = resp.data;
                $('#sb_res_gross').text('$' + parseFloat(d.gross_salary).toFixed(2));
                $('#sb_res_yest').text('$' + (d.y_est ? parseFloat(d.y_est).toFixed(2) : '0.00'));
                $('#sb_res_net').text('$' + parseFloat(d.net_salary).toFixed(2));

                $('#sb_res_basic').text('$' + parseFloat(d.basic_salary).toFixed(2));
                $('#sb_res_ot').text('$' + parseFloat(d.overtime).toFixed(2));
                $('#sb_res_emp_fnpf').text('$' + parseFloat(d.employee_fnpf).toFixed(2));
                $('#sb_res_empr_fnpf').text('$' + parseFloat(d.fnpf_er_mandatory || d.employer_fnpf).toFixed(2));
                $('#sb_res_paye').text('$' + parseFloat(d.paye_tax).toFixed(2));
                $('#sb_res_fnu').text('$' + parseFloat(d.training_levy || 0).toFixed(2));
            }
        }
    });
}

function reset_sandbox(){
    $('#sb_basic').val('1500.00');
    $('#sb_ot15').val('4.0');
    $('#sb_ot20').val('2.0');
    $('#sb_allowances').val('150.00');
    $('#sb_lump_sum').val('0.00');
    $('#sb_g_ytd').val('0.00');
    $('#sb_t_ytd').val('0.00');
    run_sandbox_calc();
}

$(document).ready(function(){
    $('.sb-calc').on('input change', function(){
        run_sandbox_calc();
    });
    run_sandbox_calc();
});
</script>
