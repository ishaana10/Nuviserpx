<?php
require_once(__DIR__ . '/../../config.php');
if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $id = intval($_GET['id']);
    $__ps = $_settings->employee_scope('payroll');
    $__own = $__ps === null ? '' : " AND p.employee_id = '".(int)$__ps."' AND p.status = 'posted'";
    $qry = $conn->query("SELECT p.*, e.employee_code, e.firstname, e.lastname, e.department, e.designation, e.phone, e.tin as emp_tin, e.fnpf_no as emp_fnpf, e.bank_code as emp_bank_code, e.bank_account as emp_bank_acc from `payroll_list` p inner join `employee_list` e on p.employee_id = e.id where p.id = '{$id}'{$__own} ");
    if($qry && $qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
        $pay_components = (new \App\Services\PayrollComponentService($conn, $_settings))->forPayroll($id);

        // Year-to-date balances for the FRCS tax year (calendar year), up to
        // and including this pay, excluding rejected/voided runs.
        $nv_ytd = ['gross' => 0.0, 'paye' => 0.0, 'srt' => 0.0, 'emp_fnpf' => 0.0, 'er_fnpf' => 0.0, 'net' => 0.0, 'deductions' => 0.0];
        $nv_t = (int)$_settings->active_tenant_id();
        $nv_eid = (int)$employee_id;
        $nv_year_start = date('Y-01-01', strtotime($pay_date));
        $yq = $conn->prepare("SELECT COALESCE(SUM(gross_salary),0) g, COALESCE(SUM(paye_tax),0) p, COALESCE(SUM(srt_tax),0) s,
                COALESCE(SUM(employee_fnpf),0) ef, COALESCE(SUM(employer_fnpf),0) rf, COALESCE(SUM(net_salary),0) n,
                COALESCE(SUM(employee_fnpf + paye_tax + srt_tax + deductions),0) d
            FROM payroll_list WHERE tenant_id = ? AND employee_id = ? AND status NOT IN ('rejected','voided')
              AND pay_date BETWEEN ? AND ?");
        if ($yq) {
            $yq->bind_param('iiss', $nv_t, $nv_eid, $nv_year_start, $pay_date);
            $yq->execute();
            $yr = $yq->get_result();
            if ($yr && ($row = $yr->fetch_assoc())) {
                $nv_ytd = ['gross' => (float)$row['g'], 'paye' => (float)$row['p'], 'srt' => (float)$row['s'],
                           'emp_fnpf' => (float)$row['ef'], 'er_fnpf' => (float)$row['rf'], 'net' => (float)$row['n'], 'deductions' => (float)$row['d']];
            }
        }

        // Leave balances as at the pay date (annual leave shown on the slip).
        $nv_leave = [];
        try {
            $nv_leave = (new \App\Services\LeaveBalanceService($conn, $_settings))->forEmployee($nv_eid, $pay_date);
        } catch (\Throwable $e) { $nv_leave = []; }

        // Net pay in words, as Fiji payslips customarily show it.
        if (!function_exists('nv_amount_in_words')) {
            function nv_amount_in_words(float $amount): string {
                $dollars = (int)floor($amount);
                $cents = (int)round(($amount - $dollars) * 100);
                if (class_exists('NumberFormatter')) {
                    $f = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
                    $words = ucwords((string)$f->format($dollars));
                } else {
                    $words = number_format($dollars);
                }
                return $words . ' Dollar' . ($dollars == 1 ? '' : 's') . ' and ' . str_pad((string)$cents, 2, '0', STR_PAD_LEFT) . ' Cents';
            }
        }
        $nv_brand = $_settings->tenant_branding();
    }
}
?>
<style>
    @media print {
        body * {
            visibility: hidden;
        }
        #print-area, #print-area * {
            visibility: visible;
        }
        #print-area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
    }
</style>
<div class="container-fluid" id="print-area">
    <div class="card p-4 shadow-none border">
        <!-- Header -->
        <div class="row border-bottom pb-3 mb-3">
            <div class="col-8 d-flex align-items-center">
                <?php if(!empty($nv_brand['logo'])): ?>
                    <img src="<?php echo validate_image($nv_brand['logo']) ?>" alt="Logo" class="mr-3" style="max-height:65px;width:auto;object-fit:contain;">
                <?php endif; ?>
                <div>
                    <h3 class="font-weight-bold text-navy mb-0"><?php echo htmlspecialchars($nv_brand['name'] ?: $_settings->info('name')) ?></h3>
                    <p class="text-muted mb-1 small"><?php echo htmlspecialchars($nv_brand['address']) ?></p>
                    <p class="text-muted mb-0 small"><b>Contact:</b> <?php echo htmlspecialchars($nv_brand['phone']) ?> | <b>Email:</b> <?php echo htmlspecialchars($nv_brand['email']) ?></p>
                </div>
            </div>
            <div class="col-4 text-right">
                <h4 class="text-primary font-weight-bold mb-1">SALARY SLIP</h4>
                <span class="badge badge-primary px-3 py-1 font-weight-normal"><?php echo isset($salary_month) ? $salary_month : '' ?></span>
                <p class="small text-muted mt-2 mb-0"><b>Run Ref:</b> <?php echo !empty($payroll_run_ref) ? $payroll_run_ref : 'N/A' ?></p>
            </div>
        </div>

        <!-- Employee & Pay Period Details -->
        <div class="row mb-3 bg-light p-3 rounded">
            <div class="col-md-6">
                <p class="mb-1"><b>Employee Code:</b> <?php echo isset($employee_code) ? $employee_code : '' ?></p>
                <p class="mb-1"><b>Employee Name:</b> <?php echo isset($firstname) ? $firstname . ' ' . $lastname : '' ?></p>
                <p class="mb-1"><b>Department / Position:</b> <?php echo isset($department) ? $department : '' ?> / <?php echo isset($designation) ? $designation : '' ?></p>
                <p class="mb-1"><b>Bank Details:</b> <?php echo !empty($bank_code) ? strtoupper($bank_code) : strtoupper($emp_bank_code ?? 'BSP') ?> - <?php echo !empty($bank_account) ? $bank_account : ($emp_bank_acc ?? 'N/A') ?></p>
            </div>
            <div class="col-md-6">
                <p class="mb-1"><b>Tax Identification No (TIN):</b> <?php echo !empty($tin) ? $tin : ($emp_tin ?? 'N/A') ?></p>
                <p class="mb-1"><b>FNPF Number:</b> <?php echo !empty($fnpf_no) ? $fnpf_no : ($emp_fnpf ?? 'N/A') ?></p>
                <p class="mb-1"><b>Tax Code & Status:</b> <?php echo !empty($tax_code) ? $tax_code : 'P' ?> (<?php echo (isset($is_resident) && $is_resident == 0) ? 'Non-Resident' : 'Resident' ?>)</p>
                <p class="mb-1"><b>Pay Period:</b> <?php echo (!empty($period_start) && !empty($period_end)) ? date("d M Y", strtotime($period_start)) . ' &ndash; ' . date("d M Y", strtotime($period_end)) : ($salary_month ?? '') ?> (<?php echo ucfirst($pay_frequency ?? 'monthly') ?>)</p>
                <p class="mb-1"><b>Pay Date:</b> <?php echo !empty($pay_date) ? date("M d, Y", strtotime($pay_date)) : date("M d, Y") ?></p>
            </div>
        </div>

        <!-- Earnings and Deductions Tables -->
        <div class="row">
            <!-- Earnings -->
            <div class="col-md-6">
                <div class="card card-outline card-success mb-3">
                    <div class="card-header py-1 bg-success text-white">
                        <h6 class="card-title m-0 font-weight-bold">Earnings<?php echo $_settings->currency_tag() ?></h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped m-0">
                            <tbody>
                                <tr>
                                    <td>Basic Salary</td>
                                    <td class="text-right font-weight-bold"><?php echo number_format($basic_salary, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Overtime</td>
                                    <td class="text-right"><?php echo number_format($overtime, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Allowances</td>
                                    <td class="text-right"><?php echo number_format($allowances, 2) ?></td>
                                </tr>
                                <?php if (!empty($retro_pay) && (float)$retro_pay != 0): ?>
                                <tr>
                                    <td>Retroactive Pay</td>
                                    <td class="text-right"><?php echo number_format($retro_pay, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Other Earnings</td>
                                    <td class="text-right"><?php echo number_format($other_earnings, 2) ?></td>
                                </tr>
                                <?php foreach (($pay_components ?? []) as $pc): if ($pc['type'] !== 'earning') continue; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pc['name']) ?><?php echo empty($pc['is_taxable']) ? ' <span class="badge badge-light border">non-taxable</span>' : '' ?>
                                        <?php if(!empty($pc['description'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($pc['description']) ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-right"><?php echo number_format($pc['amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!empty($leave_payout) && (float)$leave_payout > 0): ?>
                                <tr>
                                    <td>Leave payout</td>
                                    <td class="text-right"><?php echo number_format($leave_payout, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($redundancy_pay) && (float)$redundancy_pay > 0): ?>
                                <tr>
                                    <td>Redundancy / severance</td>
                                    <td class="text-right"><?php echo number_format($redundancy_pay, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($unpaid_deduction) && (float)$unpaid_deduction > 0): ?>
                                <tr>
                                    <td>Unpaid days (<?php echo rtrim(rtrim(number_format((float)$unpaid_days, 2), '0'), '.') ?> day<?php echo (float)$unpaid_days == 1.0 ? '' : 's' ?>)</td>
                                    <td class="text-right text-danger">-<?php echo number_format($unpaid_deduction, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <th class="font-weight-bold">Gross Earnings</th>
                                    <th class="text-right font-weight-bold text-success"><?php echo number_format($gross_salary > 0 ? $gross_salary : ($basic_salary + $overtime + $allowances + $other_earnings), 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Deductions -->
            <div class="col-md-6">
                <div class="card card-outline card-danger mb-3">
                    <div class="card-header py-1 bg-danger text-white">
                        <h6 class="card-title m-0 font-weight-bold">Deductions & Taxes<?php echo $_settings->currency_tag() ?></h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped m-0">
                            <tbody>
                                <tr>
                                    <td>FNPF Employee Contribution</td>
                                    <td class="text-right text-info"><?php echo number_format($employee_fnpf, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>P.A.Y.E. (Tax Code <?php echo htmlspecialchars(!empty($tax_code) ? $tax_code : 'P') ?>)</td>
                                    <td class="text-right text-warning"><?php echo number_format($paye_tax, 2) ?></td>
                                </tr>
                                <?php if ((float)$srt_tax != 0): ?>
                                <tr>
                                    <td>Social Responsibility Tax (SRT)</td>
                                    <td class="text-right text-warning"><?php echo number_format($srt_tax, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php $nv_other_ded = max(0, (float)$deductions - (float)($component_deductions ?? 0)); if ($nv_other_ded > 0): ?>
                                <tr>
                                    <td>Other Deductions</td>
                                    <td class="text-right text-danger"><?php echo number_format($nv_other_ded, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach (($pay_components ?? []) as $pc): if ($pc['type'] !== 'deduction') continue; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pc['name']) ?>
                                        <?php if(!empty($pc['description'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($pc['description']) ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-right text-danger"><?php echo number_format($pc['amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <th class="font-weight-bold">Total Deductions</th>
                                    <th class="text-right font-weight-bold text-danger"><?php echo number_format($employee_fnpf + $paye_tax + $srt_tax + $deductions, 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Net Salary Summary Box -->
        <div class="card bg-navy text-white p-3 mb-3">
            <div class="row align-items-center">
                <div class="col-8">
                    <h5 class="m-0 font-weight-bold text-uppercase"><i class="fas fa-coins text-warning"></i> Net Salary Payable</h5>
                    <small class="text-light">Direct credit to employee account<?php echo $_settings->currency_tag() ?></small>
                </div>
                <div class="col-4 text-right">
                    <h3 class="m-0 font-weight-bold text-warning">$<?php echo number_format($net_salary, 2) ?></h3>
                </div>
            </div>
            <div class="border-top border-secondary mt-2 pt-2">
                <small class="text-light font-italic"><?php echo nv_amount_in_words((float)$net_salary) ?></small>
            </div>
        </div>

        <!-- Year-to-Date, Leave & Superannuation -->
        <div class="row mb-3">
            <div class="col-md-5">
                <div class="card card-outline mb-0 h-100">
                    <div class="card-header py-1"><h6 class="card-title m-0 font-weight-bold"><i class="fas fa-calendar-alt mr-1 text-primary"></i>Year-to-Date Balances (<?php echo date('Y', strtotime($pay_date)) ?>)</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm m-0">
                            <tbody>
                                <tr><td>Gross Earnings</td><td class="text-right"><?php echo number_format($nv_ytd['gross'], 2) ?></td></tr>
                                <tr><td>P.A.Y.E.</td><td class="text-right"><?php echo number_format($nv_ytd['paye'], 2) ?></td></tr>
                                <?php if ($nv_ytd['srt'] > 0): ?><tr><td>Social Responsibility Tax</td><td class="text-right"><?php echo number_format($nv_ytd['srt'], 2) ?></td></tr><?php endif; ?>
                                <tr><td>Total Deductions</td><td class="text-right"><?php echo number_format($nv_ytd['deductions'], 2) ?></td></tr>
                                <tr><td class="font-weight-bold">Net Pay</td><td class="text-right font-weight-bold"><?php echo number_format($nv_ytd['net'], 2) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-outline mb-0 h-100">
                    <div class="card-header py-1"><h6 class="card-title m-0 font-weight-bold"><i class="fas fa-piggy-bank mr-1 text-success"></i>Superannuation (FNPF, YTD)</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm m-0">
                            <tbody>
                                <tr><td>Employee contribution</td><td class="text-right"><?php echo number_format($nv_ytd['emp_fnpf'], 2) ?></td></tr>
                                <tr><td>Employer contribution</td><td class="text-right"><?php echo number_format($nv_ytd['er_fnpf'], 2) ?></td></tr>
                                <tr><td class="font-weight-bold">Total</td><td class="text-right font-weight-bold"><?php echo number_format($nv_ytd['emp_fnpf'] + $nv_ytd['er_fnpf'], 2) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-outline mb-0 h-100">
                    <div class="card-header py-1"><h6 class="card-title m-0 font-weight-bold"><i class="fas fa-umbrella-beach mr-1 text-info"></i>Leave Balances</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm m-0">
                            <tbody>
                                <?php $nv_shown = 0; foreach ($nv_leave as $nv_lt): if (empty($nv_lt['policy']['tracked'])) continue; $nv_shown++; ?>
                                <tr><td><?php echo htmlspecialchars($nv_lt['label'] ?? '') ?></td><td class="text-right"><?php echo number_format((float)($nv_lt['balance'] ?? 0), 2) ?> d</td></tr>
                                <?php endforeach; if (!$nv_shown): ?>
                                <tr><td class="text-muted text-center" colspan="2">Not tracked</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employer Contributions Summary -->
        <div class="p-3 bg-light border rounded mb-4">
            <h6 class="font-weight-bold text-secondary mb-2"><i class="fas fa-building"></i> Employer Statutory Contributions Summary</h6>
            <div class="row small">
                <div class="col-md-3">
                    <span><b>FNPF Employer Contribution:</b> $<?php echo number_format($employer_fnpf, 2) ?></span>
                </div>
                <div class="col-md-3">
                    <span><b>Workcare Levy:</b> $<?php echo number_format($workcare_levy, 2) ?></span>
                </div>
                <div class="col-md-3">
                    <span><b>Training Levy (FNU):</b> $<?php echo number_format($training_levy ?? 0, 2) ?></span>
                </div>
                <div class="col-md-3 text-right">
                    <span><b>Total Employer Cost:</b> $<?php echo number_format($employer_cost > 0 ? $employer_cost : ($gross_salary + $employer_fnpf + $workcare_levy + ($training_levy ?? 0)), 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="row mt-4 pt-4">
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Employee Signature</p>
            </div>
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Authorized Signature & Stamp</p>
            </div>
        </div>
    </div>
</div>

<div class="text-right mt-3">
    <button class="btn btn-flat btn-primary" type="button" id="print-payslip"><i class="fa fa-print"></i> Print Payslip</button>
    <button class="btn btn-flat btn-secondary" type="button" data-dismiss="modal">Close</button>
</div>

<script>
    $(document).ready(function(){
        $('#print-payslip').click(function(){
            var head = $('head').clone();
            var p = $('#print-area').clone();
            var el = $('<div>');
            el.append(head);
            el.append(p);
            var nw = window.open('','_blank','width=800,height=600');
            nw.document.write(el.html());
            nw.document.close();
            setTimeout(() => {
                nw.print();
                setTimeout(() => {
                    nw.close();
                }, 500);
            }, 500);
        })
    })
</script>